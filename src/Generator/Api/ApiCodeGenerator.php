<?php

namespace SchemaCraft\Generator\Api;

use Illuminate\Support\Str;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Scanner\RelationshipDefinition;
use SchemaCraft\Scanner\TableDefinition;

/**
 * Orchestrates the generation of a full API stack from a TableDefinition:
 * Controller, Service, FormRequests, and Resource.
 */
class ApiCodeGenerator
{
    private const SKIP_COLUMNS = ['id', 'created_at', 'updated_at', 'deleted_at'];

    private const TIMESTAMP_COLUMNS = ['created_at', 'updated_at'];

    private const SOFT_DELETE_COLUMNS = ['deleted_at'];

    public function __construct(
        private string $stubsPath,
    ) {}

    /**
     * Generate all API files for the given schema.
     *
     * @return array<string, GeneratedFile>
     */
    public function generate(
        TableDefinition $table,
        string $modelName,
        string $modelNamespace = 'App\\Models',
        string $controllerNamespace = 'App\\Http\\Controllers\\Api',
        string $serviceNamespace = 'App\\Models\\Services',
        string $requestNamespace = 'App\\Http\\Requests',
        string $resourceNamespace = 'App\\Resources',
        string $schemaNamespace = 'App\\Schemas',
    ): array {
        $context = $this->buildContext(
            $table,
            $modelName,
            $modelNamespace,
            $controllerNamespace,
            $serviceNamespace,
            $requestNamespace,
            $resourceNamespace,
            $schemaNamespace,
        );

        $files = [];

        $files['controller'] = new GeneratedFile(
            path: $this->controllerPath($context),
            content: $this->renderController($context),
        );

        $files['service'] = new GeneratedFile(
            path: $this->servicePath($context),
            content: $this->renderService($context),
        );

        $files['create_request'] = new GeneratedFile(
            path: $this->requestPath($context, 'Create'),
            content: $this->renderCreateRequest($context),
        );

        $files['update_request'] = new GeneratedFile(
            path: $this->requestPath($context, 'Update'),
            content: $this->renderUpdateRequest($context),
        );

        $files['resource'] = new GeneratedFile(
            path: $this->resourcePath($context),
            content: (new ResourceGenerator)->generate(
                $table,
                $context['resourceNamespace'],
            ),
        );

        return $files;
    }

    /**
     * Generate a request file for a new action added to an existing API.
     */
    public function generateAction(
        string $actionName,
        string $modelName,
        string $requestNamespace = 'App\\Http\\Requests',
        ?TableDefinition $table = null,
        string $schemaNamespace = 'App\\Schemas',
        string $actionType = 'update',
    ): GeneratedFile {
        $stub = file_get_contents($this->stubsPath.'/api/action-request.stub');

        $requestClass = ucfirst($actionName).$modelName.'Request';
        $schemaClass = $modelName.'Schema';
        $schemaFqcn = $schemaNamespace.'\\'.$schemaClass;
        $rulesMethod = $actionType === 'create' ? 'createRules' : 'updateRules';

        // Build rule fields from editable columns
        $ruleFields = '';
        if ($table !== null) {
            $editableColumns = $this->getEditableColumns($table);
            $ruleFields = $this->buildRuleFields($editableColumns);
        }

        $content = str_replace(
            [
                '{{ requestNamespace }}',
                '{{ requestClass }}',
                '{{ schemaFqcn }}',
                '{{ schemaClass }}',
                '{{ rulesMethod }}',
                '{{ ruleFields }}',
                '{{ actionName }}',
                '{{ modelClass }}',
            ],
            [
                $requestNamespace,
                $requestClass,
                $schemaFqcn,
                $schemaClass,
                $rulesMethod,
                $ruleFields,
                $actionName,
                $modelName,
            ],
            $stub,
        );

        return new GeneratedFile(
            path: $this->namespaceToPath($requestNamespace, $requestClass),
            content: $this->cleanOutput($content),
        );
    }

