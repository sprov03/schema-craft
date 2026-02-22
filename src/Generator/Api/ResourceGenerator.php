<?php

namespace SchemaCraft\Generator\Api;

use Illuminate\Support\Str;
use SchemaCraft\Scanner\RelationshipDefinition;
use SchemaCraft\Scanner\TableDefinition;

/**
 * Generates an Eloquent API Resource class from a TableDefinition.
 */
class ResourceGenerator
{
    private const TIMESTAMP_COLUMNS = ['created_at', 'updated_at'];

    private const SOFT_DELETE_COLUMNS = ['deleted_at'];

    private const COLLECTION_RELATIONSHIPS = ['hasMany', 'belongsToMany', 'morphMany', 'morphToMany'];

    private const SINGULAR_RELATIONSHIPS = ['hasOne', 'morphOne'];

    /**
     * Generate the resource class PHP code.
     */
    public function generate(
        TableDefinition $table,
        string $resourceNamespace = 'App\\Resources',
        string $resourceSuffix = 'Resource',
    ): string {
        $modelName = $this->resolveModelName($table);
        $resourceName = $modelName.$resourceSuffix;

        $imports = $this->buildImports($table, $resourceNamespace);
        $fields = $this->buildFields($table);

        return $this->render(
            namespace: $resourceNamespace,
            resourceName: $resourceName,
            imports: $imports,
            fields: $fields,
        );
    }

    /**
     * @return string[]
     */
    private function buildImports(TableDefinition $table, string $resourceNamespace): array
    {
        $imports = [
            'Illuminate\Http\Request',
            'Illuminate\Http\Resources\Json\JsonResource',
        ];

        foreach ($table->relationships as $rel) {
            if ($rel->type === 'belongsTo') {
                continue;
            }

            if (in_array($rel->type, self::COLLECTION_RELATIONSHIPS) || in_array($rel->type, self::SINGULAR_RELATIONSHIPS)) {
                $relatedModelName = class_basename($rel->relatedModel);
                $relatedResourceFqcn = $resourceNamespace.'\\'.$relatedModelName.'Resource';

                if (! in_array($relatedResourceFqcn, $imports)) {
                    $imports[] = $relatedResourceFqcn;
                }
            }
        }

        sort($imports);

        return $imports;
    }

    /**
     * @return string[]
     */
    private function buildFields(TableDefinition $table): array
    {
        $fields = [];

        // Hidden columns to exclude from resource
        $hiddenSet = array_flip($table->hidden);

        // Build skip set for managed columns (added separately below)
        $managedColumns = [];
        if ($table->hasTimestamps) {
            $managedColumns = array_merge($managedColumns, self::TIMESTAMP_COLUMNS);
        }
        if ($table->hasSoftDeletes) {
            $managedColumns = array_merge($managedColumns, self::SOFT_DELETE_COLUMNS);
        }
        $managedSet = array_flip($managedColumns);

        // Regular columns (including FK IDs, excluding managed columns)
        foreach ($table->columns as $column) {
            if (isset($hiddenSet[$column->name])) {
                continue;
            }

            if (isset($managedSet[$column->name])) {
                continue;
            }

            $fields[] = "            '{$column->name}' => \$this->{$column->name},";
        }

        // Timestamps
        if ($table->hasTimestamps) {
            foreach (self::TIMESTAMP_COLUMNS as $col) {
                if (! isset($hiddenSet[$col])) {
                    $fields[] = "            '{$col}' => \$this->{$col},";
                }
            }
        }

        // Child relationships (non-BelongsTo) with whenLoaded
        foreach ($table->relationships as $rel) {
            if ($rel->type === 'belongsTo') {
                continue;
            }

            if (isset($hiddenSet[$rel->name])) {
                continue;
            }

            $relatedModelName = class_basename($rel->relatedModel);
            $relatedResourceName = $relatedModelName.'Resource';

            if (in_array($rel->type, self::COLLECTION_RELATIONSHIPS)) {
                if ($this->hasPivotColumns($rel)) {
                    $fields = array_merge($fields, $this->buildPivotField($rel, $relatedResourceName));
                } else {
                    $fields[] = "            '{$rel->name}' => {$relatedResourceName}::collection(\$this->whenLoaded('{$rel->name}')),";
                }
            } elseif (in_array($rel->type, self::SINGULAR_RELATIONSHIPS)) {
                $fields[] = "            '{$rel->name}' => new {$relatedResourceName}(\$this->whenLoaded('{$rel->name}')),";
            }
        }

        return $fields;
    }

    private function hasPivotColumns(RelationshipDefinition $rel): bool
    {
        return ! empty($rel->pivotColumns);
    }

    /**
     * @return string[]
     */
    private function buildPivotField(RelationshipDefinition $rel, string $relatedResourceName): array
    {
        $pivotCols = array_keys($rel->pivotColumns);
        $pivotColsList = implode("', '", $pivotCols);

        return [
            "            '{$rel->name}' => \$this->whenLoaded('{$rel->name}', function () {",
            "                return \$this->{$rel->name}->map(function (\$item) {",
            '                    return [',
            "                        ...{$relatedResourceName}::make(\$item)->resolve(),",
            "                        'pivot' => \$item->pivot->only(['{$pivotColsList}']),",
            '                    ];',
            '                });',
            '            }),',
        ];
    }

    /**
     * @param  string[]  $imports
     * @param  string[]  $fields
     */
    private function render(
        string $namespace,
        string $resourceName,
        array $imports,
        array $fields,
    ): string {
        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = "namespace {$namespace};";
        $lines[] = '';

        foreach ($imports as $import) {
            $lines[] = "use {$import};";
        }

        $lines[] = '';
        $lines[] = "class {$resourceName} extends JsonResource";
        $lines[] = '{';
        $lines[] = '    public function toArray(Request $request): array';
        $lines[] = '    {';
        $lines[] = '        return [';

        foreach ($fields as $field) {
            $lines[] = $field;
        }

        $lines[] = '        ];';
        $lines[] = '    }';
        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function resolveModelName(TableDefinition $table): string
    {
        $className = class_basename($table->schemaClass);
        $baseName = Str::beforeLast($className, 'Schema');

        if ($baseName === $className) {
            return $className;
        }

        return $baseName;
    }
}
