<?php

namespace SchemaCraft\Visualizer;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use SchemaCraft\Config\ConfigResolver;
use SchemaCraft\Generator\SchemaFileGenerator;
use SchemaCraft\Migration\DatabaseReader;

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
        $connectionConfig = ConfigResolver::resolveConnection($request->query('db_connection'));
        $dbConnection = $connectionConfig->needsConnectionProperty() ? $connectionConfig->connection : null;
        $reader = new DatabaseReader($dbConnection);
        $allTableNames = $reader->tables();

        $schemaDir = $this->namespaceToAppPath($connectionConfig->schemaNamespace);
        $modelDir = $this->namespaceToAppPath($connectionConfig->modelNamespace);

        $tables = [];
        foreach ($allTableNames as $tableName) {
            if (in_array($tableName, self::LARAVEL_INTERNAL_TABLES, true)) {
                continue;
            }

            $modelName = $this->tableToModelName($tableName);
            $prefixedSchema = $connectionConfig->prefixedSchemaName($modelName);
            $prefixedModel = $connectionConfig->prefixedModelName($modelName);

            $tables[] = [
                'name' => $tableName,
                'modelName' => $modelName,
                'hasSchema' => file_exists("{$schemaDir}/{$prefixedSchema}.php"),
                'hasModel' => file_exists("{$modelDir}/{$prefixedModel}.php"),
            ];
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
            'db_connection' => ['sometimes', 'string'],
        ]);

        $tableNames = $request->input('tables');
        $createModel = $request->boolean('createModel', true);

        return $this->runImport($tableNames, $createModel, false, false, $request->input('db_connection'));
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

        return $this->runImport($tableNames, $createModel, true, $force, $request->input('db_connection'));
    }

    /**
     * Core import logic shared by preview and write.
     */
    private function runImport(array $tableNames, bool $createModel, bool $write, bool $force, ?string $dbConnectionName = null): JsonResponse
    {
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

        foreach ($allTables as $tableName => $tableState) {
            $pivot = $generator->detectPivotTable($tableState);
            if ($pivot !== null) {
                $pivotTables[] = [
                    'table' => $tableName,
                    'tableA' => $pivot['tableA'],
                    'tableB' => $pivot['tableB'],
                ];

                if (isset($allTables[$pivot['tableA']])) {
                    $pivotMap[$pivot['tableA']][$pivot['tableB']] = $tableName;
                }
                if (isset($allTables[$pivot['tableB']])) {
                    $pivotMap[$pivot['tableB']][$pivot['tableA']] = $tableName;
                }
            } else {
                $regularTables[$tableName] = $tableState;
            }
        }

        // Generate files
        $outputFiles = [];
        $summary = ['schemas' => 0, 'models' => 0, 'skipped' => 0, 'pivots' => count($pivotTables)];

        foreach ($regularTables as $tableName => $tableState) {
            $pivotRelationships = $pivotMap[$tableName] ?? [];

            $result = $generator->generate(
                table: $tableState,
                allTables: $regularTables,
                pivotRelationships: $pivotRelationships,
                schemaNamespace: $connectionConfig->schemaNamespace,
                modelNamespace: $connectionConfig->modelNamespace,
                schemaPrefix: $connectionConfig->schemaPrefix,
                modelPrefix: $connectionConfig->modelPrefix,
                connection: $emitConnection,
            );

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
