<?php

namespace SchemaCraft\Visualizer;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use SchemaCraft\Config\ConfigResolver;
use SchemaCraft\Config\ConnectionConfig;
use SchemaCraft\Generator\Api\ApiCodeGenerator;
use SchemaCraft\Generator\FactoryGenerator;
use SchemaCraft\Generator\ModelTestGenerator;
use SchemaCraft\Generator\SchemaFileGenerator;
use SchemaCraft\Migration\DatabaseReader;
use SchemaCraft\Migration\DatabaseTableState;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Scanner\RelationshipDefinition;
use SchemaCraft\Scanner\SchemaScanner;
use SchemaCraft\Scanner\TableDefinition;

class SchemaController
{
    /**
     * Laravel internal tables to exclude from import.
     */
    private const LARAVEL_INTERNAL_TABLES = [
        'migrations',
        'password_reset_tokens',
        'password_resets',
        'personal_access_tokens',
        'failed_jobs',
        'jobs',
        'job_batches',
        'cache',
        'cache_locks',
        'sessions',
        'telescope_entries',
        'telescope_entries_tags',
        'telescope_monitoring',
        'pulse_aggregates',
        'pulse_entries',
        'pulse_values',
    ];

    /**
     * Check if BaseModel is installed.
     */
    public function installStatus(): JsonResponse
    {
        $path = app_path('Models/BaseModel.php');

        return new JsonResponse([
            'installed' => file_exists($path),
            'path' => 'app/Models/BaseModel.php',
        ]);
    }

    /**
     * Install BaseModel from stub.
     */
    public function install(Filesystem $files): JsonResponse
    {
        $path = app_path('Models/BaseModel.php');

        if ($files->exists($path)) {
            return new JsonResponse([
                'success' => true,
                'message' => 'BaseModel already exists.',
                'filePath' => $path,
            ]);
        }

        $stub = $files->get($this->resolveStubPath('base-model.stub'));

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $stub);

