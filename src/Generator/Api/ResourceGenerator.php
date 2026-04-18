<?php

namespace SchemaCraft\Generator\Api;

use Illuminate\Support\Str;
use SchemaCraft\Scanner\TableDefinition;

/**
 * Generates a typed SchemaCraftResource class from a TableDefinition.
 *
 * Generated resources extend SchemaCraftResource and declare typed properties
 * for scalar fields and relationship attributes for nested resources.
 * The base class handles toArray() via reflection — no manual array needed.
 */
class ResourceGenerator
{
    private const COLLECTION_RELATIONSHIPS = ['hasMany', 'belongsToMany', 'morphMany', 'morphToMany'];

    private const SINGULAR_RELATIONSHIPS = ['hasOne', 'morphOne'];

    private const BELONGS_TO_RELATIONSHIPS = ['belongsTo'];

    /**
     * Generate the resource class PHP code.
     *
     * @param  string  $resourceName  Explicit class name, e.g. "CampaignFullResource".
     *                                Defaults to "{ModelName}Resource" when null.
     */
    /**
     * @param  array<string, string>  $relationshipResourceOverrides  Maps relationship name → resource short class name.
     *                                                                e.g. ['assignments' => 'AsteriskDidAssignmentLightResource']
     *                                                                Defaults to "{RelatedModel}Resource" when omitted.
     */
    public function generate(
        TableDefinition $table,
        string $resourceNamespace = 'App\\Resources',
        string $modelNamespace = 'App\\Models',
        ?string $resourceName = null,
        array $relationshipResourceOverrides = [],
    ): string {
        $modelName = $this->resolveModelName($table);
        $resolvedResourceName = $resourceName ?? $modelName.'Resource';
        $modelFqcn = $this->resolveModelFqcn($table, $modelNamespace);
        $schemaClass = class_basename($table->schemaClass);

        $imports = $this->buildImports($table, $resourceNamespace, $modelFqcn, $relationshipResourceOverrides);
        $properties = $this->buildProperties($table, $relationshipResourceOverrides);

        return $this->render(
            namespace: $resourceNamespace,
            resourceName: $resolvedResourceName,
            modelClass: $modelName,
            schemaFqcn: $table->schemaClass,
            schemaClass: $schemaClass,
            imports: $imports,
            properties: $properties,
        );
    }

    /**
     * @param  array<string, string>  $overrides
     * @return string[]
     */
    private function buildImports(TableDefinition $table, string $resourceNamespace, ?string $modelFqcn, array $overrides = []): array
    {
        $imports = [
            'Illuminate\Support\Collection',
            'SchemaCraft\Attributes\Resources\ResourceSchema',
            'SchemaCraft\SchemaCraftResource',
            $table->schemaClass,
        ];

        if ($modelFqcn !== null) {
            $imports[] = $modelFqcn;
        }

        foreach ($table->relationships as $rel) {
            if ($rel->type === 'belongsTo') {
                continue;
            }

            if (in_array($rel->type, self::COLLECTION_RELATIONSHIPS)) {
                $imports[] = 'SchemaCraft\Attributes\Resources\HasMany';
            } elseif (in_array($rel->type, self::SINGULAR_RELATIONSHIPS)) {
                $imports[] = 'SchemaCraft\Attributes\Resources\HasOne';
            }

            $relatedResourceName = $overrides[$rel->name] ?? (class_basename($rel->relatedModel).'Resource');
            $relatedResourceFqcn = $resourceNamespace.'\\'.$relatedResourceName;

            if (! in_array($relatedResourceFqcn, $imports)) {
                $imports[] = $relatedResourceFqcn;
            }
        }

        // Remove duplicates and sort
        $imports = array_unique($imports);
        sort($imports);

        return $imports;
    }