    /**
     * Generate only the service file for a given schema.
     */
    public function generateService(
        TableDefinition $table,
        string $modelName,
        string $modelNamespace = 'App\\Models',
        string $serviceNamespace = 'App\\Models\\Services',
    ): GeneratedFile {
        $context = $this->buildContext(
            $table,
            $modelName,
            $modelNamespace,
            controllerNamespace: '',
            serviceNamespace: $serviceNamespace,
            requestNamespace: '',
            resourceNamespace: '',
            schemaNamespace: '',
        );

        return new GeneratedFile(
            path: $this->servicePath($context),
            content: $this->renderService($context),
        );
    }

    /**
     * Render the action route line from the action-route.stub.
     */
    public function renderActionRoute(
        string $httpMethod,
        string $routePrefix,
        string $routeParam,
        string $actionSlug,
        string $actionName,
        string $controllerClass,
    ): string {
        $stub = file_get_contents($this->stubsPath.'/api/action-route.stub');

        return $this->processTemplate($stub, [
            'httpMethod' => $httpMethod,
            'routePrefix' => $routePrefix,
            'routeParam' => $routeParam,
            'actionSlug' => $actionSlug,
            'controllerClass' => $controllerClass,
            'actionName' => $actionName,
        ]);
    }

    /**
     * Render the action controller method from a per-HTTP-method stub.
     *
     * Loads action-controller-method-{$httpMethod}.stub and processes it
     * through the template engine (directives, modifiers, simple vars).
     */
    public function renderActionControllerMethod(
        string $httpMethod,
        string $actionName,
        string $modelName,
        string $modelVariable,
        string $routeParam,
        ?string $requestClass = null,
        ?TableDefinition $table = null,
        ?string $description = null,
    ): string {
        $stubFile = "/api/action-controller-method-{$httpMethod}.stub";
        $stub = file_get_contents($this->stubsPath.$stubFile);

        // Build PHPDoc block with description (default if none provided)
        $desc = $description ?: ucfirst(Str::headline($actionName)).' the '.Str::lower($modelName).'.';
        $context = [
            'actionName' => $actionName,
            'modelClass' => $modelName,
            'modelVariable' => $modelVariable,
            'routeParam' => $routeParam,
            'phpDoc' => "    /**\n     * {$desc}\n     */",
        ];

        if ($requestClass !== null) {
            $context['requestClass'] = $requestClass;
        }

        if (self::methodRequiresRequest($httpMethod) && $table !== null) {
            $context['decodeRequestForMethodProperties'] = $this->buildDecodedRequestProperties($table);
        }

        return $this->processTemplate($stub, $context);
    }

    /**
     * Render the action service method from a per-HTTP-method stub.
     *
     * Loads action-service-method-{$httpMethod}.stub and processes it.
     */
    public function renderActionServiceMethod(
        string $httpMethod,
        string $actionName,
        string $modelName,
        string $modelVariable,
        ?TableDefinition $table = null,
    ): string {
        $stubFile = "/api/action-service-method-{$httpMethod}.stub";
        $stub = file_get_contents($this->stubsPath.$stubFile);

        $context = [
            'actionName' => $actionName,
            'modelClass' => $modelName,
            'modelVariable' => $modelVariable,
        ];

        if (self::methodRequiresRequest($httpMethod) && $table !== null) {
            $context['serviceMethodParams'] = $this->buildServiceMethodParams($table);
            $context['serviceMethodAssignments'] = $this->buildServiceMethodAssignments($table, $modelVariable);
        }

        return $this->processTemplate($stub, $context);
    }

