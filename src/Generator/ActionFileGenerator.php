<?php

namespace SchemaCraft\Generator;

use Illuminate\Support\Str;
use SchemaCraft\Generator\Api\GeneratedFile;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Scanner\RelationshipDefinition;
use SchemaCraft\Scanner\SchemaScanner;
use SchemaCraft\Scanner\TableDefinition;

/**
 * Generates Action class files and ActionRegistry files from schema field selection.
 *
 * Takes a schema class + selected column names and produces:
 * - Individual Action classes with typed properties, BelongsTo attributes, and imports
 * - ActionRegistry class grouping actions with typed properties
 */
class ActionFileGenerator
{
    public function __construct(
        private string $stubsPath,
    ) {}

    /**
     * Generate a single Action class file from a schema and selected columns.
     *
     * @param  string[]  $selectedColumns  Column names to include as properties
     * @param  array<string, bool>  $nullableOverrides  Map of column_name => nullable (overrides schema nullable)
     */
    public function generateAction(
        string $actionName,
        string $schemaClass,
        array $selectedColumns,
        string $httpMethod = 'put',
        string $actionNamespace = 'App\\Models\\Actions',
        string $schemaNamespace = 'App\\Schemas',
        array $nullableOverrides = [],
        ?string $description = null,
        string $serviceNamespace = 'App\\Models\\Services',
        string $modelNamespace = 'App\\Models',
    ): GeneratedFile {
        $scanner = new SchemaScanner($schemaClass);
        $table = $scanner->scan();

        $modelName = $this->resolveModelName($schemaClass);
        $actionClassName = ucfirst($actionName).$modelName.'Action';
        $schemaClassName = class_basename($schemaClass);
        $label = Str::headline($actionName).' '.$modelName;
        $serviceMethod = lcfirst(ucfirst($actionName).$modelName);

        // Format description for ActionMeta attribute
        $descriptionSuffix = '';
        if ($description !== null && $description !== '') {
            $escaped = str_replace("'", "\\'", $description);
            $descriptionSuffix = ", description: '{$escaped}'";
        }

        // Partition columns into flat and nested (dot-notation)
        [$flatColumns, $nestedGroups] = $this->partitionColumns($selectedColumns);

        $serviceClassName = $modelName.'Service';
        $serviceFqcn = $serviceNamespace.'\\'.$serviceClassName;
        $modelFqcn = $modelNamespace.'\\'.$modelName;
        $runMethod = $this->buildRunMethod($httpMethod, $serviceMethod, $serviceClassName, $modelName);

        $imports = $this->buildActionImports($table, $flatColumns, $nestedGroups, $schemaNamespace, $schemaClassName);

        // Add model import for run() return type and PHPDoc
        $imports .= "\nuse {$modelFqcn};";

        // Add service import for post (create) actions that call static methods
        if (strtolower($httpMethod) === 'post') {
            $imports .= "\nuse {$serviceFqcn};";
        }

        $properties = $this->buildActionProperties($table, $flatColumns, $nestedGroups, $nullableOverrides);

        $stub = file_get_contents($this->stubsPath.'/actions/action.stub');
        $content = $this->stripTemplateDocBlock($stub);

        $content = str_replace(
            [
                '{{ actionNamespace }}',
                '{{ imports }}',
                '{{ httpMethod }}',
                '{{ label }}',
                '{{ description }}',
                '{{ actionClass }}',
                '{{ schemaClass }}',
                '{{ properties }}',
                '{{ run }}',
            ],
            [
                $actionNamespace,
                $imports,
                $httpMethod,
                $label,
                $descriptionSuffix,
                $actionClassName,
                $schemaClassName,
                $properties,
                $runMethod,
            ],
            $content,
        );

        return new GeneratedFile(
            path: $this->namespaceToPath($actionNamespace, $actionClassName),
            content: $this->cleanOutput($content),
        );
    }