        return new JsonResponse([
            'success' => true,
            'message' => 'BaseModel created successfully.',
            'filePath' => $path,
        ]);
    }

    /**
     * Preview schema + model files without writing.
     */
    public function createPreview(Request $request, Filesystem $files): JsonResponse
    {
        $request->validate([
            'names' => ['required', 'string'],
            'primaryKey' => ['sometimes', 'string', 'in:auto,uuid,ulid'],
            'softDeletes' => ['sometimes', 'boolean'],
            'createModel' => ['sometimes', 'boolean'],
        ]);

        $names = array_filter(array_map('trim', explode(',', $request->input('names'))));

        if (empty($names)) {
            return new JsonResponse(['success' => false, 'message' => 'No schema names provided.'], 422);
        }

        $idConfig = $this->resolveIdConfig($request->input('primaryKey', 'auto'));
        $softDeletes = $request->boolean('softDeletes', false);
        $createModel = $request->boolean('createModel', true);

        $generatedFiles = $this->generateSchemaFiles($files, $names, $idConfig, $softDeletes, $createModel);

        return new JsonResponse([
            'success' => true,
            'files' => $generatedFiles,
        ]);
    }

    /**
     * Create schema + model files on disk.
     */
    public function create(Request $request, Filesystem $files): JsonResponse
    {
        $request->validate([
            'names' => ['required', 'string'],
            'primaryKey' => ['sometimes', 'string', 'in:auto,uuid,ulid'],
            'softDeletes' => ['sometimes', 'boolean'],
            'createModel' => ['sometimes', 'boolean'],
        ]);

        $names = array_filter(array_map('trim', explode(',', $request->input('names'))));

        if (empty($names)) {
            return new JsonResponse(['success' => false, 'message' => 'No schema names provided.'], 422);
        }

        $idConfig = $this->resolveIdConfig($request->input('primaryKey', 'auto'));
        $softDeletes = $request->boolean('softDeletes', false);
        $createModel = $request->boolean('createModel', true);

        // Auto-install BaseModel if needed
        if ($createModel && ! $files->exists(app_path('Models/BaseModel.php'))) {
            $stub = $files->get($this->resolveStubPath('base-model.stub'));
            $files->ensureDirectoryExists(app_path('Models'));
            $files->put(app_path('Models/BaseModel.php'), $stub);
        }

        $generatedFiles = $this->generateSchemaFiles($files, $names, $idConfig, $softDeletes, $createModel);

        $results = [];
        foreach ($generatedFiles as $file) {
            $fullPath = base_path($file['path']);
            $files->ensureDirectoryExists(dirname($fullPath));
            $files->put($fullPath, $file['content']);
            $results[] = [
                'path' => $file['path'],
                'created' => true,
                'message' => class_basename($file['path']).' created.',
            ];
        }

        return new JsonResponse([
            'success' => true,
            'files' => $results,
            'message' => count($results).' file(s) created.',
        ]);
    }

    /**
     * List available DB connection configurations.
     */
    public function connections(): JsonResponse
    {
        $names = ConfigResolver::allConnectionNames();
        $connections = [];

        foreach ($names as $name) {
            $config = ConfigResolver::resolveConnection($name);
            $connections[] = [
                'name' => $name,
                'connection' => $config->connection,
                'schemaPrefix' => $config->schemaPrefix,
                'modelPrefix' => $config->modelPrefix,
                'schemaNamespace' => $config->schemaNamespace,
                'modelNamespace' => $config->modelNamespace,
            ];
        }

        return new JsonResponse([
            'connections' => $connections,
            'default' => config('schema-craft.default_connection', 'default'),
        ]);
    }

    /**
     * List all importable database tables.
     */
    public function listTables(Request $request): JsonResponse
    {
        $dbConnectionParam = $request->query('db_connection');

        if ($dbConnectionParam === 'all') {
            return $this->listAllConnectionTables();
        }

        $connectionConfig = ConfigResolver::resolveConnection($dbConnectionParam);
        $dbConnection = $connectionConfig->needsConnectionProperty() ? $connectionConfig->connection : null;
        $reader = new DatabaseReader($dbConnection);
        $allTableNames = $reader->tables();

        // Filter out Laravel internal tables
        $importableNames = array_values(array_filter(
            $allTableNames,
            fn (string $t) => ! in_array($t, self::LARAVEL_INTERNAL_TABLES, true),
        ));

        // Detect pivots in one pass
        $pivotMap = $this->detectPivotTables($reader, $importableNames);

        $schemaDir = $this->namespaceToAppPath($connectionConfig->schemaNamespace);
        $modelDir = $this->namespaceToAppPath($connectionConfig->modelNamespace);

        $tables = [];
        foreach ($importableNames as $tableName) {
            $modelName = $this->tableToModelName($tableName);
            $prefixedSchema = $connectionConfig->prefixedSchemaName($modelName);
            $prefixedModel = $connectionConfig->prefixedModelName($modelName);
            $isPivot = isset($pivotMap[$tableName]);

            $tables[] = [
                'name' => $tableName,
                'modelName' => $modelName,
                'dbConnection' => $connectionConfig->name,
                'isPivot' => $isPivot,
                'pivotTables' => $isPivot ? $pivotMap[$tableName] : null,
                'hasSchema' => file_exists("{$schemaDir}/{$prefixedSchema}.php"),
                'hasModel' => file_exists("{$modelDir}/{$prefixedModel}.php"),
                'hasFactory' => file_exists($connectionConfig->factoryPath($modelName)),
                'hasModelTest' => file_exists($connectionConfig->modelTestPath($modelName)),
                'hasService' => file_exists($connectionConfig->servicePath($modelName)),
            ];
        }

        return new JsonResponse(['tables' => $tables]);
    }

    /**
     * List tables across all configured connections.
     *
     * Groups connections by underlying DB connection to avoid querying the
     * same physical database multiple times. Returns one entry per
     * (table, connection config) pair.
     */
    private function listAllConnectionTables(): JsonResponse
    {
        $allNames = ConfigResolver::allConnectionNames();

        // Group configs by underlying Laravel DB connection
        /** @var array<string, array{dbConn: ?string, configs: array<string, ConnectionConfig>}> $byDb */
        $byDb = [];
        foreach ($allNames as $name) {
            $config = ConfigResolver::resolveConnection($name);
            $dbConn = $config->needsConnectionProperty() ? $config->connection : null;
            $dbKey = $dbConn ?? '__default__';
            $byDb[$dbKey]['dbConn'] = $dbConn;
            $byDb[$dbKey]['configs'][$name] = $config;
        }

        // Read tables + detect pivots once per unique DB connection
        $tablesByDb = [];
        $pivotsByDb = [];
        foreach ($byDb as $dbKey => $group) {
            $reader = new DatabaseReader($group['dbConn']);
            $allNames = $reader->tables();
            $importableNames = array_values(array_filter(
                $allNames,
                fn (string $t) => ! in_array($t, self::LARAVEL_INTERNAL_TABLES, true),
            ));
            $tablesByDb[$dbKey] = $importableNames;
            $pivotsByDb[$dbKey] = $this->detectPivotTables($reader, $importableNames);
        }

        // Build output: one entry per (table, connection config) pair
        $tables = [];
        foreach ($byDb as $dbKey => $group) {
            foreach ($group['configs'] as $configName => $config) {
                $schemaDir = $this->namespaceToAppPath($config->schemaNamespace);
                $modelDir = $this->namespaceToAppPath($config->modelNamespace);

                foreach ($tablesByDb[$dbKey] as $tableName) {
                    $modelName = $this->tableToModelName($tableName);
                    $prefixedSchema = $config->prefixedSchemaName($modelName);
                    $prefixedModel = $config->prefixedModelName($modelName);
                    $isPivot = isset($pivotsByDb[$dbKey][$tableName]);

                    $tables[] = [
                        'name' => $tableName,
                        'modelName' => $modelName,
                        'dbConnection' => $configName,
                        'isPivot' => $isPivot,
                        'pivotTables' => $isPivot ? $pivotsByDb[$dbKey][$tableName] : null,
                        'hasSchema' => file_exists("{$schemaDir}/{$prefixedSchema}.php"),
                        'hasModel' => file_exists("{$modelDir}/{$prefixedModel}.php"),
                        'hasFactory' => file_exists($config->factoryPath($modelName)),
                        'hasModelTest' => file_exists($config->modelTestPath($modelName)),
                        'hasService' => file_exists($config->servicePath($modelName)),
                    ];
                }
            }
        }

        return new JsonResponse(['tables' => $tables]);
    }

    /**
     * Preview import from database without writing.
     */
    public function importPreview(Request $request): JsonResponse
    {
        $request->validate([
            'tables' => ['required', 'array', 'min:1'],
            'tables.*' => ['string'],
            'createModel' => ['sometimes', 'boolean'],
            'createFactory' => ['sometimes', 'boolean'],
            'createModelTest' => ['sometimes', 'boolean'],
            'createService' => ['sometimes', 'boolean'],
            'db_connection' => ['sometimes', 'string'],
        ]);

        $tableNames = $request->input('tables');
        $createModel = $request->boolean('createModel', true);

        return $this->runImport(
            $tableNames,
            $createModel,
            false,
            false,
            $request->input('db_connection'),
            $request->boolean('createFactory'),
            $request->boolean('createModelTest'),
            $request->boolean('createService'),
        );
    }

    /**
     * Import schemas from database and write to disk.
     */
    public function import(Request $request, Filesystem $files): JsonResponse
    {
        $request->validate([
            'tables' => ['required', 'array', 'min:1'],
            'tables.*' => ['string'],
            'createModel' => ['sometimes', 'boolean'],
            'createFactory' => ['sometimes', 'boolean'],
            'createModelTest' => ['sometimes', 'boolean'],
            'createService' => ['sometimes', 'boolean'],
            'force' => ['sometimes', 'boolean'],
            'db_connection' => ['sometimes', 'string'],
        ]);

        $tableNames = $request->input('tables');
        $createModel = $request->boolean('createModel', true);
        $force = $request->boolean('force', false);

        // Auto-install BaseModel if needed
        if ($createModel && ! $files->exists(app_path('Models/BaseModel.php'))) {
            $stub = $files->get($this->resolveStubPath('base-model.stub'));
            $files->ensureDirectoryExists(app_path('Models'));
            $files->put(app_path('Models/BaseModel.php'), $stub);
        }

        return $this->runImport(
            $tableNames,
            $createModel,
            true,
            $force,
            $request->input('db_connection'),
            $request->boolean('createFactory'),
            $request->boolean('createModelTest'),
            $request->boolean('createService'),
        );
    }

    /**
     * Core import logic shared by preview and write.
     */
    private function runImport(
        array $tableNames,
        bool $createModel,
        bool $write,
        bool $force,
        ?string $dbConnectionName = null,
        bool $createFactory = false,
        bool $createModelTest = false,
        bool $createService = false,
    ): JsonResponse {
        $connectionConfig = ConfigResolver::resolveConnection($dbConnectionName);
        $dbConnection = $connectionConfig->needsConnectionProperty() ? $connectionConfig->connection : null;
        $reader = new DatabaseReader($dbConnection);
        $generator = new SchemaFileGenerator;
        $files = new Filesystem;

        $schemaDir = $this->namespaceToAppPath($connectionConfig->schemaNamespace);
        $modelDir = $this->namespaceToAppPath($connectionConfig->modelNamespace);
        $emitConnection = $connectionConfig->needsConnectionProperty() ? $connectionConfig->connection : null;

        // Read all requested tables
        $allTables = [];
        foreach ($tableNames as $tableName) {
            $tableState = $reader->read($tableName);
            if ($tableState !== null) {
                $allTables[$tableName] = $tableState;
            }
        }

        // Detect pivot tables
        $pivotTables = [];
        $regularTables = [];
        $pivotMap = [];
        $pivotExtraColumns = [];

        foreach ($allTables as $tableName => $tableState) {
            $pivot = $generator->detectPivotTable($tableState);
            if ($pivot !== null) {
                $pivotTables[] = [
                    'table' => $tableName,
                    'tableA' => $pivot['tableA'],
                    'tableB' => $pivot['tableB'],
                    'extraColumns' => $pivot['extraColumns'],
                ];

                if (! empty($pivot['extraColumns'])) {
                    $pivotExtraColumns[$tableName] = $pivot['extraColumns'];
                }

                if (isset($allTables[$pivot['tableA']])) {
                    $pivotMap[$pivot['tableA']][$pivot['tableB']] = $tableName;
                }
                if (isset($allTables[$pivot['tableB']])) {
                    $pivotMap[$pivot['tableB']][$pivot['tableA']] = $tableName;
                }

                // Always generate files for pivot tables
                $regularTables[$tableName] = $tableState;
            } else {
                $regularTables[$tableName] = $tableState;
            }
        }

        // Build pivot model name map: pivot_table_name => PivotModelClassName
        $pivotModelNames = [];
        foreach ($pivotTables as $pivotInfo) {
            $pivotModelClass = $connectionConfig->modelPrefix.$this->tableToModelName($pivotInfo['table']);
            $pivotModelNames[$pivotInfo['table']] = $pivotModelClass;
        }

        // Track which tables are pivots for isPivotModel flag
        $pivotTableNames = array_column($pivotTables, 'table');

        // Generate files
        $outputFiles = [];
        $summary = [
            'schemas' => 0, 'models' => 0,
            'factories' => 0, 'tests' => 0, 'services' => 0,
            'skipped' => 0, 'pivots' => count($pivotTables),
        ];

        foreach ($regularTables as $tableName => $tableState) {
            $modelName = $this->tableToModelName($tableName);
            $prefixedModel = $connectionConfig->prefixedModelName($modelName);
            $pivotRelationships = $pivotMap[$tableName] ?? [];
            $isThisAPivot = in_array($tableName, $pivotTableNames, true);

            $result = $generator->generate(
                table: $tableState,
                allTables: $regularTables,
                pivotRelationships: $pivotRelationships,
                schemaNamespace: $connectionConfig->schemaNamespace,
                modelNamespace: $connectionConfig->modelNamespace,
                schemaPrefix: $connectionConfig->schemaPrefix,
                modelPrefix: $connectionConfig->modelPrefix,
                connection: $emitConnection,
                pivotModelNames: $pivotModelNames,
                isPivotModel: $isThisAPivot,
                pivotExtraColumns: $pivotExtraColumns,
            );

            // Schema file — always generated
            $schemaRelPath = str_replace(base_path().'/', '', "{$schemaDir}/{$result->schemaClassName}.php");
            $schemaFullPath = "{$schemaDir}/{$result->schemaClassName}.php";
            $schemaExists = file_exists($schemaFullPath);

            if ($write) {
                if (! $force && $schemaExists) {
                    $outputFiles[] = ['path' => $schemaRelPath, 'skipped' => true, 'message' => 'Already exists.', 'type' => 'schema'];
                    $summary['skipped']++;
                } else {
                    $files->ensureDirectoryExists(dirname($schemaFullPath));
                    $files->put($schemaFullPath, $result->schemaContent);
                    $outputFiles[] = ['path' => $schemaRelPath, 'created' => true, 'message' => "{$result->schemaClassName} created.", 'type' => 'schema'];
                    $summary['schemas']++;
                }
            } else {
                $outputFiles[] = ['path' => $schemaRelPath, 'content' => $result->schemaContent, 'exists' => $schemaExists, 'type' => 'schema'];
            }

            // Model file — only when createModel is true
            if ($createModel) {
                $modelRelPath = str_replace(base_path().'/', '', "{$modelDir}/{$result->modelClassName}.php");
                $modelFullPath = "{$modelDir}/{$result->modelClassName}.php";
                $modelExists = file_exists($modelFullPath);

                if ($write) {
                    if (! $force && $modelExists) {
                        $outputFiles[] = ['path' => $modelRelPath, 'skipped' => true, 'message' => 'Already exists.', 'type' => 'model'];
                        $summary['skipped']++;
                    } else {
                        $files->ensureDirectoryExists(dirname($modelFullPath));
                        $files->put($modelFullPath, $result->modelContent);
                        $outputFiles[] = ['path' => $modelRelPath, 'created' => true, 'message' => "{$result->modelClassName} created.", 'type' => 'model'];
                        $summary['models']++;
                    }
                } else {
                    $outputFiles[] = ['path' => $modelRelPath, 'content' => $result->modelContent, 'exists' => $modelExists, 'type' => 'model'];
                }
            }

            // Factory / Test / Service generation
            if ($createFactory || $createModelTest || $createService) {
                $tableDef = $this->buildTableDefinitionFromDatabase($tableState, $connectionConfig);

                if ($createFactory) {
                    $factoryPath = $connectionConfig->factoryPath($modelName);
                    $factoryRelPath = str_replace(base_path().'/', '', $factoryPath);
                    $factoryExists = file_exists($factoryPath);
                    $factoryContent = (new FactoryGenerator)->generate(
                        $tableDef, $prefixedModel, $connectionConfig->modelNamespace, $connectionConfig->factoryNamespace,
                    );

                    if ($write) {
                        if (! $force && $factoryExists) {
                            $outputFiles[] = ['path' => $factoryRelPath, 'skipped' => true, 'message' => 'Already exists.', 'type' => 'factory'];
                            $summary['skipped']++;
                        } else {
                            $files->ensureDirectoryExists(dirname($factoryPath));
                            $files->put($factoryPath, $factoryContent);
                            $outputFiles[] = ['path' => $factoryRelPath, 'created' => true, 'message' => class_basename($factoryPath).' created.', 'type' => 'factory'];
                            $summary['factories']++;
                        }
                    } else {
                        $outputFiles[] = ['path' => $factoryRelPath, 'content' => $factoryContent, 'exists' => $factoryExists, 'type' => 'factory'];
                    }
                }

                if ($createModelTest) {
                    $testPath = $connectionConfig->modelTestPath($modelName);
                    $testRelPath = str_replace(base_path().'/', '', $testPath);
                    $testExists = file_exists($testPath);
                    $testContent = (new ModelTestGenerator)->generate(
                        $tableDef, $prefixedModel, $connectionConfig->modelNamespace, $connectionConfig->factoryNamespace, $connectionConfig->testNamespace,
                    );

                    if ($write) {
                        if (! $force && $testExists) {
                            $outputFiles[] = ['path' => $testRelPath, 'skipped' => true, 'message' => 'Already exists.', 'type' => 'model_test'];
                            $summary['skipped']++;
                        } else {
                            $files->ensureDirectoryExists(dirname($testPath));
                            $files->put($testPath, $testContent);
                            $outputFiles[] = ['path' => $testRelPath, 'created' => true, 'message' => class_basename($testPath).' created.', 'type' => 'model_test'];
                            $summary['tests']++;
                        }
                    } else {
                        $outputFiles[] = ['path' => $testRelPath, 'content' => $testContent, 'exists' => $testExists, 'type' => 'model_test'];
                    }
                }

                if ($createService) {
                    $servicePath = $connectionConfig->servicePath($modelName);
                    $serviceRelPath = str_replace(base_path().'/', '', $servicePath);
                    $serviceExists = file_exists($servicePath);
                    $stubsPath = $this->resolveStubsBasePath();
                    $serviceContent = (new ApiCodeGenerator($stubsPath))->generateService(
                        $tableDef, $prefixedModel, $connectionConfig->modelNamespace, $connectionConfig->serviceNamespace,
                    )->content;

                    if ($write) {
                        if (! $force && $serviceExists) {
                            $outputFiles[] = ['path' => $serviceRelPath, 'skipped' => true, 'message' => 'Already exists.', 'type' => 'service'];
                            $summary['skipped']++;
                        } else {
                            $files->ensureDirectoryExists(dirname($servicePath));
                            $files->put($servicePath, $serviceContent);
                            $outputFiles[] = ['path' => $serviceRelPath, 'created' => true, 'message' => class_basename($servicePath).' created.', 'type' => 'service'];
                            $summary['services']++;
                        }
                    } else {
                        $outputFiles[] = ['path' => $serviceRelPath, 'content' => $serviceContent, 'exists' => $serviceExists, 'type' => 'service'];
                    }
                }
            }
        }

        return new JsonResponse([
            'success' => true,
            'files' => $outputFiles,
            'pivots' => $pivotTables,
            'summary' => $summary,
        ]);
    }

    /**
     * Resolve primary key configuration from the key type.
     *
     * @return array{imports: string, property: string}
     */
    private function resolveIdConfig(string $primaryKey): array
    {
        return match ($primaryKey) {
            'uuid' => [
                'imports' => "use SchemaCraft\\Attributes\\ColumnType;\nuse SchemaCraft\\Attributes\\Primary;",
                'property' => "    #[Primary]\n    #[ColumnType('uuid')]\n    public string \$id;",
            ],
            'ulid' => [
                'imports' => "use SchemaCraft\\Attributes\\ColumnType;\nuse SchemaCraft\\Attributes\\Primary;",
                'property' => "    #[Primary]\n    #[ColumnType('ulid')]\n    public string \$id;",
            ],
            default => [
                'imports' => "use SchemaCraft\\Attributes\\AutoIncrement;\nuse SchemaCraft\\Attributes\\Primary;",
                'property' => "    #[Primary]\n    #[AutoIncrement]\n    public int \$id;",
            ],
        };
    }

    /**
     * Generate schema + model file contents for given names.
     *
     * @param  string[]  $names
     * @param  array{imports: string, property: string}  $idConfig
     * @return array<int, array{path: string, content: string, exists: bool, type: string}>
     */
    private function generateSchemaFiles(Filesystem $files, array $names, array $idConfig, bool $softDeletes, bool $createModel): array
    {
        $schemaStub = $files->get($this->resolveStubPath('schema.stub'));
        $modelStub = $files->get($this->resolveStubPath('model.stub'));

        $generatedFiles = [];

        foreach ($names as $name) {
            $name = Str::studly($name);
            $schemaName = $name.'Schema';

            // Generate schema content
            $schemaContent = str_replace(
                ['{{ namespace }}', '{{ class }}', '{{ idImports }}', '{{ idProperty }}', '{{ softDeletesImport }}', '{{ softDeletesTrait }}'],
                ['App\\Schemas', $schemaName, $idConfig['imports'], $idConfig['property'], $softDeletes ? 'use SchemaCraft\\Traits\\SoftDeletesSchema;' : '', $softDeletes ? "    use SoftDeletesSchema;\n" : ''],
                $schemaStub,
            );
            $schemaContent = preg_replace('/\n{3,}/', "\n\n", $schemaContent);

            $schemaPath = "app/Schemas/{$schemaName}.php";
            $generatedFiles[] = [
                'path' => $schemaPath,
                'content' => $schemaContent,
                'exists' => file_exists(base_path($schemaPath)),
                'type' => 'schema',
            ];

            // Generate model content
            if ($createModel) {
                $schemaFqcn = "App\\Schemas\\{$schemaName}";
                $modelContent = str_replace(
                    ['{{ namespace }}', '{{ class }}', '{{ schemaFqcn }}', '{{ schemaClass }}', '{{ softDeletesImport }}', '{{ softDeletesTrait }}'],
                    ['App\\Models', $name, $schemaFqcn, $schemaName, $softDeletes ? 'use Illuminate\\Database\\Eloquent\\SoftDeletes;' : '', $softDeletes ? "    use SoftDeletes;\n\n" : ''],
                    $modelStub,
                );
                $modelContent = preg_replace('/\n{3,}/', "\n\n", $modelContent);

                $modelPath = "app/Models/{$name}.php";
                $generatedFiles[] = [
                    'path' => $modelPath,
                    'content' => $modelContent,
                    'exists' => file_exists(base_path($modelPath)),
                    'type' => 'model',
                ];
            }
        }

        return $generatedFiles;
    }

    /**
     * Convert a table name to a model class name.
     */
    private function tableToModelName(string $tableName): string
    {
        return Str::studly(Str::singular($tableName));
    }

    /**
     * Resolve a stub file path, preferring published stubs over package defaults.
     */
    private function resolveStubPath(string $filename): string
    {
        $publishedPath = base_path("stubs/schema-craft/{$filename}");

        if (file_exists($publishedPath)) {
            return $publishedPath;
        }

        return dirname(__DIR__)."/Console/stubs/{$filename}";
    }

    /**
     * Generate factory, model test, and/or service for tables with existing schemas.
     */
    public function generateExtras(Request $request, Filesystem $files): JsonResponse
    {
        $request->validate([
            'tables' => ['required', 'array', 'min:1'],
            'tables.*' => ['string'],
            'createFactory' => ['sometimes', 'boolean'],
            'createModelTest' => ['sometimes', 'boolean'],
            'createService' => ['sometimes', 'boolean'],
            'force' => ['sometimes', 'boolean'],
            'db_connection' => ['sometimes', 'string'],
        ]);

        $connectionConfig = ConfigResolver::resolveConnection($request->input('db_connection'));
        $force = $request->boolean('force', false);
        $outputFiles = [];
        $summary = ['factories' => 0, 'tests' => 0, 'services' => 0, 'skipped' => 0];

        foreach ($request->input('tables') as $tableName) {
            $modelName = $this->tableToModelName($tableName);
            $schemaClass = $connectionConfig->schemaClass($modelName);

            if (! class_exists($schemaClass)) {
                continue;
            }

            $scanner = new SchemaScanner($schemaClass);
            $table = $scanner->scan();
            $prefixedModel = $connectionConfig->prefixedModelName($modelName);

            if ($request->boolean('createFactory')) {
                $this->writeFactory($table, $prefixedModel, $connectionConfig, $files, $force, $outputFiles, $summary);
            }

            if ($request->boolean('createModelTest')) {
                $this->writeModelTest($table, $prefixedModel, $connectionConfig, $files, $force, $outputFiles, $summary);
            }

            if ($request->boolean('createService')) {
                $this->writeService($table, $prefixedModel, $connectionConfig, $files, $force, $outputFiles, $summary);
            }
        }

        return new JsonResponse([
            'success' => true,
            'files' => $outputFiles,
            'summary' => $summary,
        ]);
    }

    /**
     * Generate and write a factory file for a model.
     *
     * @param  array<int, array<string, mixed>>  $outputFiles
     * @param  array<string, int>  $summary
     */
    private function writeFactory(
        TableDefinition $table,
        string $modelName,
        ConnectionConfig $config,
        Filesystem $files,
        bool $force,
        array &$outputFiles,
        array &$summary,
    ): void {
        $path = $config->factoryPath($this->baseModelName($modelName, $config));
        $relPath = str_replace(base_path().'/', '', $path);

        if (! $force && file_exists($path)) {
            $outputFiles[] = ['path' => $relPath, 'skipped' => true, 'type' => 'factory'];
            $summary['skipped']++;

            return;
        }

        $content = (new FactoryGenerator)->generate(
            $table, $modelName, $config->modelNamespace, $config->factoryNamespace,
        );
        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $content);
        $outputFiles[] = ['path' => $relPath, 'created' => true, 'type' => 'factory'];
        $summary['factories']++;
    }

    /**
     * Generate and write a model test file.
     *
     * @param  array<int, array<string, mixed>>  $outputFiles
     * @param  array<string, int>  $summary
     */
    private function writeModelTest(
        TableDefinition $table,
        string $modelName,
        ConnectionConfig $config,
        Filesystem $files,
        bool $force,
        array &$outputFiles,
        array &$summary,
    ): void {
        $path = $config->modelTestPath($this->baseModelName($modelName, $config));
        $relPath = str_replace(base_path().'/', '', $path);

        if (! $force && file_exists($path)) {
            $outputFiles[] = ['path' => $relPath, 'skipped' => true, 'type' => 'model_test'];
            $summary['skipped']++;

            return;
        }

        $content = (new ModelTestGenerator)->generate(
            $table, $modelName, $config->modelNamespace, $config->factoryNamespace, $config->testNamespace,
        );
        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $content);
        $outputFiles[] = ['path' => $relPath, 'created' => true, 'type' => 'model_test'];
        $summary['tests']++;
    }

    /**
     * Generate and write a service file.
     *
     * @param  array<int, array<string, mixed>>  $outputFiles
     * @param  array<string, int>  $summary
     */
    private function writeService(
        TableDefinition $table,
        string $modelName,
        ConnectionConfig $config,
        Filesystem $files,
        bool $force,
        array &$outputFiles,
        array &$summary,
    ): void {
        $path = $config->servicePath($this->baseModelName($modelName, $config));
        $relPath = str_replace(base_path().'/', '', $path);

        if (! $force && file_exists($path)) {
            $outputFiles[] = ['path' => $relPath, 'skipped' => true, 'type' => 'service'];
            $summary['skipped']++;

            return;
        }

        $stubsPath = $this->resolveStubsBasePath();
        $content = (new ApiCodeGenerator($stubsPath))->generateService(
            $table, $modelName, $config->modelNamespace, $config->serviceNamespace,
        )->content;
        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $content);
        $outputFiles[] = ['path' => $relPath, 'created' => true, 'type' => 'service'];
        $summary['services']++;
    }

    /**
     * Strip the model prefix to get the base model name for path helpers.
     */
    private function baseModelName(string $prefixedModelName, ConnectionConfig $config): string
    {
        if ($config->modelPrefix !== '' && str_starts_with($prefixedModelName, $config->modelPrefix)) {
            return substr($prefixedModelName, strlen($config->modelPrefix));
        }

        return $prefixedModelName;
    }

    /**
     * Resolve the API stubs base path, preferring published stubs.
     */
    private function resolveStubsBasePath(): string
    {
        $publishedPath = base_path('stubs/schema-craft');

        if (is_dir($publishedPath.'/api')) {
            return $publishedPath;
        }

        return dirname(__DIR__).'/Console/stubs';
    }

    /**
     * Detect which table names are pivot tables using DatabaseReader.
     *
     * Returns a map of tableName => ['tableA' => ..., 'tableB' => ..., 'extraColumns' => [...]] for pivots.
     *
     * @param  string[]  $tableNames
     * @return array<string, array{tableA: string, tableB: string, extraColumns: array<string, string>}>
     */
    private function detectPivotTables(DatabaseReader $reader, array $tableNames): array
    {
        $generator = new SchemaFileGenerator;
        $pivots = [];

        foreach ($tableNames as $tableName) {
            $tableState = $reader->read($tableName);
            if ($tableState === null) {
                continue;
            }

            $pivot = $generator->detectPivotTable($tableState);
            if ($pivot !== null) {
                $pivots[$tableName] = $pivot;
            }
        }

        return $pivots;
    }

    /**
     * Build a TableDefinition from a DatabaseTableState for factory/test/service generation.
     *
     * Used during preview when schema files aren't on disk yet,
     * so SchemaScanner can't be used.
     */
    private function buildTableDefinitionFromDatabase(
        DatabaseTableState $tableState,
        ConnectionConfig $connectionConfig,
    ): TableDefinition {
        $modelName = $this->tableToModelName($tableState->tableName);
        $prefixedSchema = $connectionConfig->prefixedSchemaName($modelName);
        $schemaClass = $connectionConfig->schemaNamespace.'\\'.$prefixedSchema;

        // Map database columns to ColumnDefinitions
        $columns = [];
        foreach ($tableState->columns as $dbCol) {
            $columns[] = new ColumnDefinition(
                name: $dbCol->name,
                columnType: $dbCol->type,
                nullable: $dbCol->nullable,
                default: $dbCol->default,
                hasDefault: $dbCol->hasDefault,
                unsigned: $dbCol->unsigned,
                length: $dbCol->length,
                precision: $dbCol->precision,
                scale: $dbCol->scale,
                primary: $dbCol->primary,
                autoIncrement: $dbCol->autoIncrement,
                expressionDefault: $dbCol->expressionDefault,
            );
        }

        // Map foreign keys to BelongsTo relationships
        $relationships = [];
        foreach ($tableState->foreignKeys as $fk) {
            $relatedModelName = $this->tableToModelName($fk->foreignTable);
            $relatedClass = $connectionConfig->modelNamespace.'\\'.$connectionConfig->prefixedModelName($relatedModelName);
            $relationName = Str::camel(Str::replaceLast('_id', '', $fk->column));
            $fkColumn = $tableState->getColumn($fk->column);

            $relationships[] = new RelationshipDefinition(
                name: $relationName,
                type: 'belongsTo',
                relatedModel: $relatedClass,
                nullable: $fkColumn?->nullable ?? false,
                foreignColumn: $fk->column,
            );
        }

        return new TableDefinition(
            tableName: $tableState->tableName,
            schemaClass: $schemaClass,
            columns: $columns,
            relationships: $relationships,
            hasTimestamps: $tableState->hasTimestamps(),
            hasSoftDeletes: $tableState->hasSoftDeletes(),
        );
    }

    /**
     * Convert a namespace to an absolute directory path using app_path().
     *
     * App\Schemas → app_path('Schemas')
     * App\Schemas\Crm → app_path('Schemas/Crm')
     * Custom\Namespace → base_path('Custom/Namespace')
     */
    private function namespaceToAppPath(string $namespace): string
    {
        $path = str_replace('\\', '/', $namespace);

        if (str_starts_with($path, 'App/')) {
            return app_path(substr($path, 4));
        }

        return base_path($path);
    }
}