    /**
     * Render an action test method from a per-HTTP-method stub.
     *
     * Loads action-test-method-{$httpMethod}.stub and processes it.
     */
    public function renderActionTestMethod(
        string $httpMethod,
        string $actionName,
        string $modelName,
        string $modelVariable,
        string $routePrefix,
        ?TableDefinition $table = null,
    ): string {
        $stubFile = "/api/action-test-method-{$httpMethod}.stub";
        $stub = file_get_contents($this->stubsPath.$stubFile);

        $factoryClass = $modelName.'Factory';
        $actionSlug = Str::snake($actionName, '-');

        $context = [
            'actionName' => $actionName,
            'modelClass' => $modelName,
            'modelVariable' => $modelVariable,
            'factoryClass' => $factoryClass,
            'routePrefix' => $routePrefix,
            'actionSlug' => $actionSlug,
        ];

        if (self::methodRequiresRequest($httpMethod) && $table !== null) {
            $context['testRequestFields'] = $this->buildTestRequestFields($table, $modelVariable);
            $context['testRelatedFactories'] = $this->buildTestRelatedFactories($table);
        } else {
            $context['testRequestFields'] = '';
            $context['testRelatedFactories'] = '';
        }

        return $this->processTemplate($stub, $context);
    }

    /**
     * Get the FQCN imports needed for FK models referenced in decoded request properties.
     *
     * @return string[]
     */
    public function getDecodedPropertyImports(TableDefinition $table): array
    {
        $imports = [];
        $editableColumns = $this->getEditableColumns($table);

        foreach ($editableColumns as $column) {
            $relationship = $this->findRelationshipForColumn($table, $column->name);

            if ($relationship !== null) {
                $imports[] = $relationship->relatedModel;
            }
        }

        return array_unique($imports);
    }

    /**
     * Check if the given HTTP method requires a custom request class.
     *
     * Only POST and PUT methods generate request classes with validation rules.
     * GET and DELETE actions do not accept request bodies.
     */
    public static function methodRequiresRequest(string $httpMethod): bool
    {
        return in_array(strtolower($httpMethod), ['post', 'put'], true);
    }

    /**
     * Build shared context used across all stub renderings.
     *
     * @return array<string, mixed>
     */
    private function buildContext(
        TableDefinition $table,
        string $modelName,
        string $modelNamespace,
        string $controllerNamespace,
        string $serviceNamespace,
        string $requestNamespace,
        string $resourceNamespace,
        string $schemaNamespace,
    ): array {
        $modelVariable = Str::camel($modelName);
        $schemaClass = $modelName.'Schema';
        $serviceClass = $modelName.'Service';
        $resourceClass = $modelName.'Resource';
        $controllerClass = $modelName.'Controller';
        $createRequestClass = 'Create'.$modelName.'Request';
        $updateRequestClass = 'Update'.$modelName.'Request';
        $routePrefix = Str::snake(Str::pluralStudly($modelName), '-');
        $routeParam = Str::camel($modelName);

        // Determine which columns to include in create/update
        $editableColumns = $this->getEditableColumns($table);

        return [
            'table' => $table,
            'modelName' => $modelName,
            'modelVariable' => $modelVariable,
            'modelFqcn' => $modelNamespace.'\\'.$modelName,
            'modelClass' => $modelName,
            'controllerNamespace' => $controllerNamespace,
            'controllerClass' => $controllerClass,
            'serviceNamespace' => $serviceNamespace,
            'serviceClass' => $serviceClass,
            'serviceFqcn' => $serviceNamespace.'\\'.$serviceClass,
            'requestNamespace' => $requestNamespace,
            'createRequestClass' => $createRequestClass,
            'updateRequestClass' => $updateRequestClass,
            'createRequestFqcn' => $requestNamespace.'\\'.$createRequestClass,
            'updateRequestFqcn' => $requestNamespace.'\\'.$updateRequestClass,
            'resourceNamespace' => $resourceNamespace,
            'resourceClass' => $resourceClass,
            'resourceFqcn' => $resourceNamespace.'\\'.$resourceClass,
            'schemaNamespace' => $schemaNamespace,
            'schemaClass' => $schemaClass,
            'schemaFqcn' => $schemaNamespace.'\\'.$schemaClass,
            'routePrefix' => $routePrefix,
            'routeParam' => $routeParam,
            'editableColumns' => $editableColumns,
        ];
    }