    /**
     * Build the typed property declarations for the resource class body.
     *
     * @param  array<string, string>  $overrides
     * @return string[]
     */
    private function buildProperties(TableDefinition $table, array $overrides = []): array
    {
        $lines = [];

        $hiddenSet = array_flip($table->hidden);
        $managedColumns = [];

        if ($table->hasTimestamps) {
            $managedColumns = array_merge($managedColumns, ['created_at', 'updated_at']);
        }
        if ($table->hasSoftDeletes) {
            $managedColumns[] = 'deleted_at';
        }
        $managedSet = array_flip($managedColumns);

        // Scalar columns
        foreach ($table->columns as $column) {
            if (isset($hiddenSet[$column->name]) || isset($managedSet[$column->name])) {
                continue;
            }

            $phpType = $this->resolvePhpType($column->columnType, $column->nullable);
            $lines[] = "    public {$phpType} \${$column->name};";
        }

        // Managed timestamp/soft-delete columns
        if ($table->hasTimestamps) {
            foreach (['created_at', 'updated_at'] as $col) {
                if (! isset($hiddenSet[$col])) {
                    $lines[] = "    public ?\\Carbon\\Carbon \${$col};";
                }
            }
        }
        if ($table->hasSoftDeletes && ! isset($hiddenSet['deleted_at'])) {
            $lines[] = '    public ?\\Carbon\\Carbon $deleted_at;';
        }

        // Relationship properties
        foreach ($table->relationships as $rel) {
            if ($rel->type === 'belongsTo') {
                continue;
            }

            if (isset($hiddenSet[$rel->name])) {
                continue;
            }

            $relatedResourceName = $overrides[$rel->name] ?? (class_basename($rel->relatedModel).'Resource');

            if (! empty($lines)) {
                $lines[] = '';
            }

            if (in_array($rel->type, self::COLLECTION_RELATIONSHIPS)) {
                $lines[] = "    #[HasMany({$relatedResourceName}::class)]";
                $lines[] = "    public Collection \${$rel->name};";
            } elseif (in_array($rel->type, self::SINGULAR_RELATIONSHIPS)) {
                $lines[] = "    #[HasOne({$relatedResourceName}::class)]";
                $lines[] = "    public ?{$relatedResourceName} \${$rel->name};";
            }
        }

        return $lines;
    }

    /**
     * Map a schema column type to a PHP type hint.
     */
    private function resolvePhpType(string $columnType, bool $nullable): string
    {
        $base = match (true) {
            in_array($columnType, ['int', 'integer', 'bigInteger', 'unsignedBigInteger', 'unsignedInteger', 'smallInteger', 'tinyInteger', 'mediumInteger']) => 'int',
            in_array($columnType, ['float', 'double', 'decimal']) => 'float',
            $columnType === 'boolean' => 'bool',
            in_array($columnType, ['date', 'datetime', 'timestamp']) => '\\Carbon\\Carbon',
            default => 'string',
        };

        return $nullable ? "?{$base}" : $base;
    }

    /**
     * @param  string[]  $imports
     * @param  string[]  $properties
     */
    private function render(
        string $namespace,
        string $resourceName,
        string $modelClass,
        string $schemaFqcn,
        string $schemaClass,
        array $imports,
        array $properties,
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
        $lines[] = '/**';
        $lines[] = " * @mixin {$modelClass}";
        $lines[] = ' */';
        $lines[] = "#[ResourceSchema({$schemaClass}::class)]";
        $lines[] = "class {$resourceName} extends SchemaCraftResource";
        $lines[] = '{';

        if (! empty($properties)) {
            foreach ($properties as $property) {
                $lines[] = $property;
            }
            $lines[] = '';
        }

        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function resolveModelName(TableDefinition $table): string
    {
        $className = class_basename($table->schemaClass);
        $baseName = Str::beforeLast($className, 'Schema');

        return $baseName === $className ? $className : $baseName;
    }

    /**
     * Derive the model FQCN from the schema class or fall back to the given namespace.
     *
     * e.g., App\Schemas\PostSchema → App\Models\Post
     */
    private function resolveModelFqcn(TableDefinition $table, string $modelNamespace): ?string
    {
        $schemaClass = $table->schemaClass;
        $namespace = Str::beforeLast($schemaClass, '\\');
        $className = class_basename($schemaClass);
        $modelName = Str::beforeLast($className, 'Schema');

        if ($modelName === $className) {
            return null;
        }

        $modelNs = preg_replace('/\\\\Schemas$/', '\\Models', $namespace);

        if ($modelNs !== $namespace) {
            $candidate = $modelNs.'\\'.$modelName;

            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        return $modelNamespace.'\\'.$modelName;
    }
}
