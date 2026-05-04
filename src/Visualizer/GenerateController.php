<?php

namespace SchemaCraft\Visualizer;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use SchemaCraft\Config\ApiConfig;
use SchemaCraft\Config\ConfigResolver;
use SchemaCraft\Config\ConnectionConfig;
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
use SchemaCraft\Generator\Sdk\RuntimeRouteScanner;
use SchemaCraft\Generator\Sdk\SdkCustomAction;
use SchemaCraft\Generator\Sdk\SdkGenerator;
use SchemaCraft\Generator\Sdk\SdkSchemaContext;
use SchemaCraft\Generator\StubResolver;
use SchemaCraft\Migration\SchemaDiscovery;
use SchemaCraft\Scanner\ResourceScanner;
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

        $directories = ConfigResolver::schemaDirectories();
        $discovery = new SchemaDiscovery;
        $schemaClasses = $discovery->discover($directories);

        // Count routes per schema using runtime route scanning
        $routeScanner = new RuntimeRouteScanner;
        $routeCounts = $routeScanner->countBySchema(
            controllerNamespace: $apiConfig->controllerNamespace,
            schemaNamespace: $apiConfig->schemaNamespace,
        );

        $schemas = [];
        foreach ($schemaClasses as $schemaClass) {
            $modelName = $this->resolveModelName($schemaClass);
            $controllerPath = $apiConfig->controllerPath($modelName);

            $schemas[] = [
                'class' => $schemaClass,
                'modelName' => $modelName,
                'hasController' => file_exists($controllerPath),
                'hasService' => file_exists($apiConfig->servicePath($modelName)),
                'hasTest' => file_exists($apiConfig->testPath($modelName)),
                'endpointCount' => $routeCounts[$schemaClass] ?? 0,
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
        $schemaClass = $this->resolveSchemaClass($request->query('schema'));
        $modelName = $this->resolveModelName($schemaClass);

        $controllerPath = $apiConfig->controllerPath($modelName);
        $routePrefix = Str::snake(Str::pluralStudly($modelName), '-');
        $routeParam = Str::camel($modelName);

        // Discover routes for this schema at runtime
        $routeScanner = new RuntimeRouteScanner;
        $rawEndpoints = $routeScanner->scanForSchema(
            schemaClass: $schemaClass,
            controllerNamespace: $apiConfig->controllerNamespace,
            schemaNamespace: $apiConfig->schemaNamespace,
        );

        $endpoints = array_values(array_map(fn ($ep) => $this->enrichEndpoint($ep), $rawEndpoints));

        return new JsonResponse([
            'modelName' => $modelName,
            'schemaClass' => $schemaClass,
            'routePrefix' => $routePrefix,
            'routeParam' => $routeParam,
            'hasController' => file_exists($controllerPath),
            'hasService' => file_exists($apiConfig->servicePath($modelName)),
            'hasTest' => file_exists($apiConfig->testPath($modelName)),
            'endpoints' => $endpoints,
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
        $schemaClass = $this->resolveSchemaClass($request->input('schema'));

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
            $absolutePath = base_path($file->path);
            $exists = file_exists($absolutePath);
            $entry = ['path' => $file->path, 'content' => $file->content, 'exists' => $exists];
            if ($exists) {
                $entry['existingContent'] = file_get_contents($absolutePath);
            }
            $previewFiles[] = $entry;
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
        $schemaClass = $this->resolveSchemaClass($request->input('schema'));
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
            'method' => ['sometimes', 'string', 'in:get,post,put,delete'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
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
            'method' => ['sometimes', 'string', 'in:get,post,put,delete'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        return $this->handleAction($request, true, $fs);
    }

    /**
     * Create a service class for an existing API stack.
     */
    public function createService(Request $request, Filesystem $fs): JsonResponse
    {
        $request->validate([
            'schema' => ['required', 'string'],
            'api' => ['sometimes', 'string'],
            'force' => ['sometimes', 'boolean'],
        ]);

        $schemaClass = $this->resolveSchemaClass($request->input('schema'));
        $modelName = $this->resolveModelName($schemaClass);
        $force = $request->boolean('force', false);

        if (! class_exists($schemaClass)) {
            return new JsonResponse(['success' => false, 'message' => "Schema class [{$schemaClass}] not found."], 404);
        }

        $scanner = new SchemaScanner($schemaClass);
        $table = $scanner->scan();

        $connectionConfig = ConfigResolver::resolveForSchema($schemaClass);

        $stubsPath = StubResolver::basePath();
        $generator = new ApiCodeGenerator($stubsPath);

        $file = $generator->generateService(
            table: $table,
            modelName: $modelName,
            modelNamespace: $connectionConfig->modelNamespace,
            serviceNamespace: $connectionConfig->serviceNamespace,
        );

        $absolutePath = base_path($file->path);

        if (! $force && $fs->exists($absolutePath)) {
            return new JsonResponse([
                'success' => false,
                'message' => "Service class already exists at {$file->path}.",
            ], 422);
        }

        $fs->ensureDirectoryExists(dirname($absolutePath));
        $fs->put($absolutePath, $file->content);

        return new JsonResponse([
            'success' => true,
            'message' => "{$modelName}Service created.",
            'path' => $file->path,
        ]);
    }

    /**
     * Create a controller test class for an existing API stack.
     */
    public function createTest(Request $request, Filesystem $fs): JsonResponse
    {
        $request->validate([
            'schema' => ['required', 'string'],
            'api' => ['sometimes', 'string'],
            'force' => ['sometimes', 'boolean'],
        ]);

        $apiConfig = ConfigResolver::resolve($request->input('api'));
        $schemaClass = $this->resolveSchemaClass($request->input('schema'));
        $modelName = $this->resolveModelName($schemaClass);
        $force = $request->boolean('force', false);

        if (! class_exists($schemaClass)) {
            return new JsonResponse(['success' => false, 'message' => "Schema class [{$schemaClass}] not found."], 404);
        }

        $scanner = new SchemaScanner($schemaClass);
        $table = $scanner->scan();

        $connectionConfig = ConfigResolver::resolveForSchema($schemaClass);

        $testGenerator = new ControllerTestGenerator;
        $content = $testGenerator->generate(
            $table,
            $modelName,
            $connectionConfig->modelNamespace,
            routePrefix: $apiConfig->routePrefix,
        );

        $testDir = $apiConfig->testDirectory();
        $testPath = "{$testDir}/{$modelName}ControllerTest.php";
        $absolutePath = base_path($testPath);

        if (! $force && $fs->exists($absolutePath)) {
            return new JsonResponse([
                'success' => false,
                'message' => "Test class already exists at {$testPath}.",
            ], 422);
        }

        $fs->ensureDirectoryExists(dirname($absolutePath));
        $fs->put($absolutePath, $content);

        return new JsonResponse([
            'success' => true,
            'message' => "{$modelName}ControllerTest created.",
            'path' => $testPath,
        ]);
    }

    // ─── Action Import Endpoints ─────────────────────────────────────────

    /**
     * List all schemas with their resources and actions for the given API.
     *
     * All schemas are returned — not just those with actions. Resources are
     * discovered via ResourceScanner (reflection-based, no metadata drift).
     * Each schema's resources include an action indicator when wired via ->endpoint().
     */
    public function availableActions(Request $request): JsonResponse
    {
        $apiConfig = ConfigResolver::resolve($request->query('api'));

        // Discover all schemas
        $directories = ConfigResolver::schemaDirectories();
        $discovery = new SchemaDiscovery;
        $schemaClasses = $discovery->discover($directories);

        // Detect imported actions via runtime route introspection
        $importedActions = [];
        $resourceActionMap = []; // resourceClass => actionClass
        $routeScanner = new RuntimeRouteScanner;
        $registeredRoutes = $routeScanner->scanAll(
            controllerNamespace: $apiConfig->controllerNamespace,
            schemaNamespace: $apiConfig->schemaNamespace,
            routePrefix: $apiConfig->routePrefix,
        );

        foreach ($registeredRoutes['schemas'] as $schemaEndpoints) {
            foreach ($schemaEndpoints as $ep) {
                if ($ep['source'] === 'action' && $ep['actionClass'] !== null) {
                    $importedActions[] = class_basename($ep['actionClass']);
                    if (isset($ep['resourceClass'])) {
                        $resourceActionMap[$ep['resourceClass']] = $ep['actionClass'];
                    }
                }
            }
        }

        foreach ($registeredRoutes['unassigned'] as $ep) {
            if ($ep['source'] === 'action' && $ep['actionClass'] !== null) {
                $importedActions[] = class_basename($ep['actionClass']);
                if (isset($ep['resourceClass'])) {
                    $resourceActionMap[$ep['resourceClass']] = $ep['actionClass'];
                }
            }
        }

        // Scan all resources for this API grouped by schema
        $resourceDir = base_path($this->namespaceToDirectory($apiConfig->resourceNamespace));
        $resourceScanner = new ResourceScanner;
        $resourcesBySchema = $resourceScanner->scanDirectory($apiConfig->resourceNamespace, $resourceDir);

        $groups = [];

        foreach ($schemaClasses as $schemaClass) {
            $modelName = $this->resolveModelName($schemaClass);

            try {
                $connectionConfig = ConfigResolver::resolveForSchema($schemaClass);
            } catch (\Throwable) {
                continue;
            }

            // Discover Action classes for this schema
            $actionsNs = $connectionConfig->actionNamespaceForModel($modelName);
            $actionsDir = base_path(ConnectionConfig::namespaceToDirectory($actionsNs));
            $actions = [];

            if (is_dir($actionsDir)) {
                foreach (glob($actionsDir.'/*Action.php') as $file) {
                    $className = pathinfo($file, PATHINFO_FILENAME);
                    $fqcn = $actionsNs.'\\'.$className;

                    if (! class_exists($fqcn) || ! is_subclass_of($fqcn, \SchemaCraft\Action::class)) {
                        continue;
                    }

                    $definition = (new \SchemaCraft\Scanner\ActionScanner($fqcn))->scan();
                    $meta = $fqcn::meta();

                    $actionRelationships = [];
                    foreach ($definition->nestedParameters() as $nested) {
                        $nr = $nested->nestedRelationship;
                        if ($nr) {
                            $actionRelationships[] = [
                                'name' => $nr->name,
                                'type' => $nr->relationshipType,
                                'relatedModel' => class_basename($nr->relatedModel),
                                'isCollection' => $nr->isCollection,
                            ];
                        }
                    }

                    $actionRules = [];
                    try {
                        $actionInstance = new $fqcn;
                        $actionRules = $actionInstance->rules();
                    } catch (\Throwable) {
                    }

                    $detailedParams = array_map(fn ($p) => [
                        'name' => $p->name,
                        'type' => $p->type,
                        'nullable' => $p->nullable,
                        'isModel' => $p->isModel,
                        'hasDefault' => $p->hasDefault,
                        'default' => $p->default,
                        'columnName' => $p->columnName,
                        'columnType' => $p->columnType,
                        'foreignKeyColumn' => $p->foreignKeyColumn,
                        'isNestedRelationship' => $p->isNestedRelationship,
                    ], $definition->parameters);

                    $httpMethod = $meta?->method ?? 'post';

                    $actions[] = [
                        'class' => $fqcn,
                        'shortName' => $className,
                        'serviceMethod' => $definition->serviceMethod,
                        'httpMethod' => $httpMethod,
                        'label' => $meta?->label ?? Str::headline($definition->serviceMethod),
                        'description' => $definition->description,
                        'imported' => in_array($className, $importedActions),
                        'relationships' => $actionRelationships,
                        'routeSegment' => Str::kebab($definition->serviceMethod),
                        'rules' => $actionRules,
                        'parameters' => $detailedParams,
                    ];
                }
            }

            // Scan schema columns and relationships
            $columns = [];
            $relationships = [];

            try {
                $scanner = new SchemaScanner($schemaClass);
                $table = $scanner->scan();

                foreach ($table->columns as $col) {
                    $columns[] = [
                        'name' => $col->name,
                        'type' => $col->columnType,
                        'nullable' => $col->nullable,
                    ];
                }

                foreach ($table->relationships as $rel) {
                    if ($rel->type === 'belongsTo') {
                        continue;
                    }
                    $relationships[] = [
                        'name' => $rel->name,
                        'type' => $rel->type,
                        'relatedModel' => class_basename($rel->relatedModel),
                        'relatedSchema' => $this->guessSchemaForModel($rel->relatedModel),
                    ];
                }
            } catch (\Throwable) {
            }

            // Build resources list for this schema
            $schemaResources = [];
            foreach ($resourcesBySchema[$schemaClass] ?? [] as $resourceDef) {
                $schemaResources[] = [
                    'class' => $resourceDef->class,
                    'name' => $resourceDef->name,
                    'isManual' => $resourceDef->isManual,
                    'properties' => $resourceDef->properties,
                    'relationships' => $resourceDef->relationships,
                    'computed' => $resourceDef->computed,
                    'fileContents' => $resourceDef->fileContents,
                    'action' => $resourceActionMap[$resourceDef->class] ?? null,
                ];
            }

            // Also include manual resources that couldn't be schema-associated
            foreach ($resourcesBySchema['manual'] ?? [] as $resourceDef) {
                if (str_starts_with($resourceDef->name, $modelName)) {
                    $schemaResources[] = [
                        'class' => $resourceDef->class,
                        'name' => $resourceDef->name,
                        'isManual' => true,
                        'properties' => [],
                        'relationships' => [],
                        'computed' => [],
                        'fileContents' => $resourceDef->fileContents,
                        'action' => $resourceActionMap[$resourceDef->class] ?? null,
                    ];
                }
            }

            // Include schema in groups even when there are no actions
            if (! empty($actions) || ! empty($schemaResources) || true) {
                $groups[] = [
                    'schema' => $schemaClass,
                    'modelName' => $modelName,
                    'actions' => $actions,
                    'columns' => $columns,
                    'relationships' => $relationships,
                    'resources' => $schemaResources,
                    // Legacy field for backward compat
                    'hasResource' => ! empty($schemaResources),
                ];
            }
        }

        return new JsonResponse([
            'groups' => $groups,
            'apiName' => $apiConfig->name,
            'resourceNamespace' => $apiConfig->resourceNamespace,
            'routeFile' => $apiConfig->routeFile,
            'routePrefix' => $apiConfig->routePrefix,
        ]);
    }

    /**
     * Create a new named resource for a schema, independent of any action.
     */
    /**
     * Preview resource generation without writing to disk.
     */
    public function createResourcePreview(Request $request): JsonResponse
    {
        $request->validate([
            'api' => ['required', 'string'],
            'schema' => ['required', 'string'],
            'resourceName' => ['required', 'string', 'regex:/^[A-Za-z][A-Za-z0-9]*Resource$/'],
            'columns' => ['sometimes', 'array'],
            'relationships' => ['sometimes', 'array'],
            'relationshipResources' => ['sometimes', 'array'],
        ]);

        [$relativePath, $content] = $this->buildResourceFileContent($request);

        $absolutePath = base_path($relativePath);
        $exists = file_exists($absolutePath);

        $file = [
            'path' => $relativePath,
            'content' => $content,
            'exists' => $exists,
        ];

        if ($exists) {
            $file['existingContent'] = file_get_contents($absolutePath);
        }

        return new JsonResponse(['success' => true, 'files' => [$file]]);
    }

    /**
     * Write a new resource class to disk.
     */
    public function createResource(Request $request, Filesystem $fs): JsonResponse
    {
        $request->validate([
            'api' => ['required', 'string'],
            'schema' => ['required', 'string'],
            'resourceName' => ['required', 'string', 'regex:/^[A-Za-z][A-Za-z0-9]*Resource$/'],
            'columns' => ['sometimes', 'array'],
            'relationships' => ['sometimes', 'array'],
            'relationshipResources' => ['sometimes', 'array'],
            'force' => ['sometimes', 'boolean'],
        ]);

        [$relativePath, $content] = $this->buildResourceFileContent($request);
        $absolutePath = base_path($relativePath);

        if (! $request->boolean('force') && $fs->exists($absolutePath)) {
            return new JsonResponse([
                'success' => false,
                'message' => basename($relativePath, '.php').' already exists. Use force to overwrite.',
            ], 422);
        }

        $fs->ensureDirectoryExists(dirname($absolutePath));
        $fs->put($absolutePath, $content);

        return new JsonResponse([
            'success' => true,
            'message' => basename($relativePath, '.php').' created.',
            'file' => $relativePath,
        ]);
    }

    /**
     * Build the resource file content and relative path from the request.
     *
     * @return array{string, string} [relativePath, content]
     */
    private function buildResourceFileContent(Request $request): array
    {
        $apiConfig = ConfigResolver::resolve($request->input('api'));
        $schemaClass = $this->resolveSchemaClass($request->input('schema'));
        $resourceName = $request->input('resourceName');
        $resourceDir = $this->namespaceToDirectory($apiConfig->resourceNamespace);
        $relativePath = $resourceDir.'/'.$resourceName.'.php';

        $scanner = new SchemaScanner($schemaClass);
        $table = $scanner->scan();

        $selectedColumns = $request->input('columns');
        $selectedRelationships = $request->input('relationships', []);

        if ($selectedColumns !== null) {
            $table = $this->filterTableColumns($table, $selectedColumns, $selectedRelationships);
        }

        // Map relationship name → selected resource short class name (e.g. 'assignments' → 'AsteriskDidAssignmentLightResource')
        $relationshipResourceOverrides = $request->input('relationshipResources', []);

        $content = (new ResourceGenerator)->generate(
            table: $table,
            resourceNamespace: $apiConfig->resourceNamespace,
            resourceName: $resourceName,
            relationshipResourceOverrides: $relationshipResourceOverrides,
        );

        return [$relativePath, $content];
    }

    /**
     * Get all registered API routes for documentation, using runtime route introspection.
     *
     * Returns routes grouped by schema, with full metadata for action-based,
     * controller-based, and closure-based routes.
     */
    public function apiRoutes(Request $request): JsonResponse
    {
        $apiConfig = ConfigResolver::resolve($request->query('api'));

        $scanner = new RuntimeRouteScanner;
        $result = $scanner->scanAll(
            controllerNamespace: $apiConfig->controllerNamespace,
            schemaNamespace: $apiConfig->schemaNamespace,
            routePrefix: $apiConfig->routePrefix,
        );

        $groups = [];

        foreach ($result['schemas'] as $schemaClass => $endpoints) {
            $modelName = class_basename(str_replace('Schema', '', $schemaClass));

            $enrichedEndpoints = array_map(function ($ep) {
                return $this->enrichEndpoint($ep);
            }, $endpoints);

            $groups[] = [
                'schema' => $schemaClass,
                'modelName' => $modelName,
                'endpoints' => array_values($enrichedEndpoints),
            ];
        }

        // Sort groups by model name
        usort($groups, fn ($a, $b) => strcmp($a['modelName'], $b['modelName']));

        // Enrich unassigned routes too
        $unassigned = array_map(function ($ep) {
            return $this->enrichEndpoint($ep);
        }, $result['unassigned']);

        return new JsonResponse([
            'groups' => $groups,
            'unassigned' => array_values($unassigned),
            'apiName' => $apiConfig->name,
            'routeFile' => $apiConfig->routeFile,
            'routePrefix' => $apiConfig->routePrefix,
        ]);
    }

    /**
     * Enrich an endpoint from RuntimeRouteScanner with parameter metadata and response field docs.
     *
     * Both action and controller endpoints flow through the same response-resolution path so that
     * the API docs and SDK generator consume an identical data structure regardless of source.
     *
     * Response fields are only populated when a resource class is explicitly declared and scannable.
     * Endpoints with no declared resource, or with a manual JsonResource, show no response shape —
     * we do not infer or fabricate a shape from the schema.
     *
     * @param  array<string, mixed>  $endpoint
     * @return array<string, mixed>
     */
    private function enrichEndpoint(array $endpoint): array
    {
        $parameters = [];
        $relationships = [];

        // Action endpoints: extract typed parameters and nested relationship info from the action class
        if ($endpoint['source'] === 'action' && $endpoint['actionClass'] !== null && class_exists($endpoint['actionClass'])) {
            try {
                $definition = (new \SchemaCraft\Scanner\ActionScanner($endpoint['actionClass']))->scan();
                $meta = $endpoint['actionClass']::meta();

                $endpoint['label'] = $meta?->label ?? Str::headline($definition->serviceMethod);
                $endpoint['serviceMethod'] = $definition->serviceMethod;

                $parameters = array_map(fn ($p) => [
                    'name' => $p->name,
                    'type' => $p->type,
                    'nullable' => $p->nullable,
                    'isModel' => $p->isModel,
                    'hasDefault' => $p->hasDefault,
                    'default' => $p->default,
                    'columnName' => $p->columnName,
                    'columnType' => $p->columnType,
                    'foreignKeyColumn' => $p->foreignKeyColumn,
                    'isNestedRelationship' => $p->isNestedRelationship,
                    'morphPairName' => $p->morphPairName,
                    'isMorphType' => $p->isMorphType,
                ], $definition->parameters);

                foreach ($definition->nestedParameters() as $nested) {
                    $nr = $nested->nestedRelationship;
                    if ($nr) {
                        $relationships[] = [
                            'name' => $nr->name,
                            'type' => $nr->relationshipType,
                            'relatedModel' => class_basename($nr->relatedModel),
                            'isCollection' => $nr->isCollection,
                        ];
                    }
                }
            } catch (\Throwable) {
                // Skip enrichment if scan fails
            }
        }

        // Controller endpoints: build canonical parameters from FormRequest rules so the SDK
        // and API docs consume the same parameter shape regardless of endpoint source
        if ($endpoint['source'] === 'controller' && ! empty($endpoint['rules'])) {
            $parameters = $this->buildParametersFromRules($endpoint['rules']);
        }

        // Response resolution — three possible outcomes:
        //   responseFields        — resource declared and introspectable (SchemaCraftResource)
        //   responseManualResource — resource declared but manual JsonResource (opaque toArray())
        //   responseUndocumented  — controller with generic/missing return type (already set by scanner)
        //   (nothing)             — no resource declared at all
        if (! empty($endpoint['responseResource'])) {
            $rr = $endpoint['responseResource'];
            $resourceClass = $rr['resourceClass'] ?? null;

            if ($resourceClass !== null && class_exists($resourceClass)) {
                $endpoint['resolvedResourceClass'] = $resourceClass;
                $endpoint['responseCollection'] = $rr['collection'] ?? false;
                $endpoint['responseModelName'] = str_replace('Resource', '', class_basename($resourceClass));

                $fields = $this->buildResponseFieldsFromResource($resourceClass);

                if ($fields !== null) {
                    $endpoint['responseFields'] = $fields;
                } else {
                    // Resource is declared but uses a manual toArray() — known but not introspectable
                    $endpoint['responseManualResource'] = $resourceClass;
                }
            }

            unset($endpoint['responseResource']);
        }

        $endpoint['parameters'] = $parameters;
        $endpoint['relationships'] = $relationships;
        $endpoint['sourceFiles'] = $this->resolveSourceFiles($endpoint);

        unset($endpoint['controllerClass']);

        return $endpoint;
    }

    /**
     * Resolve the relevant source files for an endpoint.
     *
     * Action routes: Action class + declared Resource class.
     * Controller routes: Controller class + FormRequest + declared Resource class.
     *
     * @return array<int, array{label: string, path: string, content: string}>
     */
    private function resolveSourceFiles(array $endpoint): array
    {
        $files = [];

        if ($endpoint['source'] === 'action') {
            if (! empty($endpoint['actionClass'])) {
                $file = $this->readClassFile($endpoint['actionClass']);
                if ($file !== null) {
                    $files[] = $file;
                }
            }
        } elseif ($endpoint['source'] === 'controller') {
            if (! empty($endpoint['controllerClass'])) {
                $file = $this->readClassFile($endpoint['controllerClass']);
                if ($file !== null) {
                    $files[] = $file;
                }
            }

            if (! empty($endpoint['formRequest'])) {
                $file = $this->readClassFile($endpoint['formRequest']);
                if ($file !== null) {
                    $files[] = $file;
                }
            }
        }

        if (! empty($endpoint['resolvedResourceClass'])) {
            $file = $this->readClassFile($endpoint['resolvedResourceClass']);
            if ($file !== null) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * Read a class file via reflection and return a labelled source entry.
     *
     * @return array{label: string, path: string, content: string}|null
     */
    private function readClassFile(string $class): ?array
    {
        try {
            $filePath = (new \ReflectionClass($class))->getFileName();

            if (! $filePath || ! file_exists($filePath)) {
                return null;
            }

            return [
                'label' => class_basename($class),
                'path' => str_replace(base_path().'/', '', $filePath),
                'content' => file_get_contents($filePath),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Convert FormRequest rules into the canonical parameter shape used by action endpoints.
     *
     * Produces the same array structure as the action parameter map so the SDK generator
     * and API docs can consume controller and action endpoint params identically.
     *
     * @param  array<string, mixed>  $rules
     * @return array<int, array<string, mixed>>
     */
    private function buildParametersFromRules(array $rules): array
    {
        $parameters = [];
        $fieldNames = array_keys($rules);

        foreach ($rules as $field => $fieldRules) {
            // Dot-notation fields (e.g. items.*.id) are sub-fields of a nested param — skip the leaf
            if (str_contains($field, '.')) {
                continue;
            }

            $ruleList = is_array($fieldRules) ? $fieldRules : explode('|', (string) $fieldRules);
            $ruleStrings = array_filter(array_map(fn ($r) => is_string($r) ? $r : null, $ruleList));

            $nullable = in_array('nullable', $ruleStrings, true);
            $type = $this->inferTypeFromRules(array_values($ruleStrings));

            // A field is a nested relationship when it has sub-rules (e.g. 'items' with 'items.*.id')
            $hasSubRules = collect($fieldNames)->contains(fn ($k) => str_starts_with($k, $field.'.'));

            $parameters[] = [
                'name' => $field,
                'type' => $type,
                'nullable' => $nullable,
                'isModel' => false,
                'hasDefault' => false,
                'default' => null,
                'columnName' => $field,
                'columnType' => $type,
                'foreignKeyColumn' => null,
                'isNestedRelationship' => $hasSubRules,
                'morphPairName' => null,
                'isMorphType' => false,
            ];
        }

        return $parameters;
    }

    /**
     * Infer a PHP type string from a list of Laravel validation rule strings.
     */
    private function inferTypeFromRules(array $rules): string
    {
        foreach ($rules as $rule) {
            if ($rule === 'integer' || $rule === 'int') {
                return 'integer';
            }
            if ($rule === 'boolean' || $rule === 'bool') {
                return 'boolean';
            }
            if ($rule === 'numeric' || $rule === 'decimal') {
                return 'float';
            }
            if ($rule === 'array') {
                return 'array';
            }
        }

        return 'string';
    }

    /**
     * Build response field documentation from a SchemaCraftResource class.
     *
     * Reflects on the resource's typed public properties, relationship attributes,
     * and computed methods. Recursively scans related resource classes and embeds
     * their fields under `relatedFields` on each relationship entry.
     *
     * Returns null for manual JsonResource subclasses (toArray() is opaque).
     *
     * @param  string[]  $visited  Resource classes already scanned (prevents infinite recursion)
     * @return array{columns: array, relationships: array}|null
     */
    private function buildResponseFieldsFromResource(string $resourceClass, array $visited = []): ?array
    {
        if (in_array($resourceClass, $visited, true)) {
            return null;
        }

        try {
            $definition = (new ResourceScanner)->scanClass($resourceClass);

            if ($definition->isManual) {
                return null;
            }

            $visited[] = $resourceClass;

            $columns = array_map(fn ($p) => [
                'name' => $p['name'],
                'type' => $p['type'],
                'nullable' => $p['nullable'] ?? false,
            ], $definition->properties);

            foreach ($definition->computed as $c) {
                $columns[] = [
                    'name' => Str::snake($c['name']),
                    'type' => $c['returnType'] ?? 'mixed',
                    'nullable' => false,
                    'computed' => true,
                ];
            }

            $collectionTypes = ['hasMany', 'morphMany', 'morphToMany', 'hasManyThrough'];
            $relationships = array_map(function ($r) use ($collectionTypes, $visited) {
                $rel = [
                    'name' => $r['name'],
                    'type' => $r['type'],
                    'relatedModel' => str_replace('Resource', '', class_basename($r['resource'])),
                    'isCollection' => in_array($r['type'], $collectionTypes, true),
                    'conditional' => true,
                ];

                // Recursively scan the related resource class
                if (class_exists($r['resource'])) {
                    $nested = $this->buildResponseFieldsFromResource($r['resource'], $visited);
                    if ($nested !== null) {
                        $rel['relatedFields'] = $nested;
                    }
                }

                return $rel;
            }, $definition->relationships);

            return ['columns' => $columns, 'relationships' => $relationships];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Import selected Actions into an API — wires them to a selected resource and adds route registration.
     *
     * If resourceClass is provided, that existing resource is used for ->endpoint().
     * If not provided and no resources exist for the schema, a new default resource is auto-generated.
     */
    public function importActions(Request $request, Filesystem $fs): JsonResponse
    {
        $request->validate([
            'api' => ['required', 'string'],
            'actions' => ['required', 'array'],
            'actions.*' => ['string'],
            'schema' => ['required', 'string'],
            'resourceClass' => ['sometimes', 'nullable', 'string'],
            'resource_columns' => ['sometimes', 'array'],
            'resource_relationships' => ['sometimes', 'array'],
        ]);

        $apiConfig = ConfigResolver::resolve($request->input('api'));
        $schemaClass = $this->resolveSchemaClass($request->input('schema'));
        $modelName = $this->resolveModelName($schemaClass);
        $actionClasses = $request->input('actions');
        $selectedColumns = $request->input('resource_columns');
        $selectedRelationships = $request->input('resource_relationships');
        $requestedResourceClass = $request->input('resourceClass');

        $routeFilePath = base_path($apiConfig->routeFile);
        if (! $fs->exists($routeFilePath)) {
            return new JsonResponse([
                'success' => false,
                'message' => "Route file [{$apiConfig->routeFile}] not found.",
            ], 404);
        }

        $createdFiles = [];
        $resourceDir = base_path($this->namespaceToDirectory($apiConfig->resourceNamespace));

        // Determine the resource FQCN to use for ->endpoint()
        if ($requestedResourceClass && class_exists($requestedResourceClass)) {
            // User selected an existing resource from the dropdown
            $resourceFqcn = $requestedResourceClass;
            $resourceShortName = class_basename($resourceFqcn);
        } else {
            // Fall back: auto-generate default resource if none exists
            $resourceShortName = $modelName.'Resource';
            $resourceFqcn = $apiConfig->resourceNamespace.'\\'.$resourceShortName;
            $resourcePath = $resourceDir.'/'.$resourceShortName.'.php';

            if (! $fs->exists($resourcePath)) {
                $scanner = new SchemaScanner($schemaClass);
                $table = $scanner->scan();

                if ($selectedColumns !== null) {
                    $table = $this->filterTableColumns($table, $selectedColumns, $selectedRelationships ?? []);
                }

                $resourceGenerator = new ResourceGenerator;
                $resourceContent = $resourceGenerator->generate(
                    table: $table,
                    resourceNamespace: $apiConfig->resourceNamespace,
                );

                $fs->ensureDirectoryExists($resourceDir);
                $fs->put($resourcePath, $resourceContent);
                $createdFiles[] = str_replace(base_path().'/', '', $resourcePath);
            }
        }

        // Add route registrations for each Action
        $routeContent = $fs->get($routeFilePath);
        $addedActions = [];
        $writer = new ApiFileWriter;

        foreach ($actionClasses as $actionClass) {
            if (! class_exists($actionClass) || ! is_subclass_of($actionClass, \SchemaCraft\Action::class)) {
                continue;
            }

            $shortName = class_basename($actionClass);

            if (str_contains($routeContent, $shortName.'())->endpoint(')) {
                continue;
            }

            $routeContent = $writer->addImport($routeContent, $actionClass);
            $routeContent = $writer->addImport($routeContent, $resourceFqcn);

            $endpointLine = "    (new {$shortName}())->endpoint({$resourceShortName}::class);";
            $routeContent = $this->insertIntoRouteGroup($routeContent, $endpointLine);

            $addedActions[] = $shortName;
        }

        if (! empty($addedActions)) {
            $fs->put($routeFilePath, $routeContent);
        }

        return new JsonResponse([
            'success' => true,
            'message' => 'Imported '.count($addedActions).' action(s) into '.$apiConfig->name.'.',
            'createdFiles' => $createdFiles,
            'addedActions' => $addedActions,
        ]);
    }

    /**
     * Generate Resources for selected schemas (without importing actions).
     *
     * Accepts an explicit resourceName per schema to support multiple resources per schema.
     */
    public function generateResources(Request $request, Filesystem $fs): JsonResponse
    {
        $request->validate([
            'api' => ['required', 'string'],
            'schemas' => ['required', 'array'],
            'schemas.*.class' => ['required', 'string'],
            'schemas.*.resourceName' => ['sometimes', 'string'],
            'schemas.*.columns' => ['sometimes', 'array'],
            'schemas.*.relationships' => ['sometimes', 'array'],
        ]);

        $apiConfig = ConfigResolver::resolve($request->input('api'));
        $resourceDir = base_path($this->namespaceToDirectory($apiConfig->resourceNamespace));
        $createdFiles = [];
        $skipped = [];

        foreach ($request->input('schemas') as $schemaInput) {
            $schemaClass = $this->resolveSchemaClass($schemaInput['class']);
            $modelName = $this->resolveModelName($schemaClass);
            $resourceName = $schemaInput['resourceName'] ?? $modelName.'Resource';
            $resourcePath = $resourceDir.'/'.$resourceName.'.php';

            if ($fs->exists($resourcePath)) {
                $skipped[] = $resourceName;

                continue;
            }

            $scanner = new SchemaScanner($schemaClass);
            $table = $scanner->scan();

            $selectedColumns = $schemaInput['columns'] ?? null;
            $selectedRelationships = $schemaInput['relationships'] ?? null;

            if ($selectedColumns !== null) {
                $table = $this->filterTableColumns($table, $selectedColumns, $selectedRelationships ?? []);
            }

            $resourceGenerator = new ResourceGenerator;
            $resourceContent = $resourceGenerator->generate(
                table: $table,
                resourceNamespace: $apiConfig->resourceNamespace,
                resourceName: $resourceName,
            );

            $fs->ensureDirectoryExists($resourceDir);
            $fs->put($resourcePath, $resourceContent);
            $createdFiles[] = $resourceName.'.php';
        }

        $message = 'Generated '.count($createdFiles).' resource(s).';
        if (! empty($skipped)) {
            $message .= ' Skipped '.count($skipped).' (already exist).';
        }

        return new JsonResponse([
            'success' => true,
            'message' => $message,
            'createdFiles' => $createdFiles,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Insert a line inside the Route::group closure in a route file.
     */
    private function insertIntoRouteGroup(string $content, string $line): string
    {
        // Find the last closing }); in the file (the Route::group closure end)
        $lastClose = strrpos($content, '});');

        if ($lastClose === false) {
            return $content;
        }

        // Insert the line before the closing });
        return substr($content, 0, $lastClose).$line."\n".substr($content, $lastClose);
    }

    /**
     * Filter a TableDefinition to only include selected columns and relationships.
     */
    private function filterTableColumns(
        \SchemaCraft\Scanner\TableDefinition $table,
        array $selectedColumns,
        array $selectedRelationships,
    ): \SchemaCraft\Scanner\TableDefinition {
        $columnSet = array_flip($selectedColumns);
        $relSet = array_flip($selectedRelationships);

        // Always include id, timestamps, soft deletes
        $alwaysInclude = ['id', 'created_at', 'updated_at', 'deleted_at'];
        foreach ($alwaysInclude as $col) {
            $columnSet[$col] = true;
        }

        $filteredColumns = array_filter(
            $table->columns,
            fn ($col) => isset($columnSet[$col->name])
        );

        $filteredRelationships = array_filter(
            $table->relationships,
            fn ($rel) => isset($relSet[$rel->name])
        );

        // Create a new TableDefinition with filtered data
        return new \SchemaCraft\Scanner\TableDefinition(
            schemaClass: $table->schemaClass,
            tableName: $table->tableName,
            connection: $table->connection,
            columns: array_values($filteredColumns),
            relationships: array_values($filteredRelationships),
            compositeIndexes: $table->compositeIndexes,
            hasTimestamps: $table->hasTimestamps,
            hasSoftDeletes: $table->hasSoftDeletes,
            fillable: $table->fillable,
            hidden: $table->hidden,
            with: $table->with,
        );
    }

    /**
     * Guess the schema FQCN for a related model class.
     * e.g., App\Models\CampaignCost → App\Schemas\CampaignCostSchema
     */
    private function guessSchemaForModel(string $modelFqcn): ?string
    {
        $namespace = Str::beforeLast($modelFqcn, '\\');
        $modelName = class_basename($modelFqcn);
        $schemaNamespace = preg_replace('/\\\\Models$/', '\\Schemas', $namespace);
        $candidate = $schemaNamespace.'\\'.$modelName.'Schema';

        return class_exists($candidate) ? $candidate : null;
    }

    /**
     * Convert a namespace to a directory path (relative to base_path).
     */
    private function namespaceToDirectory(string $namespace): string
    {
        $path = str_replace('\\', '/', $namespace);

        if (str_starts_with($path, 'App/')) {
            $path = 'app/'.substr($path, 4);
        }

        return $path;
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

        $result = $this->buildSdkFiles($request);

        if ($result === null) {
            return new JsonResponse(['success' => false, 'message' => 'No schemas with generated API controllers found.'], 422);
        }

        $sdkPath = $this->resolveSdkOutputPath($request);
        $outputDir = base_path($sdkPath);
        $previewFiles = [];
        $generatedRelPaths = [];

        foreach ($result['files'] as $file) {
            $relPath = $sdkPath.'/'.$file->path;
            $absolutePath = base_path($relPath);
            $exists = file_exists($absolutePath);
            $generatedRelPaths[] = $relPath;

            $entry = [
                'path' => $relPath,
                'content' => $file->content,
                'exists' => $exists,
            ];

            if ($exists) {
                $entry['existingContent'] = file_get_contents($absolutePath);
            }

            $previewFiles[] = $entry;
        }

        // Detect files on disk that the generator no longer produces (would be removed)
        if (is_dir($outputDir)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($outputDir, \RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $fsFile) {
                if (! $fsFile->isFile()) {
                    continue;
                }

                $relPath = $sdkPath.'/'.ltrim(str_replace($outputDir, '', $fsFile->getPathname()), DIRECTORY_SEPARATOR);
                $relPath = str_replace('\\', '/', $relPath);

                if (in_array($relPath, $generatedRelPaths, true)) {
                    continue;
                }

                $previewFiles[] = [
                    'path' => $relPath,
                    'content' => '',
                    'exists' => true,
                    'existingContent' => file_get_contents($fsFile->getPathname()),
                    'removed' => true,
                ];
            }
        }

        return new JsonResponse([
            'success' => true,
            'files' => $previewFiles,
            'errors' => $result['errors'],
            'warnings' => $result['warnings'],
        ]);
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
            'removeFiles' => ['sometimes', 'array'],
            'removeFiles.*' => ['string'],
        ]);

        $result = $this->buildSdkFiles($request);
        $removeFiles = $request->input('removeFiles', []);

        if ($result === null) {
            return new JsonResponse(['success' => false, 'message' => 'No schemas with generated API controllers found.'], 422);
        }

        $sdkPath = $this->resolveSdkOutputPath($request);
        $outputPath = base_path($sdkPath);
        $results = [];

        foreach ($result['files'] as $file) {
            $absolutePath = $outputPath.'/'.$file->path;

            $fs->ensureDirectoryExists(dirname($absolutePath));
            $fs->put($absolutePath, $file->content);
            $results[] = ['path' => $sdkPath.'/'.$file->path, 'created' => true, 'message' => basename($file->path).' created.'];
        }

        // Delete files the generator no longer produces (confirmed by the user in the preview modal)
        $deleted = 0;
        foreach ($removeFiles as $relPath) {
            $absolutePath = base_path($relPath);

            if ($fs->exists($absolutePath)) {
                $fs->delete($absolutePath);
                $results[] = ['path' => $relPath, 'deleted' => true, 'message' => basename($relPath).' deleted.'];
                $deleted++;
            }
        }

        $created = count(array_filter($results, fn ($r) => $r['created'] ?? false));
        $parts = array_filter([
            $created ? "{$created} file(s) created" : null,
            $deleted ? "{$deleted} file(s) deleted" : null,
        ]);

        return new JsonResponse([
            'success' => true,
            'files' => $results,
            'message' => implode(', ', $parts) ?: 'No changes.',
            'errors' => $result['errors'],
            'warnings' => $result['warnings'],
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
            $absolutePath = base_path($file->path);
            $exists = file_exists($absolutePath);
            $entry = ['path' => $file->path, 'content' => $file->content, 'exists' => $exists];
            if ($exists) {
                $entry['existingContent'] = file_get_contents($absolutePath);
            }
            $previewFiles[] = $entry;
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

        $stubsPath = StubResolver::basePath();
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

        // Resolve connection-specific namespaces from the schema's $connection
        $connectionConfig = ConfigResolver::resolveForSchema($schemaClass);
        $modelNamespace = $connectionConfig->modelNamespace;
        $serviceNamespace = $connectionConfig->serviceNamespace;
        $schemaNamespace = $connectionConfig->schemaNamespace;

        $stubsPath = StubResolver::basePath();
        $generator = new ApiCodeGenerator($stubsPath);

        $generatedFiles = $generator->generate(
            table: $table,
            modelName: $modelName,
            modelNamespace: $modelNamespace,
            controllerNamespace: $apiConfig->controllerNamespace,
            serviceNamespace: $serviceNamespace,
            requestNamespace: $apiConfig->requestNamespace,
            resourceNamespace: $apiConfig->resourceNamespace,
            schemaNamespace: $schemaNamespace,
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
                content: (new FactoryGenerator)->generate($table, $modelName, $modelNamespace),
            );
        }

        // Tests
        if ($withTests) {
            $generatedFiles['model_test'] = new GeneratedFile(
                path: "tests/Unit/{$modelName}ModelTest.php",
                content: (new ModelTestGenerator)->generate($table, $modelName, $modelNamespace),
            );

            $generatedFiles['controller_test'] = new GeneratedFile(
                path: $apiConfig->testDirectory()."/{$modelName}ControllerTest.php",
                content: (new ControllerTestGenerator)->generate(
                    $table,
                    $modelName,
                    $modelNamespace,
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
        $schemaClass = $this->resolveSchemaClass($request->input('schema'));
        $actionName = $request->input('action');
        $httpMethod = strtolower($request->input('method', 'put'));
        $description = $request->input('description');
        $modelName = $this->resolveModelName($schemaClass);

        // Determine if this HTTP method requires a custom request class
        $needsRequest = ApiCodeGenerator::methodRequiresRequest($httpMethod);

        // Derive action type from HTTP method: post → create, put → update
        $actionType = $httpMethod === 'post' ? 'create' : 'update';

        $controllerPath = $apiConfig->controllerPath($modelName);
        $servicePath = $apiConfig->servicePath($modelName);

        if (! file_exists($controllerPath)) {
            return new JsonResponse(['success' => false, 'message' => 'Controller not found. Generate the API first.'], 422);
        }

        $hasService = file_exists($servicePath);

        // Scan schema for context
        $scanner = new SchemaScanner($schemaClass);
        $table = $scanner->scan();
        $connectionConfig = ConfigResolver::resolveForSchema($schemaClass);

        $fs = $fs ?? new Filesystem;
        $writer = new ApiFileWriter;

        $modelVariable = Str::camel($modelName);
        $routePrefix = Str::snake(Str::pluralStudly($modelName), '-');
        $routeParam = $modelVariable;
        $actionSlug = Str::snake($actionName, '-');
        $controllerClass = $modelName.'Controller';

        $stubsPath = StubResolver::basePath();
        $generator = new ApiCodeGenerator($stubsPath);

        // Generate request file only for POST/PUT methods
        $requestFile = null;
        $requestClass = null;
        $requestFqcn = null;

        if ($needsRequest) {
            $requestClass = ucfirst($actionName).$modelName.'Request';
            $requestFqcn = "{$apiConfig->requestNamespace}\\{$requestClass}";
            $requestFile = $generator->generateAction(
                $actionName,
                $modelName,
                $apiConfig->requestNamespace,
                $table,
                $connectionConfig->schemaNamespace,
                $actionType,
            );
        }

        // Render action fragments from stubs (template engine handles directives/modifiers)
        $renderedRoute = $generator->renderActionRoute(
            $httpMethod,
            $routePrefix,
            $routeParam,
            $actionSlug,
            $actionName,
            $controllerClass,
        );

        $renderedControllerMethod = $generator->renderActionControllerMethod(
            $httpMethod,
            $actionName,
            $modelName,
            $modelVariable,
            $routeParam,
            $needsRequest ? $requestClass : null,
            $table,
            $description,
        );

        $eagerRelationships = $this->detectResourceRelationships($apiConfig, $schemaClass);

        $renderedServiceMethod = $generator->renderActionServiceMethod(
            $httpMethod,
            $actionName,
            $modelName,
            $modelVariable,
            $table,
            $eagerRelationships,
        );

        // Build patched controller content
        $controllerContent = $fs->get($controllerPath);

        if ($needsRequest && $requestFqcn) {
            $controllerContent = $writer->addImport($controllerContent, $requestFqcn);
        }

        // Add FK model imports for decoded request properties
        if ($needsRequest) {
            $fkImports = $generator->getDecodedPropertyImports($table);
            foreach ($fkImports as $fkImport) {
                $controllerContent = $writer->addImport($controllerContent, $fkImport);
            }
        }

        $controllerContent = $writer->addRoute($controllerContent, $renderedRoute);
        $controllerContent = $writer->addControllerMethod($controllerContent, $renderedControllerMethod);

        // Build patched service content (only if service exists)
        $serviceContent = null;
        $serviceRelPath = null;

        if ($hasService) {
            $serviceContent = $fs->get($servicePath);
            $serviceContent = $writer->addServiceMethod($serviceContent, $renderedServiceMethod);
            $serviceRelPath = str_replace(base_path().'/', '', $servicePath);
        }

        // Build patched test content (only if test file exists)
        $testPath = $apiConfig->testPath($modelName);
        $hasTest = file_exists($testPath);
        $testContent = null;
        $testRelPath = null;

        if ($hasTest) {
            $fullRoutePrefix = rtrim($apiConfig->routePrefix, '/').'/'.$routePrefix;
            $renderedTestMethod = $generator->renderActionTestMethod(
                $httpMethod,
                $actionName,
                $modelName,
                $modelVariable,
                $fullRoutePrefix,
                $table,
            );

            $testContent = $fs->get($testPath);
            $testContent = $writer->addTestMethod($testContent, $renderedTestMethod);
            $testRelPath = str_replace(base_path().'/', '', $testPath);
        }

        $controllerRelPath = str_replace(base_path().'/', '', $controllerPath);

        if ($write) {
            $resultFiles = [];

            // Write request file only for POST/PUT
            if ($needsRequest && $requestFile !== null) {
                $requestAbsPath = base_path($requestFile->path);
                $fs->ensureDirectoryExists(dirname($requestAbsPath));
                $fs->put($requestAbsPath, $requestFile->content);
                $resultFiles[] = ['path' => $requestFile->path, 'created' => true];
            }

            // Write patched controller
            $fs->put($controllerPath, $controllerContent);
            $resultFiles[] = ['path' => $controllerRelPath, 'updated' => true];

            // Write patched service if it exists
            if ($hasService && $serviceContent !== null) {
                $fs->put($servicePath, $serviceContent);
                $resultFiles[] = ['path' => $serviceRelPath, 'updated' => true];
            }

            // Write patched test if it exists
            if ($hasTest && $testContent !== null) {
                $fs->put($testPath, $testContent);
                $resultFiles[] = ['path' => $testRelPath, 'updated' => true];
            }

            return new JsonResponse([
                'success' => true,
                'message' => "Action [{$actionName}] added successfully.",
                'files' => $resultFiles,
            ]);
        }

        // Preview mode
        $previewFiles = [];

        if ($needsRequest && $requestFile !== null) {
            $previewFiles[] = ['path' => $requestFile->path, 'content' => $requestFile->content, 'exists' => false];
        }

        $controllerAbsPath = base_path($controllerRelPath);
        $controllerExists = file_exists($controllerAbsPath);
        $controllerEntry = ['path' => $controllerRelPath, 'content' => $controllerContent, 'exists' => $controllerExists];
        if ($controllerExists) {
            $controllerEntry['existingContent'] = file_get_contents($controllerAbsPath);
        }
        $previewFiles[] = $controllerEntry;

        if ($hasService && $serviceContent !== null) {
            $serviceAbsPath = base_path($serviceRelPath);
            $serviceExists = file_exists($serviceAbsPath);
            $serviceEntry = ['path' => $serviceRelPath, 'content' => $serviceContent, 'exists' => $serviceExists];
            if ($serviceExists) {
                $serviceEntry['existingContent'] = file_get_contents($serviceAbsPath);
            }
            $previewFiles[] = $serviceEntry;
        }

        if ($hasTest && $testContent !== null) {
            $testAbsPath = base_path($testRelPath);
            $testExists = file_exists($testAbsPath);
            $testEntry = ['path' => $testRelPath, 'content' => $testContent, 'exists' => $testExists];
            if ($testExists) {
                $testEntry['existingContent'] = file_get_contents($testAbsPath);
            }
            $previewFiles[] = $testEntry;
        }

        return new JsonResponse([
            'success' => true,
            'files' => $previewFiles,
        ]);
    }

    /**
     * Build SDK files for the given API config.
     *
     * Uses RuntimeRouteScanner to discover schemas from registered routes,
     * working with both generated controllers and hand-written APIs.
     *
     * @return array{files: GeneratedFile[], errors: array, warnings: array}|null
     */
    private function buildSdkFiles(Request $request): ?array
    {
        $apiConfig = ConfigResolver::resolve($request->input('api'));
        $directories = ConfigResolver::schemaDirectories();
        $discovery = new SchemaDiscovery;

        $schemaClasses = $discovery->discover($directories);

        if ($apiConfig->schemas !== null) {
            $schemaClasses = array_filter($schemaClasses, function (string $schemaClass) use ($apiConfig) {
                return in_array(class_basename($schemaClass), $apiConfig->schemas);
            });
        }

        // Primary: use RuntimeRouteScanner to find schemas with registered routes
        $routeScanner = new RuntimeRouteScanner;
        $routeData = $routeScanner->scanAll(
            controllerNamespace: $apiConfig->controllerNamespace,
            schemaNamespace: $apiConfig->schemaNamespace,
            routePrefix: $apiConfig->routePrefix ?: null,
        );

        $schemaEndpoints = $routeData['schemas'] ?? [];
        $allowedSchemas = array_flip($schemaClasses);
        $discoveredSchemaClasses = [];

        // Enrich all schema-assigned endpoints (same data the API docs panel uses)
        foreach ($schemaEndpoints as $schemaClass => &$endpointList) {
            $endpointList = array_map(fn ($ep) => $this->enrichEndpoint($ep), $endpointList);
        }
        unset($endpointList);

        // Enrich unassigned endpoints and attempt to resolve their schema via #[ResourceSchema]
        $unassigned = array_map(fn ($ep) => $this->enrichEndpoint($ep), $routeData['unassigned'] ?? []);

        $sdkErrors = [];
        $sdkWarnings = [];

        foreach ($unassigned as $endpoint) {
            $resourceClass = $endpoint['resolvedResourceClass'] ?? null;

            if ($resourceClass === null) {
                $sdkWarnings[] = [
                    'route' => $endpoint['method'].' '.$endpoint['path'],
                    'message' => 'No #[ApiResponse] attribute — add one to include this route in the SDK.',
                ];

                continue;
            }

            $definition = (new ResourceScanner)->scanClass($resourceClass);

            if ($definition->schema === null) {
                $sdkErrors[] = [
                    'route' => $endpoint['method'].' '.$endpoint['path'],
                    'message' => class_basename($resourceClass).' has no #[ResourceSchema] — cannot assign to a schema group.',
                ];

                continue;
            }

            if (! isset($allowedSchemas[$definition->schema])) {
                continue;
            }

            $schemaEndpoints[$definition->schema][] = $endpoint;
        }

        $schemas = [];
        foreach ($schemaEndpoints as $schemaClass => $endpoints) {
            if (! isset($allowedSchemas[$schemaClass])) {
                continue;
            }

            $modelName = $this->resolveModelName($schemaClass);
            $scanner = new SchemaScanner($schemaClass);
            $table = $scanner->scan();

            // Exclude endpoints whose response is undocumented (generic return type / no type hint).
            // These cannot be reliably generated in the SDK.
            $documentedEndpoints = array_values(array_filter($endpoints, fn ($ep) => ! ($ep['responseUndocumented'] ?? false)));

            $customActions = [];
            foreach ($documentedEndpoints as $endpoint) {
                if ($endpoint['type'] === 'custom') {
                    $methods = explode('|', $endpoint['method']);
                    $httpMethod = strtolower($methods[0]);

                    $customActions[] = new SdkCustomAction(
                        name: $endpoint['action'],
                        httpMethod: $httpMethod,
                    );
                }
            }

            // Pick the response fields from the first endpoint that has a resolved resource.
            // This is the exact same data buildResponseFieldsFromResource() produced for the
            // API docs panel — one call, shared result, so SDK and API docs are always in sync.
            $resourceFields = null;
            foreach ($documentedEndpoints as $endpoint) {
                if (isset($endpoint['responseFields'])) {
                    $resourceFields = $endpoint['responseFields'];
                    break;
                }
            }

            $schemas[$modelName] = new SdkSchemaContext(
                table: $table,
                customActions: $customActions,
                endpoints: $documentedEndpoints,
                resourceFields: $resourceFields,
            );
            $discoveredSchemaClasses[$schemaClass] = true;
        }

        // Fallback: check for controller files for schemas not found via routes
        if ($apiConfig->controllerNamespace !== '') {
            $fs = new Filesystem;
            $actionScanner = new ControllerActionScanner;

            foreach ($schemaClasses as $schemaClass) {
                if (isset($discoveredSchemaClasses[$schemaClass])) {
                    continue;
                }

                $modelName = $this->resolveModelName($schemaClass);
                $controllerPath = $apiConfig->controllerPath($modelName);

                if (! $fs->exists($controllerPath)) {
                    continue;
                }

                $scanner = new SchemaScanner($schemaClass);
                $table = $scanner->scan();

                $actionNames = $actionScanner->scanFile($controllerPath);
                $customActions = array_map(
                    fn (string $name) => new SdkCustomAction(name: $name, httpMethod: 'put'),
                    $actionNames,
                );

                $schemas[$modelName] = new SdkSchemaContext(
                    table: $table,
                    customActions: $customActions,
                );
            }
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

        $stubsPath = StubResolver::basePath();
        $generator = new SdkGenerator;

        return [
            'files' => $generator->generate(
                schemas: $allSchemas,
                packageName: $sdkName,
                namespace: $sdkNamespace,
                clientClassName: $sdkClient,
                stubsPath: $stubsPath,
                version: $sdkVersion,
            ),
            'errors' => $sdkErrors ?? [],
            'warnings' => $sdkWarnings ?? [],
        ];
    }

    private function resolveSdkOutputPath(Request $request): string
    {
        $apiConfig = ConfigResolver::resolve($request->input('api'));

        return $request->input('path') ?? $apiConfig->sdkPath;
    }

    private function resolveSchemaClass(string $input): string
    {
        if (str_contains($input, '\\')) {
            return $input;
        }

        if (! str_ends_with($input, 'Schema')) {
            $input .= 'Schema';
        }

        // Search all connection schema namespaces for a matching class
        foreach (ConfigResolver::allConnectionNames() as $name) {
            $config = ConfigResolver::resolveConnection($name);
            $fqcn = "{$config->schemaNamespace}\\{$input}";

            if (class_exists($fqcn)) {
                return $fqcn;
            }
        }

        // Fallback to default namespace
        $default = ConfigResolver::connectionDefaults();

        return "{$default->schemaNamespace}\\{$input}";
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

    /**
     * Detect eager-loadable relationship names from the resource associated with a schema.
     *
     * Scans the API's resource directory for resources linked to the given schema.
     * If exactly one typed SchemaCraftResource is found, returns its relationship property names.
     * Returns an empty array when ambiguous (multiple resources) or none found.
     *
     * @return string[]
     */
    private function detectResourceRelationships(ApiConfig $apiConfig, string $schemaClass): array
    {
        $resourceDir = base_path($this->namespaceToDirectory($apiConfig->resourceNamespace));

        if (! is_dir($resourceDir)) {
            return [];
        }

        $scanner = new ResourceScanner;
        $grouped = $scanner->scanDirectory($apiConfig->resourceNamespace, $resourceDir);
        $resources = $grouped[$schemaClass] ?? [];

        // Only auto-inject eager loading when exactly one typed resource exists
        $typed = array_filter($resources, fn ($r) => ! $r->isManual);

        if (count($typed) !== 1) {
            return [];
        }

        return array_values($typed)[0]->relationshipNames();
    }

    /**
     * Check if Filament panel provider is installed.
     */
    public function filamentInstallStatus(): JsonResponse
    {
        $panelProviderPath = app_path('Providers/Filament/AdminPanelProvider.php');
        $packageInstalled = class_exists(\Filament\FilamentServiceProvider::class);

        return new JsonResponse([
            'installed' => $packageInstalled && file_exists($panelProviderPath),
            'packageInstalled' => $packageInstalled,
            'panelConfigured' => file_exists($panelProviderPath),
            'path' => 'app/Providers/Filament/AdminPanelProvider.php',
        ]);
    }

    /**
     * Run Filament install command, installing the composer package first if needed.
     */
    public function filamentInstall(): JsonResponse
    {
        $panelProviderPath = app_path('Providers/Filament/AdminPanelProvider.php');

        if (class_exists(\Filament\FilamentServiceProvider::class) && file_exists($panelProviderPath)) {
            return new JsonResponse([
                'success' => true,
                'message' => 'Filament is already installed.',
            ]);
        }

        try {
            $output = '';

            // Step 1: Install composer package if not present
            if (! class_exists(\Filament\FilamentServiceProvider::class)) {
                $composerOutput = $this->runProcess(['composer', 'require', 'filament/filament:^5.0', '--no-interaction']);
                $output .= $composerOutput."\n";
            }

            // Step 2: Run filament:install to set up the panel
            Artisan::call('filament:install', [
                '--no-interaction' => true,
            ]);
            $output .= Artisan::output();

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

    /**
     * Run an external process and return its output.
     */
    private function runProcess(array $command): string
    {
        $process = new \Symfony\Component\Process\Process($command, base_path());
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException($process->getErrorOutput() ?: $process->getOutput());
        }

        return $process->getOutput();
    }
}