    /**
     * Generate an ActionRegistry class grouping multiple action classes.
     *
     * @param  array<string, string>  $actionClasses  Map of property name => FQCN (e.g., ['create' => 'App\Actions\CreatePostAction'])
     */
    public function generateRegistry(
        string $modelName,
        array $actionClasses,
        string $registryNamespace = 'App\\Models\\Actions',
        string $schemaNamespace = 'App\\Schemas',
    ): GeneratedFile {
        $registryClassName = $modelName.'Actions';
        $schemaClassName = $modelName.'Schema';

        $imports = $this->buildRegistryImports($actionClasses, $schemaNamespace, $schemaClassName);
        $properties = $this->buildRegistryProperties($actionClasses);

        $stub = file_get_contents($this->stubsPath.'/actions/registry.stub');
        $content = $this->stripTemplateDocBlock($stub);

        $content = str_replace(
            [
                '{{ registryNamespace }}',
                '{{ imports }}',
                '{{ registryClass }}',
                '{{ schemaClass }}',
                '{{ properties }}',
            ],
            [
                $registryNamespace,
                $imports,
                $registryClassName,
                $schemaClassName,
                $properties,
            ],
            $content,
        );

        return new GeneratedFile(
            path: $this->namespaceToPath($registryNamespace, $registryClassName),
            content: $this->cleanOutput($content),
        );
    }

    /**
     * Build import statements for an Action class.
     *
     * @param  string[]  $flatColumns
     * @param  array<string, string[]>  $nestedGroups  Map of relationship name → field names
     */
    private function buildActionImports(
        TableDefinition $table,
        array $flatColumns,
        array $nestedGroups,
        string $schemaNamespace,
        string $schemaClassName,
    ): string {
        $imports = [];
        $imports[] = "use {$schemaNamespace}\\{$schemaClassName};";

        $hasBelongsTo = false;
        $relationAttrImports = [];

        foreach ($flatColumns as $columnName) {
            $relationship = $this->findRelationshipForColumn($table, $columnName);

            if ($relationship !== null) {
                $hasBelongsTo = true;
                $imports[] = "use {$relationship->relatedModel};";
            }
        }

        if ($hasBelongsTo) {
            $imports[] = 'use SchemaCraft\\Attributes\\Relations\\BelongsTo;';
        }

        // Add imports for nested relationship properties
        foreach ($nestedGroups as $relName => $fields) {
            $rel = $this->findRelationshipByName($table, $relName);
            if ($rel === null) {
                continue;
            }

            $imports[] = "use {$rel->relatedModel};";

            $attrClass = $this->relationTypeToAttributeClass($rel->type);
            if ($attrClass !== null) {
                $relationAttrImports[$attrClass] = true;
            }
        }

        foreach (array_keys($relationAttrImports) as $attrClass) {
            $imports[] = "use {$attrClass};";
        }

        $imports = array_unique($imports);
        sort($imports);

        return implode("\n", $imports);
    }

    /**
     * Build typed property declarations for an Action class.
     *
     * @param  string[]  $flatColumns
     * @param  array<string, string[]>  $nestedGroups  Map of relationship name → field names
     * @param  array<string, bool>  $nullableOverrides  Map of column_name => nullable
     */
    private function buildActionProperties(
        TableDefinition $table,
        array $flatColumns,
        array $nestedGroups,
        array $nullableOverrides = [],
    ): string {
        $lines = [];

        // Build flat column properties (existing behavior)
        foreach ($flatColumns as $columnName) {
            $relationship = $this->findRelationshipForColumn($table, $columnName);

            if ($relationship !== null) {
                $modelBaseName = class_basename($relationship->relatedModel);
                $propName = Str::camel($relationship->name);
                $isNullable = array_key_exists($columnName, $nullableOverrides)
                    ? $nullableOverrides[$columnName]
                    : $relationship->nullable;
                $nullable = $isNullable ? '?' : '';

                $lines[] = "    #[BelongsTo({$modelBaseName}::class)]";
                $lines[] = "    public {$nullable}{$modelBaseName} \${$propName};";
                $lines[] = '';
            } else {
                $column = $this->findColumn($table, $columnName);

                if ($column !== null) {
                    $phpType = $this->phpType($column);
                    $isNullable = array_key_exists($columnName, $nullableOverrides)
                        ? $nullableOverrides[$columnName]
                        : $column->nullable;
                    $nullable = $isNullable ? '?' : '';
                    $propName = Str::camel($column->name);
                    $default = '';

                    if ($isNullable) {
                        $default = '';
                    } elseif ($column->columnType === 'boolean') {
                        $default = ' = false';
                    }

                    $lines[] = "    public {$nullable}{$phpType} \${$propName}{$default};";
                    $lines[] = '';
                }
            }
        }

        // Build nested relationship properties
        foreach ($nestedGroups as $relName => $fields) {
            $nestedLines = $this->buildNestedProperty($table, $relName, $fields, $nullableOverrides);
            if ($nestedLines !== null) {
                $lines = array_merge($lines, $nestedLines);
                $lines[] = '';
            }
        }

        // Remove trailing blank line
        if (! empty($lines) && end($lines) === '') {
            array_pop($lines);
        }

        return implode("\n", $lines);
    }

