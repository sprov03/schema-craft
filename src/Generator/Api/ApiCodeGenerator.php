<?php

namespace SchemaCraft\Generator\Api;

use Illuminate\Support\Str;
use SchemaCraft\Scanner\ColumnDefinition;
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
    ): GeneratedFile {
        $stub = file_get_contents($this->stubsPath.'/api/action-request.stub');

        $requestClass = ucfirst($actionName).$modelName.'Request';

        $content = str_replace(
            ['{{ requestNamespace }}', '{{ requestClass }}'],
            [$requestNamespace, $requestClass],
            $stub,
        );

        return new GeneratedFile(
            path: $this->namespaceToPath($requestNamespace, $requestClass),
            content: $this->cleanOutput($content),
        );
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

        return $this->cleanOutput(str_replace(
            [
                '{{ serviceNamespace }}',
                '{{ modelFqcn }}',
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
                $context['serviceClass'],
                $context['modelClass'],
                $context['modelVariable'],
                $this->buildMethodParams($columns),
                $this->buildCreateAssignments($columns, $context['modelVariable']),
                $this->buildMethodParams($columns),
                $this->buildUpdateAssignments($columns, $context['modelVariable']),
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
     *
     * @param  ColumnDefinition[]  $columns
     */
    private function buildCreateAssignments(array $columns, string $modelVariable): string
    {
        $lines = [];
        foreach ($columns as $column) {
            $param = $this->paramName($column->name);
            $lines[] = "        \${$modelVariable}->{$column->name} = \${$param};";
        }

        return implode("\n", $lines);
    }

    /**
     * Build assignment lines for the instance update() method.
     *
     * @param  ColumnDefinition[]  $columns
     */
    private function buildUpdateAssignments(array $columns, string $modelVariable): string
    {
        $lines = [];
        foreach ($columns as $column) {
            $param = $this->paramName($column->name);
            $lines[] = "        \$this->{$modelVariable}->{$column->name} = \${$param};";
        }

        return implode("\n", $lines);
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
     * Clean up output by collapsing excessive blank lines.
     */
    private function cleanOutput(string $content): string
    {
        return preg_replace('/\n{3,}/', "\n\n", $content);
    }
}
