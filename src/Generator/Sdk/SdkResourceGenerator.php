<?php

namespace SchemaCraft\Generator\Sdk;

use Illuminate\Support\Str;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Scanner\TableDefinition;

/**
 * Generates a Resource class for the SDK that provides typed CRUD methods.
 *
 * Each resource maps to one API endpoint group (e.g., PostResource → /posts).
 * Generated code is PHP 7.4 compatible.
 */
class SdkResourceGenerator
{
    private const SKIP_COLUMNS = ['id', 'created_at', 'updated_at', 'deleted_at'];

    private const TIMESTAMP_COLUMNS = ['created_at', 'updated_at'];

    private const SOFT_DELETE_COLUMNS = ['deleted_at'];

    /**
     * Generate the resource class PHP code.
     *
     * @param  string[]  $customActions
     */
    public function generate(
        TableDefinition $table,
        string $resourceNamespace,
        string $dataNamespace,
        string $modelName,
        array $customActions = [],
    ): string {
        $resourceClassName = $modelName.'Resource';
        $dataClassName = $modelName.'Data';
        $routePrefix = Str::snake(Str::pluralStudly($modelName), '-');
        $editableColumns = $this->getEditableColumns($table);

        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = "namespace {$resourceNamespace};";
        $lines[] = '';
        $lines[] = "use {$dataNamespace}\\{$dataClassName};";
        $lines[] = '';
        $lines[] = "class {$resourceClassName}";
        $lines[] = '{';
        $lines[] = '    /** @var SdkConnector */';
        $lines[] = '    private $connector;';
        $lines[] = '';
        $lines[] = '    /**';
        $lines[] = '     * @param SdkConnector $connector';
        $lines[] = '     */';
        $lines[] = '    public function __construct($connector)';
        $lines[] = '    {';
        $lines[] = '        $this->connector = $connector;';
        $lines[] = '    }';

        // list() method
        $lines[] = '';
        $lines[] = '    /**';
        $lines[] = "     * @return {$dataClassName}[]";
        $lines[] = '     */';
        $lines[] = '    public function list()';
        $lines[] = '    {';
        $lines[] = "        \$response = \$this->connector->get('{$routePrefix}');";
        $lines[] = '';
        $lines[] = '        return array_map(function (array $item) {';
        $lines[] = "            return {$dataClassName}::fromArray(\$item);";
        $lines[] = '        }, $response[\'data\']);';
        $lines[] = '    }';

        // get() method
        $lines[] = '';
        $lines[] = '    /**';
        $lines[] = '     * @param int|string $id';
        $lines[] = "     * @return {$dataClassName}";
        $lines[] = '     */';
        $lines[] = '    public function get($id)';
        $lines[] = '    {';
        $lines[] = "        \$response = \$this->connector->get(\"{$routePrefix}/{\$id}\");";
        $lines[] = '';
        $lines[] = "        return {$dataClassName}::fromArray(\$response['data']);";
        $lines[] = '    }';

        // create() method
        $lines[] = '';
        $lines = array_merge($lines, $this->buildCreateMethod($editableColumns, $dataClassName, $routePrefix));

        // update() method
        $lines[] = '';
        $lines = array_merge($lines, $this->buildUpdateMethod($editableColumns, $dataClassName, $routePrefix));

        // delete() method
        $lines[] = '';
        $lines[] = '    /**';
        $lines[] = '     * @param int|string $id';
        $lines[] = '     * @return void';
        $lines[] = '     */';
        $lines[] = '    public function delete($id)';
        $lines[] = '    {';
        $lines[] = "        \$this->connector->delete(\"{$routePrefix}/{\$id}\");";
        $lines[] = '    }';

        // Custom actions
        foreach ($customActions as $action) {
            $lines[] = '';
            $actionSlug = Str::snake($action, '-');
            $lines[] = '    /**';
            $lines[] = '     * @param int|string $id';
            $lines[] = '     * @return void';
            $lines[] = '     */';
            $lines[] = "    public function {$action}(\$id)";
            $lines[] = '    {';
            $lines[] = "        \$this->connector->put(\"{$routePrefix}/{\$id}/{$actionSlug}\", []);";
            $lines[] = '    }';
        }

        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param  ColumnDefinition[]  $columns
     * @return string[]
     */
    private function buildCreateMethod(array $columns, string $dataClassName, string $routePrefix): array
    {
        $lines = [];
        $params = $this->buildMethodParams($columns);
        $dataArray = $this->buildDataArray($columns);
        $phpdocParams = $this->buildPhpDocParams($columns);

        $lines[] = '    /**';
        foreach ($phpdocParams as $param) {
            $lines[] = "     * @param {$param}";
        }
        $lines[] = "     * @return {$dataClassName}";
        $lines[] = '     */';
        $lines[] = "    public function create({$params})";
        $lines[] = '    {';
        $lines[] = '        $response = $this->connector->post(\''.$routePrefix.'\', [';

        foreach ($dataArray as $entry) {
            $lines[] = "            {$entry}";
        }

        $lines[] = '        ]);';
        $lines[] = '';
        $lines[] = "        return {$dataClassName}::fromArray(\$response['data']);";
        $lines[] = '    }';

        return $lines;
    }

    /**
     * @param  ColumnDefinition[]  $columns
     * @return string[]
     */
    private function buildUpdateMethod(array $columns, string $dataClassName, string $routePrefix): array
    {
        $lines = [];
        $params = $this->buildMethodParams($columns);
        $idParam = empty($params) ? '$id' : '$id, '.$params;
        $dataArray = $this->buildDataArray($columns);
        $phpdocParams = $this->buildPhpDocParams($columns);

        $lines[] = '    /**';
        $lines[] = '     * @param int|string $id';
        foreach ($phpdocParams as $param) {
            $lines[] = "     * @param {$param}";
        }
        $lines[] = "     * @return {$dataClassName}";
        $lines[] = '     */';
        $lines[] = "    public function update({$idParam})";
        $lines[] = '    {';
        $lines[] = "        \$response = \$this->connector->put(\"{$routePrefix}/{\$id}\", [";

        foreach ($dataArray as $entry) {
            $lines[] = "            {$entry}";
        }

        $lines[] = '        ]);';
        $lines[] = '';
        $lines[] = "        return {$dataClassName}::fromArray(\$response['data']);";
        $lines[] = '    }';

        return $lines;
    }

    /**
     * Build PHP method parameter list from column definitions (no type hints for 7.4 compat).
     *
     * @param  ColumnDefinition[]  $columns
     */
    private function buildMethodParams(array $columns): string
    {
        $params = [];

        foreach ($columns as $column) {
            $paramName = Str::camel($column->name);

            if ($column->nullable) {
                $params[] = "\${$paramName} = null";
            } else {
                $params[] = "\${$paramName}";
            }
        }

        if (count($params) <= 3) {
            return implode(', ', $params);
        }

        return "\n        ".implode(",\n        ", $params)."\n    ";
    }

    /**
     * Build PHPDoc @param lines for method parameters.
     *
     * @param  ColumnDefinition[]  $columns
     * @return string[]
     */
    private function buildPhpDocParams(array $columns): array
    {
        $params = [];

        foreach ($columns as $column) {
            $type = $this->phpType($column);
            $paramName = Str::camel($column->name);
            $nullSuffix = $column->nullable ? '|null' : '';
            $params[] = "{$type}{$nullSuffix} \${$paramName}";
        }

        return $params;
    }

    /**
     * Build the data array entries for the request body.
     *
     * @param  ColumnDefinition[]  $columns
     * @return string[]
     */
    private function buildDataArray(array $columns): array
    {
        $entries = [];

        foreach ($columns as $column) {
            $paramName = Str::camel($column->name);
            $entries[] = "'{$column->name}' => \${$paramName},";
        }

        return $entries;
    }

    /**
     * Get columns that should be included in create/update operations.
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
     * Map a ColumnDefinition to its PHP type hint.
     */
    private function phpType(ColumnDefinition $column): string
    {
        $typeMap = [
            'integer' => 'int',
            'bigInteger' => 'int',
            'smallInteger' => 'int',
            'tinyInteger' => 'int',
            'unsignedBigInteger' => 'int',
            'unsignedInteger' => 'int',
            'unsignedSmallInteger' => 'int',
            'unsignedTinyInteger' => 'int',
            'boolean' => 'bool',
            'decimal' => 'float',
            'float' => 'float',
            'double' => 'float',
            'json' => 'array',
            'timestamp' => 'string',
            'dateTime' => 'string',
            'dateTimeTz' => 'string',
            'date' => 'string',
        ];

        return $typeMap[$column->columnType] ?? 'string';
    }
}