    /**
     * Build import statements for a Registry class.
     *
     * @param  array<string, string>  $actionClasses
     */
    private function buildRegistryImports(
        array $actionClasses,
        string $schemaNamespace,
        string $schemaClassName,
    ): string {
        $imports = [];
        $imports[] = "use {$schemaNamespace}\\{$schemaClassName};";

        foreach ($actionClasses as $fqcn) {
            $imports[] = "use {$fqcn};";
        }

        $imports = array_unique($imports);
        sort($imports);

        return implode("\n", $imports);
    }

    /**
     * Build static method declarations for a Registry class.
     *
     * @param  array<string, string>  $actionClasses  Map of methodName => FQCN
     */
    private function buildRegistryProperties(array $actionClasses): string
    {
        $lines = [];

        foreach ($actionClasses as $methodName => $fqcn) {
            $className = class_basename($fqcn);
            $lines[] = "    public static function {$methodName}(): {$className}";
            $lines[] = '    {';
            $lines[] = "        return new {$className};";
            $lines[] = '    }';
            $lines[] = '';
        }

        // Remove trailing blank line
        if (! empty($lines) && end($lines) === '') {
            array_pop($lines);
        }

        return implode("\n", $lines);
    }

    /**
     * Partition selectedColumns into flat column names and nested groups.
     *
     * Flat columns: 'title', 'author_id'
     * Nested columns: 'contact.first_name', 'comments.*.body'
     *
     * @return array{0: string[], 1: array<string, string[]>}
     */
    private function partitionColumns(array $selectedColumns): array
    {
        $flat = [];
        $nested = [];

        foreach ($selectedColumns as $column) {
            if (str_contains($column, '.')) {
                // Strip the collection wildcard (*.): 'comments.*.body' → 'comments.body'
                $normalized = str_replace('.*.', '.', $column);
                $segments = explode('.', $normalized, 2);
                $nested[$segments[0]][] = $segments[1];
            } else {
                $flat[] = $column;
            }
        }

        return [$flat, $nested];
    }

    /**
     * Build a nested relationship property declaration.
     *
     * @param  string[]  $fields  Field names on the related model
     * @param  array<string, bool>  $nullableOverrides
     * @return string[]|null Lines for the property, or null if relationship not found
     */
    private function buildNestedProperty(
        TableDefinition $table,
        string $relName,
        array $fields,
        array $nullableOverrides,
    ): ?array {
        $rel = $this->findRelationshipByName($table, $relName);
        if ($rel === null) {
            return null;
        }

        $modelBaseName = class_basename($rel->relatedModel);
        $attrName = class_basename($this->relationTypeToAttributeClass($rel->type) ?? 'HasOne');
        $isCollection = in_array($rel->type, ['hasMany', 'belongsToMany', 'morphMany', 'morphToMany']);

        // Determine nullable from override or relationship definition
        $isNullable = array_key_exists($relName, $nullableOverrides)
            ? $nullableOverrides[$relName]
            : $rel->nullable;
        $nullable = (! $isCollection && $isNullable) ? '?' : '';

        // Build the fields array for the attribute
        $fieldsStr = $this->formatFieldsArray($fields);

        // Build extra attribute params
        $extraParams = '';
        if ($rel->type === 'morphOne' || $rel->type === 'morphMany' || $rel->type === 'morphToMany') {
            $morphName = $rel->morphName ?? $relName;
            $extraParams = ", '{$morphName}'";
        }

        // Build the sync param for collection types
        $syncParam = '';
        if (in_array($rel->type, ['hasMany', 'morphMany'])) {
            // Default sync=false, only add if sync is true
        } elseif (in_array($rel->type, ['belongsToMany', 'morphToMany'])) {
            // Default sync=true, already the attribute default
        }

        $lines = [];
        $lines[] = "    #[{$attrName}({$modelBaseName}::class{$extraParams}, fields: {$fieldsStr})]";

        $default = $isCollection ? ' = []' : '';
        $lines[] = "    public {$nullable}array \${$relName}{$default};";

        return $lines;
    }

