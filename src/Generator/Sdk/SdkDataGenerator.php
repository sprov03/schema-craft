<?php

namespace SchemaCraft\Generator\Sdk;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Support\Str;
use ReflectionEnum;
use RuntimeException;
use SchemaCraft\Contracts\SchemaCraftColumn;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Scanner\RelationshipDefinition;
use SchemaCraft\Scanner\TableDefinition;

/**
 * Generates a Data Transfer Object (DTO) class from a TableDefinition.
 *
 * The DTO represents the JSON response shape from the API resource,
 * with typed properties and a static fromArray() factory.
 * Generated code is PHP 7.4 compatible.
 */
class SdkDataGenerator
{
    private const TIMESTAMP_COLUMNS = ['created_at', 'updated_at'];

    private const SOFT_DELETE_COLUMNS = ['deleted_at'];

    private const COLLECTION_RELATIONSHIPS = ['hasMany', 'belongsToMany', 'morphMany', 'morphToMany'];

    private const SINGULAR_RELATIONSHIPS = ['hasOne', 'morphOne'];

    /**
     * Generate the DTO class PHP code.
     */
    public function generate(
        TableDefinition $table,
        string $dataNamespace,
        string $modelName,
    ): string {
        $dataClassName = $modelName.'Data';
        $properties = $this->buildProperties($table, $dataNamespace);
        $constructorAssignments = $this->buildConstructorAssignments($table, $dataNamespace);
        $constructorParams = $this->buildConstructorParams($table, $dataNamespace);
        $fromArrayAssignments = $this->buildFromArrayAssignments($table, $dataNamespace);

        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = "namespace {$dataNamespace};";
        $lines[] = '';
        $lines[] = "class {$dataClassName}";
        $lines[] = '{';

        // Property declarations
        foreach ($properties as $prop) {
            $lines[] = "    {$prop}";
            $lines[] = '';
        }

        // Constructor
        $lines[] = '    /**';
        foreach ($constructorParams as $param) {
            $lines[] = "     * @param {$param}";
        }
        $lines[] = '     */';
        $lines[] = '    public function __construct(';

        $paramNames = $this->buildConstructorParamNames($table, $dataNamespace);
        foreach ($paramNames as $i => $paramLine) {
            $comma = $i < count($paramNames) - 1 ? ',' : '';
            $lines[] = "        {$paramLine}{$comma}";
        }

        $lines[] = '    ) {';
        foreach ($constructorAssignments as $assignment) {
            $lines[] = "        {$assignment}";
        }
        $lines[] = '    }';

        // fromArray factory
        $lines[] = '';
        $lines[] = '    /**';
        $lines[] = '     * @param array $data';
        $lines[] = '     * @return self';
        $lines[] = '     */';
        $lines[] = '    public static function fromArray(array $data)';
        $lines[] = '    {';
        $lines[] = '        return new self(';

        foreach ($fromArrayAssignments as $i => $assignment) {
            $comma = $i < count($fromArrayAssignments) - 1 ? ',' : '';
            $lines[] = "            {$assignment}{$comma}";
        }

        $lines[] = '        );';
        $lines[] = '    }';
        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Build the property declarations with PHPDoc types.
     *
     * @return string[]
     */
    private function buildProperties(TableDefinition $table, string $dataNamespace): array
    {
        $properties = [];
        $hiddenSet = array_flip($table->hidden);
        $managedColumns = $this->getManagedColumns($table);
        $managedSet = array_flip($managedColumns);

        // Regular columns
        foreach ($table->columns as $column) {
            if (isset($hiddenSet[$column->name]) || isset($managedSet[$column->name])) {
                continue;
            }

            $type = $this->phpType($column);
            $propName = $this->propertyName($column->name);

            if ($column->nullable) {
                $properties[] = "/** @var {$type}|null */\n    public \${$propName};";
            } else {
                $properties[] = "/** @var {$type} */\n    public \${$propName};";
            }
        }

        // Timestamp columns
        if ($table->hasTimestamps) {
            foreach (self::TIMESTAMP_COLUMNS as $col) {
                if (! isset($hiddenSet[$col])) {
                    $propName = $this->propertyName($col);
                    $properties[] = "/** @var string|null */\n    public \${$propName};";
                }
            }
        }

        // Soft delete column
        if ($table->hasSoftDeletes) {
            foreach (self::SOFT_DELETE_COLUMNS as $col) {
                if (! isset($hiddenSet[$col])) {
                    $propName = $this->propertyName($col);
                    $properties[] = "/** @var string|null */\n    public \${$propName};";
                }
            }
        }

        // Relationships (non-BelongsTo) — always nullable (whenLoaded)
        foreach ($table->relationships as $rel) {
            if ($rel->type === 'belongsTo' || isset($hiddenSet[$rel->name])) {
                continue;
            }

            $properties[] = $this->buildRelationshipPropertyDeclaration($rel);
        }

        return $properties;
    }

    /**
     * Build constructor parameter names (for the function signature).
     *
     * @return string[]
     */
    private function buildConstructorParamNames(TableDefinition $table, string $dataNamespace): array
    {
        $params = [];
        $hiddenSet = array_flip($table->hidden);
        $managedColumns = $this->getManagedColumns($table);
        $managedSet = array_flip($managedColumns);

        foreach ($table->columns as $column) {
            if (isset($hiddenSet[$column->name]) || isset($managedSet[$column->name])) {
                continue;
            }

            $propName = $this->propertyName($column->name);

            if ($column->nullable) {
                $params[] = "\${$propName} = null";
            } else {
                $params[] = "\${$propName}";
            }
        }

        if ($table->hasTimestamps) {
            foreach (self::TIMESTAMP_COLUMNS as $col) {
                if (! isset($hiddenSet[$col])) {
                    $params[] = '$'.$this->propertyName($col).' = null';
                }
            }
        }

        if ($table->hasSoftDeletes) {
            foreach (self::SOFT_DELETE_COLUMNS as $col) {
                if (! isset($hiddenSet[$col])) {
                    $params[] = '$'.$this->propertyName($col).' = null';
                }
            }
        }

        foreach ($table->relationships as $rel) {
            if ($rel->type === 'belongsTo' || isset($hiddenSet[$rel->name])) {
                continue;
            }

            $params[] = '$'.$rel->name.' = null';
        }

        return $params;
    }

    /**
     * Build constructor PHPDoc @param lines.
     *
     * @return string[]
     */
    private function buildConstructorParams(TableDefinition $table, string $dataNamespace): array
    {
        $params = [];
        $hiddenSet = array_flip($table->hidden);
        $managedColumns = $this->getManagedColumns($table);
        $managedSet = array_flip($managedColumns);

        foreach ($table->columns as $column) {
            if (isset($hiddenSet[$column->name]) || isset($managedSet[$column->name])) {
                continue;
            }

            $type = $this->phpType($column);
            $propName = $this->propertyName($column->name);
            $nullSuffix = $column->nullable ? '|null' : '';
            $params[] = "{$type}{$nullSuffix} \${$propName}";
        }

        if ($table->hasTimestamps) {
            foreach (self::TIMESTAMP_COLUMNS as $col) {
                if (! isset($hiddenSet[$col])) {
                    $params[] = 'string|null $'.$this->propertyName($col);
                }
            }
        }

        if ($table->hasSoftDeletes) {
            foreach (self::SOFT_DELETE_COLUMNS as $col) {
                if (! isset($hiddenSet[$col])) {
                    $params[] = 'string|null $'.$this->propertyName($col);
                }
            }
        }

        foreach ($table->relationships as $rel) {
            if ($rel->type === 'belongsTo' || isset($hiddenSet[$rel->name])) {
                continue;
            }

            $relatedDataClass = class_basename($rel->relatedModel).'Data';

            if (in_array($rel->type, self::COLLECTION_RELATIONSHIPS)) {
                $params[] = "{$relatedDataClass}[]|null \${$rel->name}";
            } elseif (in_array($rel->type, self::SINGULAR_RELATIONSHIPS)) {
                $params[] = "{$relatedDataClass}|null \${$rel->name}";
            } else {
                $params[] = "mixed \${$rel->name}";
            }
        }

        return $params;
    }

    /**
     * Build constructor body assignments ($this->x = $x).
     *
     * @return string[]
     */
    private function buildConstructorAssignments(TableDefinition $table, string $dataNamespace): array
    {
        $assignments = [];
        $hiddenSet = array_flip($table->hidden);
        $managedColumns = $this->getManagedColumns($table);
        $managedSet = array_flip($managedColumns);

        foreach ($table->columns as $column) {
            if (isset($hiddenSet[$column->name]) || isset($managedSet[$column->name])) {
                continue;
            }

            $propName = $this->propertyName($column->name);
            $assignments[] = "\$this->{$propName} = \${$propName};";
        }

        if ($table->hasTimestamps) {
            foreach (self::TIMESTAMP_COLUMNS as $col) {
                if (! isset($hiddenSet[$col])) {
                    $propName = $this->propertyName($col);
                    $assignments[] = "\$this->{$propName} = \${$propName};";
                }
            }
        }

        if ($table->hasSoftDeletes) {
            foreach (self::SOFT_DELETE_COLUMNS as $col) {
                if (! isset($hiddenSet[$col])) {
                    $propName = $this->propertyName($col);
                    $assignments[] = "\$this->{$propName} = \${$propName};";
                }
            }
        }

        foreach ($table->relationships as $rel) {
            if ($rel->type === 'belongsTo' || isset($hiddenSet[$rel->name])) {
                continue;
            }

            $assignments[] = "\$this->{$rel->name} = \${$rel->name};";
        }

        return $assignments;
    }

    /**
     * Build the fromArray() assignment expressions (positional, not named).
     *
     * @return string[]
     */
    private function buildFromArrayAssignments(TableDefinition $table, string $dataNamespace): array
    {
        $assignments = [];
        $hiddenSet = array_flip($table->hidden);
        $managedColumns = $this->getManagedColumns($table);
        $managedSet = array_flip($managedColumns);

        // Regular columns
        foreach ($table->columns as $column) {
            if (isset($hiddenSet[$column->name]) || isset($managedSet[$column->name])) {
                continue;
            }

            $default = $column->nullable ? ' ?? null' : '';
            $assignments[] = "isset(\$data['{$column->name}']) ? \$data['{$column->name}']{$default} : null";
        }

        // Timestamp columns
        if ($table->hasTimestamps) {
            foreach (self::TIMESTAMP_COLUMNS as $col) {
                if (! isset($hiddenSet[$col])) {
                    $assignments[] = "isset(\$data['{$col}']) ? \$data['{$col}'] : null";
                }
            }
        }

        // Soft delete column
        if ($table->hasSoftDeletes) {
            foreach (self::SOFT_DELETE_COLUMNS as $col) {
                if (! isset($hiddenSet[$col])) {
                    $assignments[] = "isset(\$data['{$col}']) ? \$data['{$col}'] : null";
                }
            }
        }

        // Relationships
        foreach ($table->relationships as $rel) {
            if ($rel->type === 'belongsTo' || isset($hiddenSet[$rel->name])) {
                continue;
            }

            $assignments[] = $this->buildRelationshipFromArray($rel);
        }

        return $assignments;
    }

    private function buildRelationshipPropertyDeclaration(RelationshipDefinition $rel): string
    {
        if (in_array($rel->type, self::COLLECTION_RELATIONSHIPS)) {
            $relatedDataClass = class_basename($rel->relatedModel).'Data';

            return "/** @var {$relatedDataClass}[]|null */\n    public \${$rel->name};";
        }

        if (in_array($rel->type, self::SINGULAR_RELATIONSHIPS)) {
            $relatedDataClass = class_basename($rel->relatedModel).'Data';

            return "/** @var {$relatedDataClass}|null */\n    public \${$rel->name};";
        }

        return "/** @var mixed */\n    public \${$rel->name};";
    }

    private function buildRelationshipFromArray(RelationshipDefinition $rel): string
    {
        $relatedDataClass = class_basename($rel->relatedModel).'Data';

        if (in_array($rel->type, self::COLLECTION_RELATIONSHIPS)) {
            return "isset(\$data['{$rel->name}']) ? array_map(function (array \$item) { return {$relatedDataClass}::fromArray(\$item); }, \$data['{$rel->name}']) : null";
        }

        if (in_array($rel->type, self::SINGULAR_RELATIONSHIPS)) {
            return "isset(\$data['{$rel->name}']) ? {$relatedDataClass}::fromArray(\$data['{$rel->name}']) : null";
        }

        return "isset(\$data['{$rel->name}']) ? \$data['{$rel->name}'] : null";
    }

    /**
     * @return string[]
     */
    private function getManagedColumns(TableDefinition $table): array
    {
        $managed = [];

        if ($table->hasTimestamps) {
            $managed = array_merge($managed, self::TIMESTAMP_COLUMNS);
        }

        if ($table->hasSoftDeletes) {
            $managed = array_merge($managed, self::SOFT_DELETE_COLUMNS);
        }

        return $managed;
    }

    /**
     * Map a ColumnDefinition to its PHP type hint.
     *
     * If the column has a castType that is a SchemaCraftColumn, its sdkType() is used.
     * BackedEnum backing types are resolved via reflection.
     * Any other existing class that does NOT implement SchemaCraftColumn throws.
     */
    private function phpType(ColumnDefinition $column): string
    {
        if ($column->castType !== null && class_exists($column->castType)) {
            if (is_subclass_of($column->castType, \BackedEnum::class)) {
                return (new ReflectionEnum($column->castType))->getBackingType()->getName();
            }

            // Fully compliant — delegate.
            if (is_subclass_of($column->castType, SchemaCraftColumn::class)) {
                return $column->castType::sdkType();
            }

            // Custom Eloquent cast without SchemaCraftColumn — throw.
            // Built-ins like DateTime/Carbon don't implement CastsAttributes and fall through.
            if (is_subclass_of($column->castType, CastsAttributes::class)) {
                throw new RuntimeException(
                    "Cast class [{$column->castType}] must implement SchemaCraftColumn. "
                    .'Extend AbstractBitmaskType, AbstractJsonDtoType, or AbstractCollectionType, '
                    .'or implement SchemaCraftColumn directly. No fallback is provided.'
                );
            }
        }

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

    /**
     * Convert a snake_case column name to a camelCase property name.
     */
    private function propertyName(string $columnName): string
    {
        return Str::camel($columnName);
    }
}
