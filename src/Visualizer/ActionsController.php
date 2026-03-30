<?php

namespace SchemaCraft\Visualizer;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use SchemaCraft\Action;
use SchemaCraft\Config\ConfigResolver;
use SchemaCraft\Config\ConnectionConfig;
use SchemaCraft\Generator\ActionCodeGenerator;
use SchemaCraft\Generator\ActionFileGenerator;
use SchemaCraft\Generator\Api\ApiCodeGenerator;
use SchemaCraft\Generator\Api\ApiFileWriter;
use SchemaCraft\Generator\Filament\FilamentActionCodeGenerator;
use SchemaCraft\Generator\StubResolver;
use SchemaCraft\Migration\SchemaDiscovery;
use SchemaCraft\Scanner\ActionDefinition;
use SchemaCraft\Scanner\ActionParameter;
use SchemaCraft\Scanner\ActionScanner;
use SchemaCraft\Scanner\NestedFieldDefinition;
use SchemaCraft\Scanner\NestedRelationshipParameter;
use SchemaCraft\Scanner\SchemaScanner;
use SchemaCraft\Scanner\TableDefinition;

class ActionsController
{
    /**
     * List all schemas with their discovered Action classes and columns.
     */
    public function config(): JsonResponse
    {
        $directories = ConfigResolver::schemaDirectories();
        $discovery = new SchemaDiscovery;
        $schemaClasses = $discovery->discover($directories);

        $schemas = [];

        foreach ($schemaClasses as $schemaClass) {
            $modelName = $this->resolveModelName($schemaClass);

            // Resolve connection config for namespace-aware discovery
            try {
                $scanner = new SchemaScanner($schemaClass);
                $table = $scanner->scan();
                $connectionConfig = ConfigResolver::resolveByDatabaseConnection($table->connection);
            } catch (\Throwable) {
                $connectionConfig = ConfigResolver::connectionDefaults();
            }

            // Discover action classes for this model
            $actions = $this->discoverActions($modelName, $connectionConfig->modelNamespace);
            $hasRegistry = $this->hasRegistry($modelName, $connectionConfig->modelNamespace);

            // Scan schema columns for the field picker
            $columns = $this->scanSchemaColumns($schemaClass);

            $schemas[] = [
                'class' => $schemaClass,
                'modelName' => $modelName,
                'actions' => $actions,
                'columns' => $columns,
                'hasRegistry' => $hasRegistry,
            ];
        }

        return new JsonResponse(['schemas' => $schemas]);
    }

    /**
     * Get the full ActionDefinition for a single Action class.
     */
    public function detail(Request $request): JsonResponse
    {
        $request->validate([
            'action' => ['required', 'string'],
        ]);

        $actionClass = $request->query('action');

        if (! class_exists($actionClass) || ! is_subclass_of($actionClass, Action::class)) {
            return new JsonResponse([
                'success' => false,
                'message' => "Action class [{$actionClass}] not found.",
            ], 404);
        }

        $scanner = new ActionScanner($actionClass);
        $definition = $scanner->scan();

        // Generate the service method code preview
        $schemaClass = $definition->schemaClass;
        $modelName = $this->resolveModelName($schemaClass);
        $codeGenerator = new ActionCodeGenerator;
        $serviceMethodCode = $codeGenerator->renderServiceMethod($definition, $modelName);

        // Check if service file and method already exist
        $serviceMethodExists = false;
        try {
            $schemaScanner = new SchemaScanner($schemaClass);
            $table = $schemaScanner->scan();
            $connectionConfig = ConfigResolver::resolveByDatabaseConnection($table->connection);
            $servicePath = $connectionConfig->servicePath($modelName);

            if (file_exists($servicePath)) {
                $serviceContent = file_get_contents($servicePath);
                $serviceMethodExists = str_contains($serviceContent, "function {$definition->serviceMethod}(");
            }
        } catch (\Throwable $e) {
            // Silently ignore — service method existence check is non-critical
        }

        return new JsonResponse([
            'success' => true,
            'actionClass' => $definition->actionClass,
            'name' => $definition->name,
            'httpMethod' => $definition->httpMethod,
            'label' => $definition->label,
            'description' => $definition->description,
            'serviceMethod' => $definition->serviceMethod,
            'serviceMethodCode' => $serviceMethodCode,
            'serviceMethodExists' => $serviceMethodExists,
            'schemaClass' => $definition->schemaClass,
            'parameters' => array_map(fn ($p) => array_filter([
                'name' => $p->name,
                'type' => $p->type,
                'nullable' => $p->nullable,
                'hasDefault' => $p->hasDefault,
                'default' => $p->default,
                'isModel' => $p->isModel,
                'modelClass' => $p->modelClass,
                'foreignKeyColumn' => $p->foreignKeyColumn,
                'columnName' => $p->columnName,
                'relationship' => $p->relationship,
                'isNestedRelationship' => $p->isNestedRelationship ?: null,
                'nestedRelationship' => $p->nestedRelationship !== null ? [
                    'name' => $p->nestedRelationship->name,
                    'relationshipType' => $p->nestedRelationship->relationshipType,
                    'relatedModel' => $p->nestedRelationship->relatedModel,
                    'nullable' => $p->nestedRelationship->nullable,
                    'isCollection' => $p->nestedRelationship->isCollection,
                    'fields' => array_map(fn ($f) => [
                        'name' => $f->name,
                        'dotPath' => $f->dotPath,
                        'type' => $f->type,
                        'nullable' => $f->nullable,
                    ], $p->nestedRelationship->fields),
                    'pivotFields' => $p->nestedRelationship->pivotFields,
                ] : null,
            ], fn ($v) => $v !== null), $definition->parameters),
        ]);
    }

