<?php

namespace SchemaCraft\Generator\Sdk;

use Illuminate\Support\Str;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Scanner\RelationshipDefinition;
use SchemaCraft\Scanner\TableDefinition;

/**
 * Generates a Data Transfer Object (DTO) class from a TableDefinition.
 *
 * The DTO represents the JSON response shape from the API resource,
 * with typed readonly properties and a static fromArray() factory.
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
        $fromArrayAssignments = $this->buildFromArrayAssignments($table, $dataNamespace);

        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = "namespace {$dataNamespace};";
        $lines[] = '';
        $lines[] = "class {$dataClassName}";
        $lines[] = '{';
        $lines[] = '    public function __construct(';

        foreach ($properties as $i => $prop) {
            $comma = $i < count($properties) - 1 ? ',' : ',';
            $lines[] = "        {$prop}{$comma}";
        }

        $lines[] = '    ) {}';
        $lines[] = '';
        $lines[] = '    /**';
        $lines[] = '     * @param  array<string, mixed>  $data';
        $lines[] = '     */';
        $lines[] = '    public static function fromArray(array $data): self';
        $lines[] = '    {';
        $lines[] = '        return new self(';

        foreach ($fromArrayAssignments as $i => $assignment) {
            $comma = $i < count($fromArrayAssignments) - 1 ? ',' : ',';
            $lines[] = "            {$assignment}{$comma}";
        }

        $lines[] = '        );';
        $lines[] = '    }';
        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Build the constructor property declarations.
     *
     * @return string[]
     */
    private function buildProperties(TableDefinition $table, string $dataNamespace): array
    {
        $properties = [];
        $hiddenSet = array_flip($table->hidden);
        $managedColumns = $this->getManagedColumns($table);
        $managedSet = array_flip($managedColumns);

        // Regular columns (excluding hidden and managed)
        foreach ($table->columns as $column) {
            if (isset($hiddenSet[$column->name])) {
                continue;
            }

            if (isset($managedSet[$column->name])) {
                continue;
            }

            $properties[] = $this->buildPropertyDeclaration($column);
        }

        // Timestamp columns
        if ($table->hasTimestamps) {
            foreach (self::TIMESTAMP_COLUMNS as $col) {
                if (! isset($hiddenSet[$col])) {
                    $properties[] = "public readonly ?string \${$this->propertyName($col)}";
                }
            }
        }

        // Soft delete column
        if ($table->hasSoftDeletes) {
            foreach (self::SOFT_DELETE_COLUMNS as $col) {
                if (! isset($hiddenSet[$col])) {
                    $properties[] = "public readonly ?string \${$this->propertyName($col)}";
                }
            }
        }

        // Relationships (non-BelongsTo) — always nullable (whenLoaded)
        foreach ($table->relationships as $rel) {
            if ($rel->type === 'belongsTo') {
                continue;
            }

            if (isset($hiddenSet[$rel->name])) {
                continue;
            }

            $properties[] = $this->buildRelationshipProperty($rel, $dataNamespace);
        }

        return $properties;
    }

    /**
     * Build the fromArray() assignment expressions.
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
            if (isset($hiddenSet[$column->name])) {
                continue;
            }

            if (isset($managedSet[$column->name])) {
                continue;
            }

            $assignments[] = $this->buildFromArrayExpression($column);
        }

        // Timestamp columns
        if ($table->hasTimestamps) {
            foreach (self::TIMESTAMP_COLUMNS as $col) {
                if (! isset($hiddenSet[$col])) {
                    $prop = $this->propertyName($col);
                    $assignments[] = "{$prop}: \$data['{$col}'] ?? null";
                }
            }
        }

        // Soft delete column
        if ($table->hasSoftDeletes) {
            foreach (self::SOFT_DELETE_COLUMNS as $col) {
                if (! isset($hiddenSet[$col])) {
                    $prop = $this->propertyName($col);
                    $assignments[] = "{$prop}: \$data['{$col}'] ?? null";
                }
            }
        }

        // Relationships
        foreach ($table->relationships as $rel) {
            if ($rel->type === 'belongsTo') {
                continue;
            }

            if (isset($hiddenSet[$rel->name])) {
                continue;
            }

            $assignments[] = $this->buildRelationshipFromArray($rel, $dataNamespace);
        }

        return $assignments;
    }

    private function buildPropertyDeclaration(ColumnDefinition $column): string
    {
        $type = $this->phpType($column);
        $nullablePrefix = $column->nullable ? '?' : '';
        $propName = $this->propertyName($column->name);

        return "public readonly {$nullablePrefix}{$type} \${$propName}";
    }

    private function buildFromArrayExpression(ColumnDefinition $column): string
    {
        $propName = $this->propertyName($column->name);
        $default = $column->nullable ? ' ?? null' : '';

        return "{$propName}: \$data['{$column->name}']{$default}";
    }

    private function buildRelationshipProperty(RelationshipDefinition $rel, string $dataNamespace): string
    {
        // Relationships from whenLoaded are always nullable (may not be present)
        if (in_array($rel->type, self::COLLECTION_RELATIONSHIPS)) {
            $relatedDataClass = class_basename($rel->relatedModel).'Data';

            return "/** @var {$relatedDataClass}[]|null */ public readonly ?array \${$rel->name}";
        }

        if (in_array($rel->type, self::SINGULAR_RELATIONSHIPS)) {
            $relatedDataClass = class_basename($rel->relatedModel).'Data';

            return "public readonly ?{$relatedDataClass} \${$rel->name}";
        }

        return "public readonly mixed \${$rel->name}";
    }

    private function buildRelationshipFromArray(RelationshipDefinition $rel, string $dataNamespace): string
    {
        $relatedDataClass = class_basename($rel->relatedModel).'Data';

        if (in_array($rel->type, self::COLLECTION_RELATIONSHIPS)) {
            return "{$rel->name}: isset(\$data['{$rel->name}']) ? array_map(fn (array \$item) => {$relatedDataClass}::fromArray(\$item), \$data['{$rel->name}']) : null";
        }

        if (in_array($rel->type, self::SINGULAR_RELATIONSHIPS)) {
            return "{$rel->name}: isset(\$data['{$rel->name}']) ? {$relatedDataClass}::fromArray(\$data['{$rel->name}']) : null";
        }

        return "{$rel->name}: \$data['{$rel->name}'] ?? null";
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
     * Convert a snake_case column name to a camelCase property name.
     */
    private function propertyName(string $columnName): string
    {
        return Str::camel($columnName);
    }
}
