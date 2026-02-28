<?php

namespace SchemaCraft\Visualizer;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use SchemaCraft\Config\ApiConfig;
use SchemaCraft\Config\ConfigResolver;
use SchemaCraft\Generator\Api\ApiCodeGenerator;
use SchemaCraft\Generator\Api\ApiFileWriter;
use SchemaCraft\Generator\Api\GeneratedFile;
use SchemaCraft\Generator\Api\ResourceGenerator;
use SchemaCraft\Generator\ControllerTestGenerator;
use SchemaCraft\Generator\DependencyResolver;
use SchemaCraft\Generator\FactoryGenerator;
use SchemaCraft\Generator\Filament\FilamentCodeGenerator;
use SchemaCraft\Generator\Filament\FilamentPolicyGenerator;
use SchemaCraft\Generator\ModelTestGenerator;
use SchemaCraft\Generator\Sdk\ControllerActionScanner;
use SchemaCraft\Generator\Sdk\SdkGenerator;
use SchemaCraft\Generator\Sdk\SdkSchemaContext;
use SchemaCraft\Migration\SchemaDiscovery;
use SchemaCraft\Scanner\SchemaScanner;

class GenerateController
{
    /**
     * Get available APIs and discoverable schemas.
     */
    public function config(Request $request): JsonResponse
    {
        $apis = ConfigResolver::allApiNames();
        $apiConfig = ConfigResolver::resolve($request->query('api'));
        $actionScanner = new ControllerActionScanner;

        $directories = ConfigResolver::schemaDirectories();
        $discovery = new SchemaDiscovery;
        $schemaClasses = $discovery->discover($directories);

        $schemas = [];
        foreach ($schemaClasses as $schemaClass) {
            $modelName = $this->resolveModelName($schemaClass);
            $controllerPath = $apiConfig->controllerPath($modelName);
            $hasController = file_exists($controllerPath);

            $endpointCount = 0;
            if ($hasController) {
                $customActions = $actionScanner->scanFile($controllerPath);
                $endpointCount = 5 + count($customActions);
            }

            $schemas[] = [
                'class' => $schemaClass,
                'modelName' => $modelName,
                'hasController' => $hasController,
                'hasService' => file_exists($apiConfig->servicePath($modelName)),
                'endpointCount' => $endpointCount,
            ];
        }

        return new JsonResponse([
            'apis' => $apis,
            'schemas' => $schemas,
        ]);
    }