    /**
     * Preview new Action class file(s) from field selection.
     */
    public function createPreview(Request $request): JsonResponse
    {
        $request->validate([
            'schema' => ['required', 'string'],
            'actionName' => ['required', 'string'],
            'selectedColumns' => ['required', 'array', 'min:1'],
            'selectedColumns.*' => ['string'],
            'httpMethod' => ['sometimes', 'string', 'in:get,post,put,delete'],
            'nullableOverrides' => ['sometimes', 'array'],
            'nullableOverrides.*' => ['boolean'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        return $this->handleCreate($request, false);
    }

    /**
     * Write new Action class file(s) from field selection.
     */
    public function create(Request $request, Filesystem $fs): JsonResponse
    {
        $request->validate([
            'schema' => ['required', 'string'],
            'actionName' => ['required', 'string'],
            'selectedColumns' => ['required', 'array', 'min:1'],
            'selectedColumns.*' => ['string'],
            'httpMethod' => ['sometimes', 'string', 'in:get,post,put,delete'],
            'nullableOverrides' => ['sometimes', 'array'],
            'nullableOverrides.*' => ['boolean'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'skipFiles' => ['sometimes', 'array'],
            'skipFiles.*' => ['string'],
        ]);

        return $this->handleCreate($request, true, $fs);
    }

    /**
     * Preview the service method generation (for diff display).
     */
    public function servicePreview(Request $request): JsonResponse
    {
        $request->validate([
            'action' => ['required', 'string'],
        ]);

        $actionClass = $request->query('action');

        if (! class_exists($actionClass) || ! is_subclass_of($actionClass, Action::class)) {
            return new JsonResponse([
                'success' => false,
                'message' => "Action class [{$actionClass}] not found.",
            ], 404);
        }

        $result = $this->buildServicePreview($actionClass);

        return new JsonResponse([
            'success' => true,
            'servicePath' => $result['relPath'],
            'existingContent' => $result['existingContent'],
            'newContent' => $result['newContent'],
            'exists' => $result['exists'],
        ]);
    }

    /**
     * Generate a service method from an existing Action class.
     */
    public function generateService(Request $request, Filesystem $fs): JsonResponse
    {
        $request->validate([
            'action' => ['required', 'string'],
        ]);

        $actionClass = $request->input('action');

        if (! class_exists($actionClass) || ! is_subclass_of($actionClass, Action::class)) {
            return new JsonResponse([
                'success' => false,
                'message' => "Action class [{$actionClass}] not found.",
            ], 404);
        }

        $result = $this->buildServicePreview($actionClass);
        $servicePath = $result['absPath'];

        $fs->ensureDirectoryExists(dirname($servicePath));
        $fs->put($servicePath, $result['newContent']);

        $verb = $result['methodExists'] ? 'Updated' : 'Added';

        return new JsonResponse([
            'success' => true,
            'message' => "{$verb} [{$result['serviceMethod']}] method in [{$result['relPath']}].",
        ]);
    }

    /**
     * Preview updated Action class files from field selection.
     */
    public function updatePreview(Request $request): JsonResponse
    {
        $request->validate([
            'schema' => ['required', 'string'],
            'actionName' => ['required', 'string'],
            'selectedColumns' => ['required', 'array', 'min:1'],
            'selectedColumns.*' => ['string'],
            'httpMethod' => ['sometimes', 'string', 'in:get,post,put,delete'],
            'nullableOverrides' => ['sometimes', 'array'],
            'nullableOverrides.*' => ['boolean'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        return $this->handleCreate($request, false);
    }

    /**
     * Write updated Action class files from field selection.
     */
    public function update(Request $request, Filesystem $fs): JsonResponse
    {
        $request->validate([
            'schema' => ['required', 'string'],
            'actionName' => ['required', 'string'],
            'selectedColumns' => ['required', 'array', 'min:1'],
            'selectedColumns.*' => ['string'],
            'httpMethod' => ['sometimes', 'string', 'in:get,post,put,delete'],
            'nullableOverrides' => ['sometimes', 'array'],
            'nullableOverrides.*' => ['boolean'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'skipFiles' => ['sometimes', 'array'],
            'skipFiles.*' => ['string'],
        ]);

        return $this->handleCreate($request, true, $fs);
    }

    /**
     * Get columns for a related model's schema (for lazy-loading nested fields).
     */
    public function relatedSchema(Request $request): JsonResponse
    {
        $request->validate([
            'model' => ['required', 'string'],
        ]);

        $modelClass = $request->query('model');
        $schemaClass = ActionScanner::resolveRelatedSchemaClass($modelClass);

        if ($schemaClass === null || ! class_exists($schemaClass)) {
            return new JsonResponse([
                'success' => false,
                'message' => "No schema found for model [{$modelClass}].",
            ], 404);
        }

        try {
            $scanner = new SchemaScanner($schemaClass);
            $table = $scanner->scan();
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'message' => "Failed to scan schema: {$e->getMessage()}",
            ], 500);
        }

        $columns = [];

        foreach ($table->columns as $column) {
            if (in_array($column->name, ['created_at', 'updated_at', 'deleted_at'])) {
                continue;
            }

            // Skip FK columns (these are covered by belongsTo relationships)
            $isFk = false;
            foreach ($table->relationships as $rel) {
                if ($rel->type !== 'belongsTo') {
                    continue;
                }
                $fkColumn = $rel->foreignColumn ?? Str::snake($rel->name).'_id';
                if ($fkColumn === $column->name) {
                    $isFk = true;

                    break;
                }
            }

            if ($isFk) {
                continue;
            }

            // Also skip morph type/id columns
            $isMorphColumn = false;
            foreach ($table->relationships as $rel) {
                if ($rel->type !== 'morphTo') {
                    continue;
                }
                $morphName = $rel->morphName ?? $rel->name;
                if ($column->name === $morphName.'_type' || $column->name === $morphName.'_id') {
                    $isMorphColumn = true;

                    break;
                }
            }

            if ($isMorphColumn) {
                continue;
            }

            $columns[] = [
                'name' => $column->name,
                'type' => $column->columnType,
                'nullable' => $column->nullable,
            ];
        }

        // Include non-BelongsTo relationships for deep nesting
        $nestedTypes = ['hasOne', 'hasMany', 'belongsToMany', 'morphOne', 'morphMany', 'morphToMany'];

        foreach ($table->relationships as $rel) {
            if (! in_array($rel->type, $nestedTypes)) {
                continue;
            }

            $isCollection = in_array($rel->type, ['hasMany', 'belongsToMany', 'morphMany', 'morphToMany']);

            $columns[] = [
                'name' => $rel->name,
                'type' => $rel->type,
                'nullable' => $rel->nullable,
                'isNestedRelationship' => true,
                'isCollection' => $isCollection,
                'relatedModel' => $rel->relatedModel,
                'morphName' => $rel->morphName ?? null,
            ];
        }

        return new JsonResponse([
            'success' => true,
            'model' => $modelClass,
            'schema' => $schemaClass,
            'columns' => $columns,
        ]);
    }

    /**
     * Preview the filamentAction() code for an Action class.
     */
    public function filamentActionPreview(Request $request): JsonResponse
    {
        $request->validate([
            'action' => ['required', 'string'],
        ]);

        $actionClass = $request->query('action');

        if (! class_exists($actionClass) || ! is_subclass_of($actionClass, Action::class)) {
            return new JsonResponse([
                'success' => false,
                'message' => "Action class [{$actionClass}] not found.",
            ], 404);
        }

        $scanner = new ActionScanner($actionClass);
        $definition = $scanner->scan();

        $generator = new FilamentActionCodeGenerator;
        $methodCode = $generator->generate($definition);
        $imports = $generator->requiredImports($definition);

        $ref = new \ReflectionClass($actionClass);
        $filePath = $ref->getFileName();
        $existingContent = $filePath ? file_get_contents($filePath) : null;
        $hasMethod = $existingContent !== null && str_contains($existingContent, 'function filamentAction(');

        return new JsonResponse([
            'success' => true,
            'methodCode' => $methodCode,
            'imports' => $imports,
            'filePath' => $filePath ? str_replace(base_path().'/', '', $filePath) : null,
            'hasExistingMethod' => $hasMethod,
            'existingContent' => $existingContent,
        ]);
    }

    /**
     * Publish the filamentAction() method into an Action class file.
     */
    public function publishFilamentAction(Request $request, Filesystem $fs): JsonResponse
    {
        $request->validate([
            'action' => ['required', 'string'],
            'force' => ['sometimes', 'boolean'],
        ]);

        $actionClass = $request->input('action');
        $force = $request->input('force', false);

        if (! class_exists($actionClass) || ! is_subclass_of($actionClass, Action::class)) {
            return new JsonResponse([
                'success' => false,
                'message' => "Action class [{$actionClass}] not found.",
            ], 404);
        }

        $scanner = new ActionScanner($actionClass);
        $definition = $scanner->scan();

        $generator = new FilamentActionCodeGenerator;
        $methodCode = $generator->generate($definition);
        $requiredImports = $generator->requiredImports($definition);

        $ref = new \ReflectionClass($actionClass);
        $filePath = $ref->getFileName();

        if (! $filePath || ! $fs->exists($filePath)) {
            return new JsonResponse([
                'success' => false,
                'message' => "Cannot locate file for [{$actionClass}].",
            ], 404);
        }

        $content = $fs->get($filePath);
        $hasMethod = str_contains($content, 'function filamentAction(');

        if ($hasMethod && ! $force) {
            return new JsonResponse([
                'success' => false,
                'message' => 'filamentAction() already exists. Set force=true to overwrite.',
            ], 409);
        }

        if ($hasMethod) {
            $content = $this->replaceMethod($content, 'filamentAction', $methodCode);
        } else {
            $lastBrace = strrpos($content, '}');
            $content = substr($content, 0, $lastBrace)."\n".$methodCode."\n}\n";
        }

        $writer = new ApiFileWriter;
        foreach ($requiredImports as $import) {
            $content = $writer->addImport($content, $import);
        }

        $fs->put($filePath, $content);

        $relPath = str_replace(base_path().'/', '', $filePath);

        return new JsonResponse([
            'success' => true,
            'message' => "Published filamentAction() to [{$relPath}].",
            'filePath' => $relPath,
            'content' => $content,
        ]);
    }

    private function handleCreate(Request $request, bool $write, ?Filesystem $fs = null): JsonResponse
    {
        $schemaClass = $this->resolveSchemaClass($request->input('schema'));
        $actionName = Str::camel($request->input('actionName'));
        $selectedColumns = $request->input('selectedColumns');
        $httpMethod = strtolower($request->input('httpMethod', 'put'));
        $nullableOverrides = $request->input('nullableOverrides', []);
        $description = $request->input('description');

        if (! class_exists($schemaClass)) {
            return new JsonResponse([
                'success' => false,
                'message' => "Schema class [{$schemaClass}] not found.",
            ], 404);
        }

        $modelName = $this->resolveModelName($schemaClass);

        // Scan schema to get the connection config for namespace resolution
        $scanner = new SchemaScanner($schemaClass);
        $table = $scanner->scan();
        $connectionConfig = ConfigResolver::resolveByDatabaseConnection($table->connection);

        $actionNamespace = $connectionConfig->modelNamespace.'\\Actions\\'.$modelName;
        $schemaNamespace = $connectionConfig->schemaNamespace;

        $stubsPath = StubResolver::basePath();
        $generator = new ActionFileGenerator($stubsPath);

        // Generate the Action class file
        $actionFile = $generator->generateAction(
            actionName: $actionName,
            schemaClass: $schemaClass,
            selectedColumns: $selectedColumns,
            httpMethod: $httpMethod,
            actionNamespace: $actionNamespace,
            schemaNamespace: $schemaNamespace,
            nullableOverrides: $nullableOverrides,
            description: $description,
            serviceNamespace: $connectionConfig->serviceNamespace,
            modelNamespace: $connectionConfig->modelNamespace,
        );

        $files = [];
        $actionAbsPath = base_path($actionFile->path);
        $actionExists = file_exists($actionAbsPath);
        $files[] = [
            'path' => $actionFile->path,
            'content' => $actionFile->content,
            'exists' => $actionExists,
            'existingContent' => $actionExists ? file_get_contents($actionAbsPath) : null,
        ];

        // Check if we should also generate/update the registry
        $existingActions = $this->discoverActions($modelName, $connectionConfig->modelNamespace);
        $actionClassName = ucfirst($actionName).$modelName.'Action';
        $actionFqcn = $actionNamespace.'\\'.$actionClassName;

        // Build action classes map for registry (existing + new)
        $actionClasses = [];
        foreach ($existingActions as $existing) {
            $className = class_basename($existing['class']);
            $baseName = Str::beforeLast($className, 'Action');
            $propName = lcfirst(Str::beforeLast($baseName, $modelName) ?: $baseName);

            $actionClasses[$propName] = $existing['class'];
        }
        $actionClasses[$actionName] = $actionFqcn;

        if (count($actionClasses) > 0) {
            $registryFile = $generator->generateRegistry(
                modelName: $modelName,
                actionClasses: $actionClasses,
                registryNamespace: $actionNamespace,
                schemaNamespace: $schemaNamespace,
            );

            $registryAbsPath = base_path($registryFile->path);
            $registryExists = file_exists($registryAbsPath);
            $files[] = [
                'path' => $registryFile->path,
                'content' => $registryFile->content,
                'exists' => $registryExists,
                'existingContent' => $registryExists ? file_get_contents($registryAbsPath) : null,
            ];
        }

        // Generate the service method file
        $serviceResult = $this->buildServiceFromGeneratorInputs(
            schemaClass: $schemaClass,
            actionName: $actionName,
            actionClassName: $actionClassName,
            actionNamespace: $actionNamespace,
            httpMethod: $httpMethod,
            selectedColumns: $selectedColumns,
            table: $table,
            connectionConfig: $connectionConfig,
            nullableOverrides: $nullableOverrides,
        );

        if ($serviceResult !== null) {
            $serviceAbsPath = base_path($serviceResult['relPath']);
            $serviceExists = file_exists($serviceAbsPath);
            $files[] = [
                'path' => $serviceResult['relPath'],
                'content' => $serviceResult['newContent'],
                'exists' => $serviceExists,
                'existingContent' => $serviceExists ? file_get_contents($serviceAbsPath) : null,
            ];
        }

        if ($write) {
            $fs = $fs ?? new Filesystem;
            $skipFiles = $request->input('skipFiles', []);

            foreach ($files as $file) {
                if (in_array($file['path'], $skipFiles, true)) {
                    continue;
                }
                $absPath = base_path($file['path']);
                $fs->ensureDirectoryExists(dirname($absPath));
                $fs->put($absPath, $file['content']);
            }

            return new JsonResponse([
                'success' => true,
                'message' => "Created {$actionClassName} action.",
                'files' => $files,
            ]);
        }

        return new JsonResponse([
            'success' => true,
            'files' => $files,
        ]);
    }

    /**
     * Discover Action classes for a given model by scanning conventional directories.
     *
     * @return array<int, array{class: string, name: string, httpMethod: string, label: string|null}>
     */
    private function discoverActions(string $modelName, string $modelNamespace): array
    {
        $actions = [];
        $actionsNamespace = $modelNamespace.'\\Actions\\'.$modelName;
        $actionsDir = base_path(ConnectionConfig::namespaceToDirectory($actionsNamespace));

        if (! is_dir($actionsDir)) {
            return $actions;
        }

        $files = glob($actionsDir.'/*Action.php');

        foreach ($files as $file) {
            $className = pathinfo($file, PATHINFO_FILENAME);
            $fqcn = $actionsNamespace.'\\'.$className;

            if (! class_exists($fqcn)) {
                continue;
            }

            if (! is_subclass_of($fqcn, Action::class)) {
                continue;
            }

            try {
                $scanner = new ActionScanner($fqcn);
                $definition = $scanner->scan();

                $actions[] = [
                    'class' => $fqcn,
                    'name' => $definition->name,
                    'httpMethod' => $definition->httpMethod,
                    'label' => $definition->label,
                ];
            } catch (\Throwable) {
                // Skip classes that can't be scanned
            }
        }

        return $actions;
    }

    /**
     * Check if an ActionRegistry exists for a model.
     */
    private function hasRegistry(string $modelName, string $modelNamespace): bool
    {
        $registryClass = "{$modelNamespace}\\Actions\\{$modelName}\\{$modelName}Actions";

        return class_exists($registryClass);
    }

    /**
     * Scan schema columns for the field picker UI.
     *
     * @return array<int, array{name: string, type: string, nullable: bool, isRelationship: bool, relatedModel: string|null}>
     */
    private function scanSchemaColumns(string $schemaClass): array
    {
        if (! class_exists($schemaClass)) {
            return [];
        }

        try {
            $scanner = new SchemaScanner($schemaClass);
            $table = $scanner->scan();
        } catch (\Throwable) {
            return [];
        }

        $columns = [];

        // Add regular columns (skip auto-increment PKs and timestamps managed by traits)
        foreach ($table->columns as $column) {
            if ($column->autoIncrement) {
                continue;
            }

            // Skip timestamp columns (created_at, updated_at, deleted_at)
            if (in_array($column->name, ['created_at', 'updated_at', 'deleted_at'])) {
                continue;
            }

            // Check if this column is a FK for a relationship
            $isRelationship = false;
            $relatedModel = null;

            foreach ($table->relationships as $rel) {
                if ($rel->type !== 'belongsTo') {
                    continue;
                }

                $fkColumn = $rel->foreignColumn ?? Str::snake($rel->name).'_id';
                if ($fkColumn === $column->name) {
                    $isRelationship = true;
                    $relatedModel = $rel->relatedModel;

                    break;
                }
            }

            $columns[] = [
                'name' => $column->name,
                'type' => $column->columnType,
                'nullable' => $column->nullable,
                'isRelationship' => $isRelationship,
                'relatedModel' => $relatedModel,
            ];
        }

        // Add non-BelongsTo relationships as expandable nested entries
        $nestedTypes = ['hasOne', 'hasMany', 'belongsToMany', 'morphOne', 'morphMany', 'morphToMany'];

        foreach ($table->relationships as $rel) {
            if (! in_array($rel->type, $nestedTypes)) {
                continue;
            }

            $isCollection = in_array($rel->type, ['hasMany', 'belongsToMany', 'morphMany', 'morphToMany']);

            $columns[] = [
                'name' => $rel->name,
                'type' => $rel->type,
                'nullable' => $rel->nullable,
                'isRelationship' => true,
                'isNestedRelationship' => true,
                'isCollection' => $isCollection,
                'relatedModel' => $rel->relatedModel,
                'morphName' => $rel->morphName ?? null,
            ];
        }

        return $columns;
    }

    private function resolveSchemaClass(string $input): string
    {
        if (str_contains($input, '\\')) {
            return $input;
        }

        if (! str_ends_with($input, 'Schema')) {
            $input .= 'Schema';
        }

        foreach (ConfigResolver::allConnectionNames() as $name) {
            $config = ConfigResolver::resolveConnection($name);
            $fqcn = "{$config->schemaNamespace}\\{$input}";

            if (class_exists($fqcn)) {
                return $fqcn;
            }
        }

        $default = ConfigResolver::connectionDefaults();

        return "{$default->schemaNamespace}\\{$input}";
    }

    /**
     * Build a service file preview with existing and new content.
     *
     * @return array{absPath: string, relPath: string, existingContent: ?string, newContent: string, exists: bool, methodExists: bool, serviceMethod: string}
     */
    /**
     * Build the service method file content from generator inputs (before the action class exists).
     *
     * @param  string[]  $selectedColumns
     * @param  array<string, bool>  $nullableOverrides
     * @return array{relPath: string, newContent: string}|null
     */
    private function buildServiceFromGeneratorInputs(
        string $schemaClass,
        string $actionName,
        string $actionClassName,
        string $actionNamespace,
        string $httpMethod,
        array $selectedColumns,
        TableDefinition $table,
        ConnectionConfig $connectionConfig,
        array $nullableOverrides = [],
    ): ?array {
        $modelName = $this->resolveModelName($schemaClass);

        // Build an ActionDefinition from the raw inputs (no class file needed)
        $definition = $this->buildActionDefinitionFromInputs(
            actionClassName: $actionClassName,
            actionNamespace: $actionNamespace,
            actionName: $actionName,
            httpMethod: $httpMethod,
            schemaClass: $schemaClass,
            selectedColumns: $selectedColumns,
            table: $table,
            nullableOverrides: $nullableOverrides,
        );

        $prefixedModel = $connectionConfig->prefixedModelName($modelName);
        $relPath = $connectionConfig->serviceDirectory().'/'.$prefixedModel.'Service.php';
        $absPath = base_path($relPath);
        $exists = file_exists($absPath);
        $existingContent = $exists ? file_get_contents($absPath) : null;

        // If service file doesn't exist, create it
        if (! $exists) {
            $apiGenerator = new ApiCodeGenerator(StubResolver::basePath());
            $serviceFile = $apiGenerator->generateService(
                $table,
                $modelName,
                serviceNamespace: $connectionConfig->serviceNamespace,
            );
            $existingContent = $serviceFile->content;
        }

        $codeGenerator = new ActionCodeGenerator;
        $renderedMethod = $codeGenerator->renderServiceMethod($definition, $modelName);

        $writer = new ApiFileWriter;
        $methodExists = str_contains($existingContent, "function {$definition->serviceMethod}(");

        if ($methodExists) {
            $newContent = $this->replaceMethod($existingContent, $definition->serviceMethod, $renderedMethod);
        } else {
            $newContent = $writer->addServiceMethod($existingContent, $renderedMethod);
        }

        // Add FK model imports
        foreach ($definition->parameters as $param) {
            if ($param->isModel && $param->modelClass !== null) {
                $newContent = $writer->addImport($newContent, $param->modelClass);
            }
        }

        return [
            'relPath' => $relPath,
            'newContent' => $newContent,
        ];
    }

    /**
     * Build an ActionDefinition from raw inputs (for service method generation before class exists).
     *
     * @param  string[]  $selectedColumns
     * @param  array<string, bool>  $nullableOverrides
     */
    private function buildActionDefinitionFromInputs(
        string $actionClassName,
        string $actionNamespace,
        string $actionName,
        string $httpMethod,
        string $schemaClass,
        array $selectedColumns,
        TableDefinition $table,
        array $nullableOverrides = [],
    ): ActionDefinition {
        $parameters = [];

        // Partition columns
        $flat = [];
        $nested = [];
        foreach ($selectedColumns as $column) {
            if (str_contains($column, '.')) {
                $normalized = str_replace('.*.', '.', $column);
                $segments = explode('.', $normalized, 2);
                $nested[$segments[0]][] = $segments[1];
            } else {
                $flat[] = $column;
            }
        }

        // Build flat parameters
        foreach ($flat as $columnName) {
            $relationship = null;
            foreach ($table->relationships as $rel) {
                if ($rel->type !== 'belongsTo') {
                    continue;
                }
                $fkColumn = $rel->foreignColumn ?? Str::snake($rel->name).'_id';
                if ($fkColumn === $columnName) {
                    $relationship = $rel;

                    break;
                }
            }

            if ($relationship !== null) {
                $isNullable = array_key_exists($columnName, $nullableOverrides)
                    ? $nullableOverrides[$columnName]
                    : $relationship->nullable;

                $parameters[] = new ActionParameter(
                    name: Str::camel($relationship->name),
                    type: class_basename($relationship->relatedModel),
                    nullable: $isNullable,
                    isModel: true,
                    modelClass: $relationship->relatedModel,
                    foreignKeyColumn: $columnName,
                    relationship: $relationship->name,
                );
            } else {
                $column = null;
                foreach ($table->columns as $col) {
                    if ($col->name === $columnName) {
                        $column = $col;

                        break;
                    }
                }

                if ($column !== null) {
                    $phpType = match ($column->columnType) {
                        'integer', 'bigInteger', 'smallInteger', 'tinyInteger',
                        'unsignedBigInteger', 'unsignedInteger', 'unsignedSmallInteger', 'unsignedTinyInteger' => 'int',
                        'boolean' => 'bool',
                        'decimal', 'float', 'double' => 'float',
                        'json' => 'array',
                        default => 'string',
                    };
                    $isNullable = array_key_exists($columnName, $nullableOverrides)
                        ? $nullableOverrides[$columnName]
                        : $column->nullable;

                    $parameters[] = new ActionParameter(
                        name: Str::camel($column->name),
                        type: $phpType,
                        nullable: $isNullable,
                        columnName: $column->name,
                    );
                }
            }
        }

        // Build nested parameters
        foreach ($nested as $relName => $fields) {
            $rel = null;
            foreach ($table->relationships as $r) {
                if ($r->name === $relName) {
                    $rel = $r;

                    break;
                }
            }
            if ($rel === null) {
                continue;
            }

            $isCollection = in_array($rel->type, ['hasMany', 'belongsToMany', 'morphMany', 'morphToMany']);
            $nestedFields = [];
            foreach ($fields as $field) {
                $nestedFields[] = new NestedFieldDefinition(
                    name: $field,
                    dotPath: $relName.'.'.$field,
                    type: 'string',
                );
            }

            $nestedParam = new NestedRelationshipParameter(
                name: $relName,
                relationshipType: $rel->type,
                relatedModel: $rel->relatedModel,
                nullable: $rel->nullable,
                isCollection: $isCollection,
                fields: $nestedFields,
                pivotFields: [],
            );

            $parameters[] = new ActionParameter(
                name: $relName,
                type: 'array',
                nullable: $rel->nullable,
                isNestedRelationship: true,
                nestedRelationship: $nestedParam,
            );
        }

        return new ActionDefinition(
            actionClass: $actionNamespace.'\\'.$actionClassName,
            name: $actionName,
            httpMethod: $httpMethod,
            serviceMethod: $actionName,
            schemaClass: $schemaClass,
            parameters: $parameters,
            isStatic: strtolower($httpMethod) === 'post',
        );
    }

    private function buildServicePreview(string $actionClass): array
    {
        $scanner = new ActionScanner($actionClass);
        $definition = $scanner->scan();

        $schemaClass = $definition->schemaClass;
        $modelName = $this->resolveModelName($schemaClass);

        $schemaScanner = new SchemaScanner($schemaClass);
        $table = $schemaScanner->scan();
        $connectionConfig = ConfigResolver::resolveByDatabaseConnection($table->connection);
        $prefixedModel = $connectionConfig->prefixedModelName($modelName);
        $servicePath = $connectionConfig->serviceDirectory().'/'.$prefixedModel.'Service.php';

        $absPath = base_path($servicePath);
        $exists = file_exists($absPath);
        $existingContent = $exists ? file_get_contents($absPath) : null;

        // If service file doesn't exist, create it first
        if (! $exists) {
            $apiGenerator = new ApiCodeGenerator(StubResolver::basePath());
            $serviceFile = $apiGenerator->generateService(
                $table,
                $modelName,
                serviceNamespace: $connectionConfig->serviceNamespace,
            );
            $existingContent = $serviceFile->content;
        }

        $generator = new ActionCodeGenerator;
        $renderedMethod = $generator->renderServiceMethod($definition, $modelName);

        $writer = new ApiFileWriter;
        $methodExists = str_contains($existingContent, "function {$definition->serviceMethod}(");

        if ($methodExists) {
            // Replace existing method
            $newContent = $this->replaceMethod($existingContent, $definition->serviceMethod, $renderedMethod);
        } else {
            // Add new method
            $newContent = $writer->addServiceMethod($existingContent, $renderedMethod);
        }

        // Add FK model imports
        foreach ($definition->parameters as $param) {
            if ($param->isModel && $param->modelClass !== null) {
                $newContent = $writer->addImport($newContent, $param->modelClass);
            }
        }

        return [
            'absPath' => $absPath,
            'relPath' => $servicePath,
            'existingContent' => $exists ? file_get_contents($absPath) : null,
            'newContent' => $newContent,
            'exists' => $exists,
            'methodExists' => $methodExists,
            'serviceMethod' => $definition->serviceMethod,
        ];
    }

    /**
     * Replace an existing method in a PHP class with new content.
     */
    private function replaceMethod(string $content, string $methodName, string $newMethod): string
    {
        // Find the method start (public/protected/private + static? + function name)
        $pattern = '/(\n\s*)((?:\/\*\*[\s\S]*?\*\/\s*)?(?:public|protected|private)\s+(?:static\s+)?function\s+'.$methodName.'\s*\()/';

        if (! preg_match($pattern, $content, $match, PREG_OFFSET_CAPTURE)) {
            // Method not found, append instead
            $lastBrace = strrpos($content, '}');

            return substr($content, 0, $lastBrace).$newMethod."\n}\n";
        }

        // Find the start of the method (including any preceding docblock)
        $methodStart = $match[2][1];

        // Find the end of the method by tracking brace depth
        $braceDepth = 0;
        $inMethod = false;
        $methodEnd = $methodStart;

        for ($i = $methodStart; $i < strlen($content); $i++) {
            if ($content[$i] === '{') {
                $braceDepth++;
                $inMethod = true;
            } elseif ($content[$i] === '}') {
                $braceDepth--;
                if ($inMethod && $braceDepth === 0) {
                    $methodEnd = $i + 1;

                    break;
                }
            }
        }

        // Replace the old method with the new one
        return substr($content, 0, $methodStart).$newMethod.substr($content, $methodEnd);
    }

    private function resolveModelName(string $schemaClass): string
    {
        $className = class_basename($schemaClass);

        return Str::beforeLast($className, 'Schema') ?: $className;
    }
}