    /**
     * Find a relationship by its property name.
     */
    private function findRelationshipByName(TableDefinition $table, string $name): ?RelationshipDefinition
    {
        foreach ($table->relationships as $rel) {
            if ($rel->name === $name) {
                return $rel;
            }
        }

        return null;
    }

    /**
     * Map a relationship type to its attribute FQCN.
     */
    private function relationTypeToAttributeClass(string $type): ?string
    {
        return match ($type) {
            'belongsTo' => 'SchemaCraft\\Attributes\\Relations\\BelongsTo',
            'hasOne' => 'SchemaCraft\\Attributes\\Relations\\HasOne',
            'hasMany' => 'SchemaCraft\\Attributes\\Relations\\HasMany',
            'belongsToMany' => 'SchemaCraft\\Attributes\\Relations\\BelongsToMany',
            'morphOne' => 'SchemaCraft\\Attributes\\Relations\\MorphOne',
            'morphMany' => 'SchemaCraft\\Attributes\\Relations\\MorphMany',
            'morphToMany' => 'SchemaCraft\\Attributes\\Relations\\MorphToMany',
            default => null,
        };
    }

    /**
     * Format a fields array as a PHP array literal string.
     *
     * @param  string[]  $fields
     */
    private function formatFieldsArray(array $fields): string
    {
        $items = array_map(fn (string $f) => "'{$f}'", $fields);

        return '['.implode(', ', $items).']';
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

            $fkColumn = $relationship->foreignColumn ?? Str::snake($relationship->name).'_id';

            if ($fkColumn === $columnName) {
                return $relationship;
            }
        }

        return null;
    }

    private function findColumn(TableDefinition $table, string $columnName): ?ColumnDefinition
    {
        foreach ($table->columns as $column) {
            if ($column->name === $columnName) {
                return $column;
            }
        }

        return null;
    }

    private function phpType(ColumnDefinition $column): string
    {
        return match ($column->columnType) {
            'integer', 'bigInteger', 'smallInteger', 'tinyInteger',
            'unsignedBigInteger', 'unsignedInteger', 'unsignedSmallInteger', 'unsignedTinyInteger' => 'int',
            'boolean' => 'bool',
            'decimal', 'float', 'double' => 'float',
            'json' => 'array',
            default => 'string',
        };
    }

    /**
     * Build the run() method implementation based on HTTP method.
     */
    private function buildRunMethod(string $httpMethod, string $serviceMethod, string $serviceClassName, string $modelName): string
    {
        $varName = Str::camel($modelName);

        $body = match (strtolower($httpMethod)) {
            'post' => "        return {$serviceClassName}::{$serviceMethod}(...\$mapped);",
            'delete' => "        \${$varName}->Service()->{$serviceMethod}();\n\n        return null;",
            'get' => "        return \${$varName}->Service()->{$serviceMethod}();",
            default => "        return \${$varName}->Service()->{$serviceMethod}(...\$mapped);",
        };

        $lines = [];
        $lines[] = "    /** @param {$modelName} \${$varName} */";
        $lines[] = "    public function run(mixed \${$varName}, array \$mapped): {$modelName}";
        $lines[] = '    {';
        $lines[] = $body;
        $lines[] = '    }';

        return implode("\n", $lines);
    }

    private function resolveModelName(string $schemaClass): string
    {
        $className = class_basename($schemaClass);

        return Str::beforeLast($className, 'Schema') ?: $className;
    }

    private function namespaceToPath(string $namespace, string $className): string
    {
        $relativePath = str_replace('\\', '/', $namespace);

        if (str_starts_with($relativePath, 'App/')) {
            $relativePath = 'app/'.substr($relativePath, 4);
        }

        return $relativePath.'/'.$className.'.php';
    }

    private function stripTemplateDocBlock(string $content): string
    {
        return preg_replace('/\/\*\*\s*\n\s*\*\s*Template variables:.*?\*\/\s*\n?/s', '', $content);
    }

    private function cleanOutput(string $content): string
    {
        return preg_replace('/\n{3,}/', "\n\n", $content);
    }
}