    /**
     * Get detailed endpoint info for a generated API stack.
     */
    public function stackDetail(Request $request): JsonResponse
    {
        $request->validate([
            'schema' => ['required', 'string'],
            'api' => ['sometimes', 'string'],
        ]);

        $apiConfig = ConfigResolver::resolve($request->query('api'));
        $schemaClass = $this->resolveSchemaClass($request->query('schema'), $apiConfig);
        $modelName = $this->resolveModelName($schemaClass);

        $controllerPath = $apiConfig->controllerPath($modelName);

        if (! file_exists($controllerPath)) {
            return new JsonResponse([
                'success' => false,
                'message' => "Controller for [{$modelName}] not found.",
            ], 404);
        }

        $routePrefix = Str::snake(Str::pluralStudly($modelName), '-');
        $routeParam = Str::camel($modelName);

        $endpoints = [
            ['method' => 'GET', 'path' => "/{$routePrefix}", 'action' => 'getCollection', 'type' => 'standard'],
            ['method' => 'GET', 'path' => "/{$routePrefix}/{{$routeParam}}", 'action' => 'get', 'type' => 'standard'],
            ['method' => 'POST', 'path' => "/{$routePrefix}", 'action' => 'create', 'type' => 'standard'],
            ['method' => 'PUT', 'path' => "/{$routePrefix}/{{$routeParam}}", 'action' => 'update', 'type' => 'standard'],
            ['method' => 'DELETE', 'path' => "/{$routePrefix}/{{$routeParam}}", 'action' => 'delete', 'type' => 'standard'],
        ];

        $actionScanner = new ControllerActionScanner;
        $customActions = $actionScanner->scanFile($controllerPath);

        foreach ($customActions as $actionName) {
            $actionSlug = Str::snake($actionName, '-');
            $endpoints[] = [
                'method' => 'PUT',
                'path' => "/{$routePrefix}/{{$routeParam}}/{$actionSlug}",
                'action' => $actionName,
                'type' => 'custom',
            ];
        }

        // Scan schema for field metadata
        $fields = [];
        $relationships = [];
        $editableFields = [];

        if (class_exists($schemaClass)) {
            $scanner = new SchemaScanner($schemaClass);
            $table = $scanner->scan();

            $skipNames = ['id', 'created_at', 'updated_at', 'deleted_at'];

            foreach ($table->columns as $col) {
                $isEditable = ! $col->primary
                    && ! $col->autoIncrement
                    && ! in_array($col->name, $skipNames, true);

                $field = [
                    'name' => $col->name,
                    'type' => $col->columnType,
                    'nullable' => $col->nullable,
                    'editable' => $isEditable,
                ];

                if ($col->primary) {
                    $field['primary'] = true;
                }
                if ($col->autoIncrement) {
                    $field['autoIncrement'] = true;
                }
                if ($col->unsigned) {
                    $field['unsigned'] = true;
                }
                if ($col->length !== null) {
                    $field['length'] = $col->length;
                }
                if ($col->precision !== null) {
                    $field['precision'] = $col->precision;
                }
                if ($col->scale !== null) {
                    $field['scale'] = $col->scale;
                }
                if ($col->unique) {
                    $field['unique'] = true;
                }
                if ($col->hasDefault) {
                    $field['default'] = $col->default;
                }
                if ($col->castType !== null) {
                    $field['cast'] = class_basename($col->castType);
                }

                $fields[] = $field;

                if ($isEditable) {
                    $rules = [];
                    $rules[] = $col->nullable ? 'nullable' : 'required';

                    match (true) {
                        str_starts_with($col->columnType, 'unsigned') => array_push($rules, 'integer', 'min:0'),
                        in_array($col->columnType, ['string']) => array_push($rules, 'string', 'max:'.($col->length ?? 255)),
                        in_array($col->columnType, ['text', 'mediumText', 'longText']) => $rules[] = 'string',
                        in_array($col->columnType, ['integer', 'bigInteger', 'smallInteger', 'tinyInteger']) => $rules[] = 'integer',
                        $col->columnType === 'boolean' => $rules[] = 'boolean',
                        in_array($col->columnType, ['decimal', 'float', 'double']) => $rules[] = 'numeric',
                        in_array($col->columnType, ['timestamp', 'dateTime', 'dateTimeTz', 'date']) => $rules[] = 'date',
                        in_array($col->columnType, ['time', 'timeTz']) => $rules[] = 'date_format:H:i:s',
                        $col->columnType === 'json' => $rules[] = 'array',
                        $col->columnType === 'uuid' => array_push($rules, 'string', 'uuid'),
                        $col->columnType === 'ulid' => array_push($rules, 'string', 'ulid'),
                        $col->columnType === 'year' => array_push($rules, 'integer', 'digits:4'),
                        default => $rules[] = 'string',
                    };

                    if ($col->unique) {
                        $rules[] = "unique:{$table->tableName},{$col->name}";
                    }

                    $editableFields[] = [
                        'name' => $col->name,
                        'type' => $col->columnType,
                        'nullable' => $col->nullable,
                        'rules' => implode('|', $rules),
                    ];
                }
            }

            // Add timestamps to fields if present
            if ($table->hasTimestamps) {
                if (! collect($fields)->contains('name', 'created_at')) {
                    $fields[] = ['name' => 'created_at', 'type' => 'timestamp', 'nullable' => true, 'editable' => false];
                }
                if (! collect($fields)->contains('name', 'updated_at')) {
                    $fields[] = ['name' => 'updated_at', 'type' => 'timestamp', 'nullable' => true, 'editable' => false];
                }
            }

            foreach ($table->relationships as $rel) {
                $relData = [
                    'name' => $rel->name,
                    'type' => $rel->type,
                    'relatedModel' => class_basename($rel->relatedModel),
                ];

                // Resolve the related schema class for drill-down
                $relatedSchemaClass = $this->resolveRelatedSchemaClass($rel->relatedModel);
                if ($relatedSchemaClass !== null) {
                    $relData['relatedSchema'] = $relatedSchemaClass;
                }

                $relationships[] = $relData;
            }
        }

        return new JsonResponse([
            'modelName' => $modelName,
            'routePrefix' => $routePrefix,
            'routeParam' => $routeParam,
            'hasController' => true,
            'hasService' => file_exists($apiConfig->servicePath($modelName)),
            'endpoints' => $endpoints,
            'fields' => $fields,
            'editableFields' => $editableFields,
            'relationships' => $relationships,
        ]);
    }

    /**
     * Get field and relationship metadata for any schema (no controller required).
     * Used for drill-down into related resources.
     */
    public function resourceDetail(Request $request): JsonResponse
    {
        $request->validate([
            'schema' => ['required', 'string'],
        ]);

        $schemaClass = $request->query('schema');

        if (! class_exists($schemaClass) || ! is_subclass_of($schemaClass, \SchemaCraft\Schema::class)) {
            return new JsonResponse([
                'success' => false,
                'message' => "Schema class [{$schemaClass}] not found.",
            ], 404);
        }

        $modelName = $this->resolveModelName($schemaClass);

        return new JsonResponse(
            $this->buildResourceMetadata($schemaClass, $modelName),
        );
    }