    /**
     * Get columns that should be included in create/update operations.
     * Excludes primary keys, auto-increments, timestamps, and soft-delete columns.
     *
     * @return ColumnDefinition[]
     */
    private function getEditableColumns(TableDefinition $table): array
    {
        $skipSet = array_flip(self::SKIP_COLUMNS);

        if ($table->hasTimestamps) {
            foreach (self::TIMESTAMP_COLUMNS as $col) {
                $skipSet[$col] = true;
            }
        }

        if ($table->hasSoftDeletes) {
            foreach (self::SOFT_DELETE_COLUMNS as $col) {
                $skipSet[$col] = true;
            }
        }

        $columns = [];
        foreach ($table->columns as $column) {
            if ($column->primary || $column->autoIncrement) {
                continue;
            }

            if (isset($skipSet[$column->name])) {
                continue;
            }

            $columns[] = $column;
        }

        return $columns;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function renderController(array $context): string
    {
        $stub = file_get_contents($this->stubsPath.'/api/controller.stub');

        return $this->cleanOutput(str_replace(
            [
                '{{ controllerNamespace }}',
                '{{ modelFqcn }}',
                '{{ serviceFqcn }}',
                '{{ resourceFqcn }}',
                '{{ createRequestFqcn }}',
                '{{ updateRequestFqcn }}',
                '{{ controllerClass }}',
                '{{ routePrefix }}',
                '{{ routeParam }}',
                '{{ resourceClass }}',
                '{{ modelClass }}',
                '{{ modelVariable }}',
                '{{ createRequestClass }}',
                '{{ updateRequestClass }}',
                '{{ serviceClass }}',
            ],
            [
                $context['controllerNamespace'],
                $context['modelFqcn'],
                $context['serviceFqcn'],
                $context['resourceFqcn'],
                $context['createRequestFqcn'],
                $context['updateRequestFqcn'],
                $context['controllerClass'],
                $context['routePrefix'],
                $context['routeParam'],
                $context['resourceClass'],
                $context['modelClass'],
                $context['modelVariable'],
                $context['createRequestClass'],
                $context['updateRequestClass'],
                $context['serviceClass'],
            ],
            $stub,
        ));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function renderService(array $context): string
    {
        $stub = file_get_contents($this->stubsPath.'/api/service.stub');

        $columns = $context['editableColumns'];
        $table = $context['table'];

        return $this->cleanOutput(str_replace(
            [
                '{{ serviceNamespace }}',
                '{{ modelFqcn }}',
                '{{ relatedModelImports }}',
                '{{ serviceClass }}',
                '{{ modelClass }}',
                '{{ modelVariable }}',
                '{{ createParams }}',
                '{{ createAssignments }}',
                '{{ updateParams }}',
                '{{ updateAssignments }}',
            ],
            [
                $context['serviceNamespace'],
                $context['modelFqcn'],
                $this->buildRelatedModelImports($columns, $table),
                $context['serviceClass'],
                $context['modelClass'],
                $context['modelVariable'],
                $this->buildModelMethodParams($columns, $table),
                $this->buildCreateAssignments($columns, $context['modelVariable'], $table),
                $this->buildModelMethodParams($columns, $table),
                $this->buildUpdateAssignments($columns, $context['modelVariable'], $table),
            ],
            $stub,
        ));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function renderCreateRequest(array $context): string
    {
        $stub = file_get_contents($this->stubsPath.'/api/create-request.stub');

        return $this->cleanOutput(str_replace(
            [
                '{{ requestNamespace }}',
                '{{ schemaFqcn }}',
                '{{ requestClass }}',
                '{{ schemaClass }}',
                '{{ ruleFields }}',
            ],
            [
                $context['requestNamespace'],
                $context['schemaFqcn'],
                $context['createRequestClass'],
                $context['schemaClass'],
                $this->buildRuleFields($context['editableColumns']),
            ],
            $stub,
        ));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function renderUpdateRequest(array $context): string
    {
        $stub = file_get_contents($this->stubsPath.'/api/update-request.stub');

        return $this->cleanOutput(str_replace(
            [
                '{{ requestNamespace }}',
                '{{ schemaFqcn }}',
                '{{ requestClass }}',
                '{{ schemaClass }}',
                '{{ ruleFields }}',
            ],
            [
                $context['requestNamespace'],
                $context['schemaFqcn'],
                $context['updateRequestClass'],
                $context['schemaClass'],
                $this->buildRuleFields($context['editableColumns']),
            ],
            $stub,
        ));
    }

    /**
     * Build PHP method parameter list from column definitions.
     *
     * @param  ColumnDefinition[]  $columns
     */
    private function buildMethodParams(array $columns): string
    {
        $params = [];

        foreach ($columns as $column) {
            $type = $this->phpType($column);
            $nullablePrefix = $column->nullable ? '?' : '';
            $default = $column->nullable ? ' = null' : '';

            $params[] = "{$nullablePrefix}{$type} \${$this->paramName($column->name)}{$default}";
        }

        if (count($params) <= 3) {
            return implode(', ', $params);
        }

        return "\n        ".implode(",\n        ", $params).",\n    ";
    }

    /**
     * Build assignment lines for the static create() method.
     * FK columns with BelongsTo relationships use associate().
     *
     * @param  ColumnDefinition[]  $columns
     */
    private function buildCreateAssignments(array $columns, string $modelVariable, ?TableDefinition $table = null): string
    {
        $lines = [];
        foreach ($columns as $column) {
            $relationship = $table ? $this->findRelationshipForColumn($table, $column->name) : null;

            if ($relationship !== null) {
                $paramName = Str::camel($relationship->name);
                $relationMethod = Str::camel($relationship->name);

                if ($relationship->nullable) {
                    $lines[] = "        if (\${$paramName} !== null) {";
                    $lines[] = "            \${$modelVariable}->{$relationMethod}()->associate(\${$paramName});";
                    $lines[] = '        }';
                } else {
                    $lines[] = "        \${$modelVariable}->{$relationMethod}()->associate(\${$paramName});";
                }
            } else {
                $param = $this->paramName($column->name);
                $lines[] = "        \${$modelVariable}->{$column->name} = \${$param};";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Build assignment lines for the instance update() method.
     * FK columns with BelongsTo relationships use associate()/dissociate().
     *
     * @param  ColumnDefinition[]  $columns
     */
    private function buildUpdateAssignments(array $columns, string $modelVariable, ?TableDefinition $table = null): string
    {
        $lines = [];
        foreach ($columns as $column) {
            $relationship = $table ? $this->findRelationshipForColumn($table, $column->name) : null;

            if ($relationship !== null) {
                $paramName = Str::camel($relationship->name);
                $relationMethod = Str::camel($relationship->name);

                if ($relationship->nullable) {
                    $lines[] = "        if (\${$paramName} !== null) {";
                    $lines[] = "            \$this->{$modelVariable}->{$relationMethod}()->associate(\${$paramName});";
                    $lines[] = '        } else {';
                    $lines[] = "            \$this->{$modelVariable}->{$relationMethod}()->dissociate();";
                    $lines[] = '        }';
                } else {
                    $lines[] = "        \$this->{$modelVariable}->{$relationMethod}()->associate(\${$paramName});";
                }
            } else {
                $param = $this->paramName($column->name);
                $lines[] = "        \$this->{$modelVariable}->{$column->name} = \${$param};";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Build PHP method parameter list with model-typed FK columns.
     *
     * FK columns with BelongsTo relationships become Model-typed params (e.g., User $author),
     * while regular columns remain primitive-typed (e.g., string $title).
     *
     * @param  ColumnDefinition[]  $columns
     */
    private function buildModelMethodParams(array $columns, TableDefinition $table): string
    {
        $params = [];

        foreach ($columns as $column) {
            $relationship = $this->findRelationshipForColumn($table, $column->name);

            if ($relationship !== null) {
                $relatedModel = class_basename($relationship->relatedModel);
                $paramName = Str::camel($relationship->name);
                $nullablePrefix = $relationship->nullable ? '?' : '';
                $default = $relationship->nullable ? ' = null' : '';

                $params[] = "{$nullablePrefix}{$relatedModel} \${$paramName}{$default}";
            } else {
                $type = $this->phpType($column);
                $nullablePrefix = $column->nullable ? '?' : '';
                $default = $column->nullable ? ' = null' : '';

                $params[] = "{$nullablePrefix}{$type} \${$this->paramName($column->name)}{$default}";
            }
        }

        if (count($params) <= 3) {
            return implode(', ', $params);
        }

        return "\n        ".implode(",\n        ", $params).",\n    ";
    }

    /**
     * Build use-import lines for related models referenced by BelongsTo relationships.
     *
     * @param  ColumnDefinition[]  $columns
     */
    private function buildRelatedModelImports(array $columns, TableDefinition $table): string
    {
        $imports = [];

        foreach ($columns as $column) {
            $relationship = $this->findRelationshipForColumn($table, $column->name);

            if ($relationship !== null) {
                $imports[] = "use {$relationship->relatedModel};";
            }
        }

        $imports = array_unique($imports);
        sort($imports);

        return empty($imports) ? '' : "\n".implode("\n", $imports);
    }

    /**
     * Build test request field assignments for action test stubs.
     *
     * Follows the same logic as ControllerTestGenerator — editable columns
     * get test values, FK columns reference the factory-created model's ID.
     */
    private function buildTestRequestFields(TableDefinition $table, string $modelVariable): string
    {
        $editableColumns = $this->getEditableColumns($table);
        $lines = [];

        foreach ($editableColumns as $column) {
            $relationship = $this->findRelationshipForColumn($table, $column->name);

            if ($relationship !== null) {
                // FK columns use the existing model's FK value
                $fkName = $relationship->foreignColumn ?? Str::snake($relationship->name).'_id';
                $lines[] = "            '{$fkName}' => \${$modelVariable}->{$fkName},";
            } else {
                $value = $this->testValue($column);
                $lines[] = "            '{$column->name}' => {$value},";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Build related factory creation lines for POST action tests.
     *
     * For POST actions that create new resources, the FK columns need
     * fresh related models created via factories.
     */
    private function buildTestRelatedFactories(TableDefinition $table): string
    {
        $lines = [];

        foreach ($table->relationships as $rel) {
            if ($rel->type !== 'belongsTo') {
                continue;
            }

            $relatedClass = class_basename($rel->relatedModel);
            $relatedFactory = $relatedClass.'Factory';
            $relatedVar = Str::camel($rel->name);
            $lines[] = "        \${$relatedVar} = {$relatedFactory}::createDefault();";
        }

        return empty($lines) ? '' : "\n".implode("\n", $lines);
    }

    /**
     * Generate a PHP test value for a column definition.
     *
     * Matches the output format of ControllerTestGenerator::testValue().
     */
    private function testValue(ColumnDefinition $column): string
    {
        return match ($column->columnType) {
            'string' => "'{$column->name}_test'",
            'text', 'mediumText', 'longText' => "'Test {$column->name} content'",
            'integer', 'bigInteger', 'unsignedBigInteger', 'unsignedInteger' => '1',
            'smallInteger', 'unsignedSmallInteger' => '1',
            'tinyInteger', 'unsignedTinyInteger' => '1',
            'boolean' => 'true',
            'decimal', 'float', 'double' => '9.99',
            'date' => "'2025-01-15'",
            'timestamp', 'dateTime', 'dateTimeTz' => "'2025-01-15 12:00:00'",
            'time', 'timeTz' => "'12:00:00'",
            'json' => '[]',
            'uuid' => "'550e8400-e29b-41d4-a716-446655440000'",
            'ulid' => "'01ARZ3NDEKTSV4RRFFQ69G5FAV'",
            'year' => '2025',
            default => "'test_value'",
        };
    }

    /**
     * Build the indented rule fields list for form requests.
     *
     * @param  ColumnDefinition[]  $columns
     */
    private function buildRuleFields(array $columns): string
    {
        $lines = [];
        foreach ($columns as $column) {
            $lines[] = "            '{$column->name}',";
        }

        return implode("\n", $lines);
    }

    /**
     * Map a ColumnDefinition to its PHP type hint.
     */
    private function phpType(ColumnDefinition $column): string
    {
        return match ($column->columnType) {
            'integer', 'bigInteger', 'smallInteger', 'tinyInteger',
            'unsignedBigInteger', 'unsignedInteger', 'unsignedSmallInteger', 'unsignedTinyInteger' => 'int',
            'boolean' => 'bool',
            'decimal', 'float', 'double' => 'float',
            'json' => 'array',
            'timestamp', 'dateTime', 'dateTimeTz', 'date' => 'string',
            default => 'string',
        };
    }

    /**
     * Convert a snake_case column name to a camelCase parameter name.
     */
    private function paramName(string $columnName): string
    {
        return Str::camel($columnName);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function controllerPath(array $context): string
    {
        return $this->namespaceToPath($context['controllerNamespace'], $context['controllerClass']);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function servicePath(array $context): string
    {
        return $this->namespaceToPath($context['serviceNamespace'], $context['serviceClass']);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function requestPath(array $context, string $prefix): string
    {
        $className = $prefix.$context['modelName'].'Request';

        return $this->namespaceToPath($context['requestNamespace'], $className);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resourcePath(array $context): string
    {
        return $this->namespaceToPath($context['resourceNamespace'], $context['resourceClass']);
    }

    /**
     * Convert a namespace + class name to a relative file path.
     *
     * App\Http\Controllers\Api + PostController => app/Http/Controllers/Api/PostController.php
     */
    private function namespaceToPath(string $namespace, string $className): string
    {
        $relativePath = str_replace('\\', '/', $namespace);

        // Convert App\ to app/ for PSR-4 convention
        if (str_starts_with($relativePath, 'App/')) {
            $relativePath = 'app/'.substr($relativePath, 4);
        }

        return $relativePath.'/'.$className.'.php';
    }

    /**
     * Process a stub template: resolve directives, modifiers, then simple vars.
     *
     * @param  array<string, string>  $context
     */
    private function processTemplate(string $stub, array $context): string
    {
        // Strip the template doc block BEFORE variable replacement so that
        // multi-line values (e.g. {{ phpDoc }} containing "*/") don't break the regex.
        $stub = $this->stripTemplateDocBlock($stub);

        $stub = $this->processDirectives($stub, $context);
        $stub = $this->processModifiers($stub, $context);

        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $stub = str_replace("{{ {$key} }}", $value, $stub);
            }
        }

        return $stub;
    }

    /**
     * Resolve {{ modelResolver:arg1,arg2 }} directives by loading model-resolver.stub.
     *
     * Maps: 1st arg → {{ modelClass }}, 2nd arg → {{ modelId }} (formatted as $snake_id).
     *
     * @param  array<string, string>  $context
     */
    private function processDirectives(string $content, array $context): string
    {
        return preg_replace_callback(
            '/\{\{\s*modelResolver:(\w+),(\w+)\s*\}\}/',
            function ($matches) use ($context) {
                $modelClass = $context[$matches[1]] ?? $matches[1];
                $routeParam = $context[$matches[2]] ?? $matches[2];
                $modelId = '$'.Str::snake($routeParam).'_id';

                return $this->renderModelResolver($modelClass, $modelId);
            },
            $content,
        );
    }

    /**
     * Resolve {{ var:id }} modifiers → Str::snake(value) . '_id'.
     *
     * @param  array<string, string>  $context
     */
    private function processModifiers(string $content, array $context): string
    {
        return preg_replace_callback(
            '/\{\{\s*(\w+):id\s*\}\}/',
            function ($matches) use ($context) {
                $value = $context[$matches[1]] ?? $matches[1];

                return Str::snake($value).'_id';
            },
            $content,
        );
    }

    /**
     * Render a model resolver expression from the model-resolver stub.
     *
     * Used by both processDirectives() (for route model) and
     * buildDecodedRequestProperties() (for FK columns).
     */
    private function renderModelResolver(string $modelClass, string $valueExpression, bool $nullable = false): string
    {
        $stubName = $nullable ? 'model-resolver-nullable.stub' : 'model-resolver.stub';
        $stub = rtrim(file_get_contents($this->stubsPath.'/api/'.$stubName));

        return str_replace(
            ['{{ modelClass }}', '{{ modelId }}'],
            [$modelClass, $valueExpression],
            $stub,
        );
    }

    /**
     * Build the decoded request properties block for controller stubs.
     *
     * Each column becomes an explicit argument: primitives use $request->validated()['col'],
     * FK columns are resolved via the model-resolver stub.
     */
    private function buildDecodedRequestProperties(TableDefinition $table): string
    {
        $editableColumns = $this->getEditableColumns($table);
        $lines = [];

        foreach ($editableColumns as $column) {
            $relationship = $this->findRelationshipForColumn($table, $column->name);

            if ($relationship !== null) {
                $relatedModel = class_basename($relationship->relatedModel);
                $valueExpr = "\$request->validated()['{$column->name}']";
                $lines[] = '            '.$this->renderModelResolver($relatedModel, $valueExpr, $relationship->nullable).',';
            } else {
                $lines[] = "            \$request->validated()['{$column->name}'],";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Build typed method parameters for service method stubs.
     *
     * FK columns become typed model params (e.g., User $author),
     * primitives become typed params (e.g., string $title).
     * Multi-line formatting when more than 3 parameters.
     */
    private function buildServiceMethodParams(TableDefinition $table): string
    {
        $editableColumns = $this->getEditableColumns($table);
        $params = [];

        foreach ($editableColumns as $column) {
            $relationship = $this->findRelationshipForColumn($table, $column->name);

            if ($relationship !== null) {
                $relatedModel = class_basename($relationship->relatedModel);
                $paramName = Str::camel($relationship->name);
                $nullablePrefix = $relationship->nullable ? '?' : '';
                $default = $relationship->nullable ? ' = null' : '';
                $params[] = "{$nullablePrefix}{$relatedModel} \${$paramName}{$default}";
            } else {
                $type = $this->phpType($column);
                $nullablePrefix = $column->nullable ? '?' : '';
                $default = $column->nullable ? ' = null' : '';
                $params[] = "{$nullablePrefix}{$type} \${$this->paramName($column->name)}{$default}";
            }
        }

        if (count($params) <= 3) {
            return implode(', ', $params);
        }

        return "\n        ".implode(",\n        ", $params).",\n    ";
    }

    /**
     * Build relationship-aware assignment lines for action service method stubs.
     *
     * Uses the same associate/dissociate pattern as the main service update method.
     */
    private function buildServiceMethodAssignments(TableDefinition $table, string $modelVariable): string
    {
        $editableColumns = $this->getEditableColumns($table);

        return $this->buildUpdateAssignments($editableColumns, $modelVariable, $table);
    }

    /**
     * Find a belongsTo relationship that owns the given column.
     */
    private function findRelationshipForColumn(TableDefinition $table, string $columnName): ?RelationshipDefinition
    {
        foreach ($table->relationships as $relationship) {
            if ($relationship->type !== 'belongsTo') {
                continue;
            }

            // Match by explicit foreignColumn or by convention: {relationshipName}_id
            $fkColumn = $relationship->foreignColumn ?? Str::snake($relationship->name).'_id';

            if ($fkColumn === $columnName) {
                return $relationship;
            }
        }

        return null;
    }

    /**
     * Clean up output by collapsing excessive blank lines and stripping template doc blocks.
     */
    private function cleanOutput(string $content): string
    {
        $content = $this->stripTemplateDocBlock($content);

        return preg_replace('/\n{3,}/', "\n\n", $content);
    }

    /**
     * Strip the "Template variables" doc block from rendered stub output.
     */
    private function stripTemplateDocBlock(string $content): string
    {
        return preg_replace('/\/\*\*\s*\n\s*\*\s*Template variables:.*?\*\/\s*\n?/s', '', $content);
    }
}