    /**
     * Build field and relationship metadata for a schema class.
     *
     * @return array{modelName: string, fields: array, relationships: array}
     */
    private function buildResourceMetadata(string $schemaClass, string $modelName): array
    {
        $scanner = new SchemaScanner($schemaClass);
        $table = $scanner->scan();

        $fields = [];
        $relationships = [];
        $skipNames = ['id', 'created_at', 'updated_at', 'deleted_at'];

        foreach ($table->columns as $col) {
            $isEditable = ! $col->primary
                && ! $col->autoIncrement
                && ! in_array($col->name, $skipNames, true);

            $field = [
                'name' => $col->name,
                'type' => $col->columnType,
                'nullable' => $col->nullable,
                'editable' => $isEditable,
            ];

            if ($col->primary) {
                $field['primary'] = true;
            }
            if ($col->autoIncrement) {
                $field['autoIncrement'] = true;
            }
            if ($col->unsigned) {
                $field['unsigned'] = true;
            }
            if ($col->length !== null) {
                $field['length'] = $col->length;
            }
            if ($col->precision !== null) {
                $field['precision'] = $col->precision;
            }
            if ($col->scale !== null) {
                $field['scale'] = $col->scale;
            }
            if ($col->unique) {
                $field['unique'] = true;
            }
            if ($col->hasDefault) {
                $field['default'] = $col->default;
            }
            if ($col->castType !== null) {
                $field['cast'] = class_basename($col->castType);
            }

            $fields[] = $field;
        }

        // Add timestamps if present
        if ($table->hasTimestamps) {
            if (! collect($fields)->contains('name', 'created_at')) {
                $fields[] = ['name' => 'created_at', 'type' => 'timestamp', 'nullable' => true, 'editable' => false];
            }
            if (! collect($fields)->contains('name', 'updated_at')) {
                $fields[] = ['name' => 'updated_at', 'type' => 'timestamp', 'nullable' => true, 'editable' => false];
            }
        }

        foreach ($table->relationships as $rel) {
            $relData = [
                'name' => $rel->name,
                'type' => $rel->type,
                'relatedModel' => class_basename($rel->relatedModel),
            ];

            $relatedSchemaClass = $this->resolveRelatedSchemaClass($rel->relatedModel);
            if ($relatedSchemaClass !== null) {
                $relData['relatedSchema'] = $relatedSchemaClass;
            }

            $relationships[] = $relData;
        }

        return [
            'modelName' => $modelName,
            'fields' => $fields,
            'relationships' => $relationships,
        ];
    }

    /**
     * Preview full API stack generation without writing.
     */
    public function generatePreview(Request $request): JsonResponse
    {
        $request->validate([
            'schema' => ['required', 'string'],
            'api' => ['sometimes', 'string'],
            'noFactory' => ['sometimes', 'boolean'],
            'noTest' => ['sometimes', 'boolean'],
        ]);

        $apiConfig = ConfigResolver::resolve($request->input('api'));
        $schemaClass = $this->resolveSchemaClass($request->input('schema'), $apiConfig);

        if (! class_exists($schemaClass)) {
            return new JsonResponse(['success' => false, 'message' => "Schema class [{$schemaClass}] not found."], 404);
        }

        $files = $this->buildApiStackFiles(
            $schemaClass,
            $apiConfig,
            ! $request->boolean('noFactory'),
            ! $request->boolean('noTest'),
        );

        $previewFiles = [];
        foreach ($files as $file) {
            $previewFiles[] = [
                'path' => $file->path,
                'content' => $file->content,
                'exists' => file_exists(base_path($file->path)),
            ];
        }

        return new JsonResponse(['success' => true, 'files' => $previewFiles]);
    }

    /**
     * Generate full API stack and write files to disk.
     */
    public function generate(Request $request, Filesystem $fs): JsonResponse
    {
        $request->validate([
            'schema' => ['required', 'string'],
            'api' => ['sometimes', 'string'],
            'noFactory' => ['sometimes', 'boolean'],
            'noTest' => ['sometimes', 'boolean'],
            'force' => ['sometimes', 'boolean'],
        ]);

        $apiConfig = ConfigResolver::resolve($request->input('api'));
        $schemaClass = $this->resolveSchemaClass($request->input('schema'), $apiConfig);
        $force = $request->boolean('force', false);

        if (! class_exists($schemaClass)) {
            return new JsonResponse(['success' => false, 'message' => "Schema class [{$schemaClass}] not found."], 404);
        }

        $files = $this->buildApiStackFiles(
            $schemaClass,
            $apiConfig,
            ! $request->boolean('noFactory'),
            ! $request->boolean('noTest'),
        );

        $results = [];
        foreach ($files as $file) {
            $absolutePath = base_path($file->path);

            if (! $force && $fs->exists($absolutePath)) {
                $results[] = ['path' => $file->path, 'skipped' => true, 'message' => 'Already exists.'];

                continue;
            }

            $fs->ensureDirectoryExists(dirname($absolutePath));
            $fs->put($absolutePath, $file->content);
            $results[] = ['path' => $file->path, 'created' => true, 'message' => class_basename($file->path).' created.'];
        }

        $created = count(array_filter($results, fn ($r) => $r['created'] ?? false));

        return new JsonResponse([
            'success' => true,
            'files' => $results,
            'message' => "{$created} file(s) created.",
        ]);
    }

    /**
     * Preview adding an action to existing API.
     */
    public function actionPreview(Request $request): JsonResponse
    {
        $request->validate([
            'schema' => ['required', 'string'],
            'action' => ['required', 'string'],
            'api' => ['sometimes', 'string'],
        ]);

        return $this->handleAction($request, false);
    }

    /**
     * Add an action to existing API and write files.
     */
    public function action(Request $request, Filesystem $fs): JsonResponse
    {
        $request->validate([
            'schema' => ['required', 'string'],
            'action' => ['required', 'string'],
            'api' => ['sometimes', 'string'],
        ]);

        return $this->handleAction($request, true, $fs);
    }

    /**
     * Create a new API configuration.
     */
    public function createApi(Request $request, Filesystem $fs): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string'],
            'prefix' => ['sometimes', 'string'],
        ]);

        $name = $request->input('name');
        $studlyName = Str::studly($name);
        $prefix = $request->input('prefix', Str::kebab($name).'-api');

        $existingApis = config('schema-craft.apis', []);
        if (isset($existingApis[$name])) {
            return new JsonResponse(['success' => false, 'message' => "API configuration [{$name}] already exists."], 422);
        }

        $createdFiles = [];

        // Create route file
        $routeFile = "routes/{$name}-api.php";
        $routeAbsolutePath = base_path($routeFile);

        if (! $fs->exists($routeAbsolutePath)) {
            $stubsPath = $this->resolveStubsPath();
            $stub = $fs->get($stubsPath.'/api/route-file.stub');

            $content = str_replace(
                ['{{ apiName }}', '{{ prefix }}'],
                [$studlyName, $prefix],
                $stub,
            );

            $fs->ensureDirectoryExists(dirname($routeAbsolutePath));
            $fs->put($routeAbsolutePath, $content);
            $createdFiles[] = $routeFile;
        }

        // Create isolated directories
        $directories = [
            app_path("Http/Controllers/{$studlyName}Api"),
            app_path("Http/Requests/{$studlyName}Api"),
            app_path("Resources/{$studlyName}Api"),
        ];

        foreach ($directories as $dir) {
            if (! is_dir($dir)) {
                $fs->makeDirectory($dir, 0755, true);
                $fs->put($dir.'/.gitkeep', '');
            }
        }

        // Add API entry to config file
        $configPath = config_path('schema-craft.php');
        if ($fs->exists($configPath)) {
            $this->addApiToConfig($fs, $configPath, $name, $studlyName, $routeFile, $prefix);
        }

        // Register route in bootstrap/app.php
        $this->registerRouteInBootstrap($fs, $routeFile, $prefix, $name);

        return new JsonResponse([
            'success' => true,
            'message' => "API [{$name}] created successfully.",
            'files' => $createdFiles,
        ]);
    }

    /**
     * Get SDK configuration for an API.
     */
    public function sdkConfig(Request $request): JsonResponse
    {
        $apiConfig = ConfigResolver::resolve($request->query('api'));

        return new JsonResponse([
            'path' => $apiConfig->sdkPath,
            'name' => $apiConfig->sdkName,
            'namespace' => $apiConfig->sdkNamespace,
            'client' => $apiConfig->sdkClient,
            'version' => $apiConfig->sdkVersion,
        ]);
    }

    /**
     * Preview SDK generation without writing.
     */
    public function sdkPreview(Request $request): JsonResponse
    {
        $request->validate([
            'api' => ['sometimes', 'string'],
            'path' => ['sometimes', 'string'],
            'name' => ['sometimes', 'string'],
            'namespace' => ['sometimes', 'string'],
            'client' => ['sometimes', 'string'],
            'version' => ['sometimes', 'string'],
        ]);

        $files = $this->buildSdkFiles($request);

        if ($files === null) {
            return new JsonResponse(['success' => false, 'message' => 'No schemas with generated API controllers found.'], 422);
        }

        $sdkPath = $this->resolveSdkOutputPath($request);
        $previewFiles = [];
        foreach ($files as $file) {
            $previewFiles[] = [
                'path' => $sdkPath.'/'.$file->path,
                'content' => $file->content,
                'exists' => file_exists(base_path($sdkPath.'/'.$file->path)),
            ];
        }

        return new JsonResponse(['success' => true, 'files' => $previewFiles]);
    }

    /**
     * Generate SDK and write files to disk.
     */
    public function sdkGenerate(Request $request, Filesystem $fs): JsonResponse
    {
        $request->validate([
            'api' => ['sometimes', 'string'],
            'path' => ['sometimes', 'string'],
            'name' => ['sometimes', 'string'],
            'namespace' => ['sometimes', 'string'],
            'client' => ['sometimes', 'string'],
            'version' => ['sometimes', 'string'],
            'force' => ['sometimes', 'boolean'],
        ]);

        $files = $this->buildSdkFiles($request);
        $force = $request->boolean('force', false);

        if ($files === null) {
            return new JsonResponse(['success' => false, 'message' => 'No schemas with generated API controllers found.'], 422);
        }

        $sdkPath = $this->resolveSdkOutputPath($request);
        $outputPath = base_path($sdkPath);
        $results = [];

        foreach ($files as $file) {
            $absolutePath = $outputPath.'/'.$file->path;

            if (! $force && $fs->exists($absolutePath)) {
                $results[] = ['path' => $sdkPath.'/'.$file->path, 'skipped' => true, 'message' => 'Already exists.'];

                continue;
            }

            $fs->ensureDirectoryExists(dirname($absolutePath));
            $fs->put($absolutePath, $file->content);
            $results[] = ['path' => $sdkPath.'/'.$file->path, 'created' => true, 'message' => basename($file->path).' created.'];
        }

        $created = count(array_filter($results, fn ($r) => $r['created'] ?? false));

        return new JsonResponse([
            'success' => true,
            'files' => $results,
            'message' => "{$created} SDK file(s) created.",
        ]);
    }

    /**
     * Preview Filament resource generation without writing.
     */
    public function filamentPreview(Request $request): JsonResponse
    {
        $request->validate([
            'schema' => ['required', 'string'],
            'withPolicy' => ['sometimes', 'boolean'],
        ]);

        $schemaClass = $request->input('schema');

        if (! class_exists($schemaClass) || ! is_subclass_of($schemaClass, \SchemaCraft\Schema::class)) {
            return new JsonResponse(['success' => false, 'message' => "Schema class [{$schemaClass}] not found."], 404);
        }

        $files = $this->buildFilamentFiles($schemaClass, $request->boolean('withPolicy', false));

        $previewFiles = [];
        foreach ($files as $file) {
            $previewFiles[] = [
                'path' => $file->path,
                'content' => $file->content,
                'exists' => file_exists(base_path($file->path)),
            ];
        }

        return new JsonResponse(['success' => true, 'files' => $previewFiles]);
    }

    /**
     * Generate Filament resource and write files to disk.
     */
    public function filamentGenerate(Request $request, Filesystem $fs): JsonResponse
    {
        $request->validate([
            'schema' => ['required', 'string'],
            'withPolicy' => ['sometimes', 'boolean'],
            'force' => ['sometimes', 'boolean'],
        ]);

        $schemaClass = $request->input('schema');
        $force = $request->boolean('force', false);

        if (! class_exists($schemaClass) || ! is_subclass_of($schemaClass, \SchemaCraft\Schema::class)) {
            return new JsonResponse(['success' => false, 'message' => "Schema class [{$schemaClass}] not found."], 404);
        }

        $files = $this->buildFilamentFiles($schemaClass, $request->boolean('withPolicy', false));

        $results = [];
        foreach ($files as $file) {
            $absolutePath = base_path($file->path);

            if (! $force && $fs->exists($absolutePath)) {
                $results[] = ['path' => $file->path, 'skipped' => true, 'message' => 'Already exists.'];

                continue;
            }

            $fs->ensureDirectoryExists(dirname($absolutePath));
            $fs->put($absolutePath, $file->content);
            $results[] = ['path' => $file->path, 'created' => true, 'message' => class_basename($file->path).' created.'];
        }

        $created = count(array_filter($results, fn ($r) => $r['created'] ?? false));

        return new JsonResponse([
            'success' => true,
            'files' => $results,
            'message' => "{$created} Filament file(s) created.",
        ]);
    }

    /**
     * Build Filament resource files for a schema.
     *
     * @return GeneratedFile[]
     */
    private function buildFilamentFiles(string $schemaClass, bool $withPolicy): array
    {
        $modelName = $this->resolveModelName($schemaClass);
        $scanner = new SchemaScanner($schemaClass);
        $table = $scanner->scan();

        $stubsPath = $this->resolveStubsPath();
        $generator = new FilamentCodeGenerator($stubsPath);

        $files = $generator->generate(
            table: $table,
            modelName: $modelName,
        );

        if ($withPolicy) {
            $policyGenerator = new FilamentPolicyGenerator($stubsPath);
            $files['policy'] = $policyGenerator->generate(modelName: $modelName);
        }

        return array_values($files);
    }

    /**
     * Build the full API stack files (preview or write).
     *
     * @return GeneratedFile[]
     */
    private function buildApiStackFiles(string $schemaClass, ApiConfig $apiConfig, bool $withFactory, bool $withTests): array
    {
        $modelName = $this->resolveModelName($schemaClass);
        $scanner = new SchemaScanner($schemaClass);
        $table = $scanner->scan();

        $stubsPath = $this->resolveStubsPath();
        $generator = new ApiCodeGenerator($stubsPath);

        $generatedFiles = $generator->generate(
            table: $table,
            modelName: $modelName,
            modelNamespace: $apiConfig->modelNamespace,
            controllerNamespace: $apiConfig->controllerNamespace,
            serviceNamespace: $apiConfig->serviceNamespace,
            requestNamespace: $apiConfig->requestNamespace,
            resourceNamespace: $apiConfig->resourceNamespace,
            schemaNamespace: $apiConfig->schemaNamespace,
        );

        // Dependency resources
        $resolver = new DependencyResolver;
        $deps = $resolver->resolveDependencies($table);
        $resourceDir = str_replace('\\', '/', $apiConfig->resourceNamespace);
        if (str_starts_with($resourceDir, 'App/')) {
            $resourceDir = 'app/'.substr($resourceDir, 4);
        }

        foreach ($deps as $depModelName => $depTable) {
            $generatedFiles["dependency_resource_{$depModelName}"] = new GeneratedFile(
                path: "{$resourceDir}/{$depModelName}Resource.php",
                content: (new ResourceGenerator)->generate($depTable, $apiConfig->resourceNamespace),
            );
        }

        // Factory
        if ($withFactory) {
            $generatedFiles['factory'] = new GeneratedFile(
                path: "database/factories/{$modelName}Factory.php",
                content: (new FactoryGenerator)->generate($table, $modelName, $apiConfig->modelNamespace),
            );
        }

        // Tests
        if ($withTests) {
            $generatedFiles['model_test'] = new GeneratedFile(
                path: "tests/Unit/{$modelName}ModelTest.php",
                content: (new ModelTestGenerator)->generate($table, $modelName, $apiConfig->modelNamespace),
            );

            $testDir = $apiConfig->name === 'default'
                ? 'tests/Feature/Controllers'
                : 'tests/Feature/Controllers/'.ucfirst($apiConfig->name).'Api';

            $generatedFiles['controller_test'] = new GeneratedFile(
                path: "{$testDir}/{$modelName}ControllerTest.php",
                content: (new ControllerTestGenerator)->generate(
                    $table,
                    $modelName,
                    $apiConfig->modelNamespace,
                    routePrefix: $apiConfig->routePrefix,
                ),
            );
        }

        return array_values($generatedFiles);
    }

    /**
     * Handle add-action flow for both preview and write.
     */
    private function handleAction(Request $request, bool $write, ?Filesystem $fs = null): JsonResponse
    {
        $apiConfig = ConfigResolver::resolve($request->input('api'));
        $schemaClass = $this->resolveSchemaClass($request->input('schema'), $apiConfig);
        $actionName = $request->input('action');
        $modelName = $this->resolveModelName($schemaClass);

        $controllerPath = $apiConfig->controllerPath($modelName);
        $servicePath = $apiConfig->servicePath($modelName);

        if (! file_exists($controllerPath)) {
            return new JsonResponse(['success' => false, 'message' => 'Controller not found. Generate the API first.'], 422);
        }

        $hasService = file_exists($servicePath);

        $fs = $fs ?? new Filesystem;
        $writer = new ApiFileWriter;

        $modelVariable = Str::camel($modelName);
        $routePrefix = Str::snake(Str::pluralStudly($modelName), '-');
        $routeParam = $modelVariable;
        $requestClass = ucfirst($actionName).$modelName.'Request';
        $requestFqcn = "{$apiConfig->requestNamespace}\\{$requestClass}";

        $stubsPath = $this->resolveStubsPath();
        $generator = new ApiCodeGenerator($stubsPath);
        $requestFile = $generator->generateAction($actionName, $modelName, $apiConfig->requestNamespace);

        // Build patched controller content
        $controllerContent = $fs->get($controllerPath);
        $controllerContent = $writer->addImport($controllerContent, $requestFqcn);
        $controllerContent = $writer->addRoute(
            $controllerContent,
            'put',
            $routePrefix,
            $actionName,
            $modelName.'Controller',
            $routeParam,
        );
        $controllerContent = $writer->addControllerMethod(
            $controllerContent,
            $actionName,
            $modelName,
            $modelVariable,
            $requestClass,
        );

        // Build patched service content (only if service exists)
        $serviceContent = null;
        $serviceRelPath = null;

        if ($hasService) {
            $serviceContent = $fs->get($servicePath);
            $serviceContent = $writer->addServiceMethod(
                $serviceContent,
                $actionName,
                $modelName,
                $modelVariable,
            );
            $serviceRelPath = str_replace(base_path().'/', '', $servicePath);
        }

        $controllerRelPath = str_replace(base_path().'/', '', $controllerPath);

        if ($write) {
            // Write request file
            $requestAbsPath = base_path($requestFile->path);
            $fs->ensureDirectoryExists(dirname($requestAbsPath));
            $fs->put($requestAbsPath, $requestFile->content);

            // Write patched controller
            $fs->put($controllerPath, $controllerContent);

            $resultFiles = [
                ['path' => $requestFile->path, 'created' => true],
                ['path' => $controllerRelPath, 'updated' => true],
            ];

            // Write patched service if it exists
            if ($hasService && $serviceContent !== null) {
                $fs->put($servicePath, $serviceContent);
                $resultFiles[] = ['path' => $serviceRelPath, 'updated' => true];
            }

            return new JsonResponse([
                'success' => true,
                'message' => "Action [{$actionName}] added successfully.",
                'files' => $resultFiles,
            ]);
        }

        // Preview mode
        $previewFiles = [
            ['path' => $requestFile->path, 'content' => $requestFile->content, 'exists' => false],
            ['path' => $controllerRelPath, 'content' => $controllerContent, 'exists' => true],
        ];

        if ($hasService && $serviceContent !== null) {
            $previewFiles[] = ['path' => $serviceRelPath, 'content' => $serviceContent, 'exists' => true];
        }

        return new JsonResponse([
            'success' => true,
            'files' => $previewFiles,
        ]);
    }

    /**
     * Build SDK files for the given API config.
     *
     * @return GeneratedFile[]|null
     */
    private function buildSdkFiles(Request $request): ?array
    {
        $apiConfig = ConfigResolver::resolve($request->input('api'));
        $directories = ConfigResolver::schemaDirectories();
        $discovery = new SchemaDiscovery;
        $actionScanner = new ControllerActionScanner;
        $fs = new Filesystem;

        $schemaClasses = $discovery->discover($directories);

        if ($apiConfig->schemas !== null) {
            $schemaClasses = array_filter($schemaClasses, function (string $schemaClass) use ($apiConfig) {
                return in_array(class_basename($schemaClass), $apiConfig->schemas);
            });
        }

        // Build contexts for schemas with controllers
        $schemas = [];
        foreach ($schemaClasses as $schemaClass) {
            $modelName = $this->resolveModelName($schemaClass);
            $controllerPath = $apiConfig->controllerPath($modelName);

            if (! $fs->exists($controllerPath)) {
                continue;
            }

            $scanner = new SchemaScanner($schemaClass);
            $table = $scanner->scan();
            $customActions = $actionScanner->scanFile($controllerPath);

            $schemas[$modelName] = new SdkSchemaContext(
                table: $table,
                customActions: $customActions,
            );
        }

        if (empty($schemas)) {
            return null;
        }

        // Resolve dependency schemas
        $resolver = new DependencyResolver;
        $dependencySchemas = [];

        foreach ($schemas as $context) {
            $deps = $resolver->resolveDependencies($context->table);
            foreach ($deps as $depModelName => $depTable) {
                if (! isset($schemas[$depModelName]) && ! isset($dependencySchemas[$depModelName])) {
                    $dependencySchemas[$depModelName] = new SdkSchemaContext(
                        table: $depTable,
                        isDependencyOnly: true,
                    );
                }
            }
        }

        $allSchemas = array_merge($schemas, $dependencySchemas);

        $sdkName = $request->input('name') ?? $apiConfig->sdkName;
        $sdkNamespace = $request->input('namespace') ?? $apiConfig->sdkNamespace;
        $sdkClient = $request->input('client') ?? $apiConfig->sdkClient;
        $sdkVersion = $request->input('version') ?? $apiConfig->sdkVersion;

        $stubsPath = $this->resolveStubsPath();
        $generator = new SdkGenerator;

        return $generator->generate(
            schemas: $allSchemas,
            packageName: $sdkName,
            namespace: $sdkNamespace,
            clientClassName: $sdkClient,
            stubsPath: $stubsPath,
            version: $sdkVersion,
        );
    }

    private function resolveSdkOutputPath(Request $request): string
    {
        $apiConfig = ConfigResolver::resolve($request->input('api'));

        return $request->input('path') ?? $apiConfig->sdkPath;
    }

    private function resolveSchemaClass(string $input, ApiConfig $apiConfig): string
    {
        if (str_contains($input, '\\')) {
            return $input;
        }

        if (! str_ends_with($input, 'Schema')) {
            $input .= 'Schema';
        }

        return "{$apiConfig->schemaNamespace}\\{$input}";
    }

    private function resolveModelName(string $schemaClass): string
    {
        $className = class_basename($schemaClass);

        return Str::beforeLast($className, 'Schema') ?: $className;
    }

    /**
     * Resolve a related model FQCN to its schema class (convention-based).
     */
    private function resolveRelatedSchemaClass(string $modelFqcn): ?string
    {
        // Convention: App\Models\User → App\Schemas\UserSchema
        $parts = explode('\\', $modelFqcn);
        $modelBaseName = array_pop($parts);
        $namespace = implode('\\', $parts);

        // Replace last \Models segment with \Schemas
        $schemaNamespace = preg_replace('/\\\\Models(\\\\|$)/', '\\Schemas$1', $namespace, 1);
        $schemaClass = $schemaNamespace.'\\'.$modelBaseName.'Schema';

        if (class_exists($schemaClass) && is_subclass_of($schemaClass, \SchemaCraft\Schema::class)) {
            return $schemaClass;
        }

        return null;
    }

    private function resolveStubsPath(): string
    {
        $publishedPath = base_path('stubs/schema-craft');

        if (is_dir($publishedPath.'/api')) {
            return $publishedPath;
        }

        return dirname(__DIR__).'/Console/stubs';
    }

    /**
     * Add a new API entry to the config file.
     */
    private function addApiToConfig(
        Filesystem $fs,
        string $configPath,
        string $name,
        string $studlyName,
        string $routeFile,
        string $prefix,
    ): void {
        $configContent = $fs->get($configPath);

        $sdkName = config('app.name', 'my-app');
        $sdkName = Str::kebab($sdkName).'/'.$name.'-sdk';
        $sdkNamespace = Str::studly(config('app.name', 'MyApp')).'\\'.Str::studly($name).'Sdk';
        $clientName = Str::studly($name).'Client';

        $entry = <<<PHP

        '{$name}' => [
            'namespaces' => [
                'controller' => 'App\\\\Http\\\\Controllers\\\\{$studlyName}Api',
                'service'    => 'App\\\\Models\\\\Services',
                'request'    => 'App\\\\Http\\\\Requests\\\\{$studlyName}Api',
                'resource'   => 'App\\\\Resources\\\\{$studlyName}Api',
                'schema'     => 'App\\\\Schemas',
                'model'      => 'App\\\\Models',
            ],
            'routes' => [
                'file'       => '{$routeFile}',
                'prefix'     => '{$prefix}',
                'middleware'  => ['auth:sanctum'],
            ],
            'schemas' => null,
            'sdk' => [
                'path'      => 'packages/{$name}-sdk',
                'name'      => '{$sdkName}',
                'namespace' => '{$sdkNamespace}',
                'client'    => '{$clientName}',
                'version'   => '0.1.0',
            ],
        ],
PHP;

        $lastBracketPos = strrpos($configContent, "    ],\n\n];");

        if ($lastBracketPos !== false) {
            $insertPos = $lastBracketPos + strlen("    ],\n");
            $configContent = substr($configContent, 0, $insertPos).$entry."\n".substr($configContent, $insertPos);
        }

        $fs->put($configPath, $configContent);
    }

    /**
     * Register the route file in bootstrap/app.php.
     */
    private function registerRouteInBootstrap(Filesystem $fs, string $routeFile, string $prefix, string $name): void
    {
        $bootstrapPath = base_path('bootstrap/app.php');

        if (! $fs->exists($bootstrapPath)) {
            return;
        }

        $content = $fs->get($bootstrapPath);

        if (str_contains($content, $routeFile)) {
            return;
        }

        if (! str_contains($content, '->withRouting(')) {
            return;
        }

        $routeRegistration = "\n            \\Illuminate\\Support\\Facades\\Route::middleware('auth:sanctum')\n"
            ."                ->prefix('{$prefix}')\n"
            ."                ->group(base_path('{$routeFile}'));\n";

        if (preg_match('/then:\s*function\s*\(\)\s*\{/s', $content, $matches, \PREG_OFFSET_CAPTURE)) {
            $openBracePos = $matches[0][1] + strlen($matches[0][0]);
            $content = substr($content, 0, $openBracePos).$routeRegistration.substr($content, $openBracePos);
        } else {
            $thenClosure = "        then: function () {\n"
                ."            \\Illuminate\\Support\\Facades\\Route::middleware('auth:sanctum')\n"
                ."                ->prefix('{$prefix}')\n"
                ."                ->group(base_path('{$routeFile}'));\n"
                ."        },\n";

            if (preg_match("/(\s*health:\s*'[^']*',?\s*\n)/", $content, $matches, \PREG_OFFSET_CAPTURE)) {
                $insertPos = $matches[0][1] + strlen($matches[0][0]);
                $content = substr($content, 0, $insertPos).$thenClosure.substr($content, $insertPos);
            }
        }

        $fs->put($bootstrapPath, $content);
    }

    /**
     * Check if Filament panel provider is installed.
     */
    public function filamentInstallStatus(): JsonResponse
    {
        $panelProviderPath = app_path('Providers/Filament/AdminPanelProvider.php');

        return new JsonResponse([
            'installed' => file_exists($panelProviderPath),
            'path' => 'app/Providers/Filament/AdminPanelProvider.php',
        ]);
    }

    /**
     * Run Filament install command.
     */
    public function filamentInstall(): JsonResponse
    {
        $panelProviderPath = app_path('Providers/Filament/AdminPanelProvider.php');

        if (file_exists($panelProviderPath)) {
            return new JsonResponse([
                'success' => true,
                'message' => 'Filament is already installed.',
            ]);
        }

        try {
            Artisan::call('filament:install', [
                '--no-interaction' => true,
            ]);

            $output = Artisan::output();

            return new JsonResponse([
                'success' => true,
                'message' => 'Filament installed successfully.',
                'output' => trim($output),
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Failed to install Filament: '.$e->getMessage(),
            ], 500);
        }
    }
}
