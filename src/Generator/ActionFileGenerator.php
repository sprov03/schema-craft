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
        $label = Str::headline($actionName);
        $route = Str::kebab($actionName);
        $serviceMethod = $actionName;

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

        // Build DataSchema classes for nested relationships
        $actionSchemaClasses = $this->buildDataSchemaClasses(
            $table, $nestedGroups, $actionClassName, $schemaNamespace, $nullableOverrides,
        );

        $imports = $this->buildActionImports(
            $table, $flatColumns, $nestedGroups, $schemaNamespace, $schemaClassName, ! empty($actionSchemaClasses),
        );

        // Add model import for run() return type and PHPDoc
        $imports .= "\nuse {$modelFqcn};";

        // Add service import for post (create) actions that call static methods
        if (strtolower($httpMethod) === 'post') {
            $imports .= "\nuse {$serviceFqcn};";
        }

        $properties = $this->buildActionProperties($table, $flatColumns, $nestedGroups, $nullableOverrides, $actionClassName);

        $stub = file_get_contents($this->stubsPath.'/actions/action.stub');
        $content = $this->stripTemplateDocBlock($stub);

        // Build the DataSchema class code to append after the Action class
        $actionSchemasCode = '';
        if (! empty($actionSchemaClasses)) {
            $actionSchemasCode = "\n".implode("\n\n", $actionSchemaClasses);
        }

        $content = str_replace(
            [
                '{{ actionNamespace }}',
                '{{ imports }}',
                '{{ route }}',
                '{{ httpMethod }}',
                '{{ label }}',
                '{{ description }}',
                '{{ actionClass }}',
                '{{ schemaClass }}',
                '{{ properties }}',
                '{{ run }}',
                '{{ dataSchemas }}',
            ],
            [
                $actionNamespace,
                $imports,
                $route,
                $httpMethod,
                $label,
                $descriptionSuffix,
                $actionClassName,
                $schemaClassName,
                $properties,
                $runMethod,
                $actionSchemasCode,
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
        bool $hasDataSchemas = false,
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

            $attrClass = $this->relationTypeToAttributeClass($rel->type);
            if ($attrClass !== null) {
                $relationAttrImports[$attrClass] = true;
            }

            // Add related schema import for DataSchema classes
            $relatedModelBaseName = class_basename($rel->relatedModel);
            $relatedSchemaClassName = $relatedModelBaseName.'Schema';
            $relatedSchemaFqcn = $schemaNamespace.'\\'.$relatedSchemaClassName;
            if ($relatedSchemaFqcn !== $schemaNamespace.'\\'.$schemaClassName) {
                $imports[] = "use {$relatedSchemaFqcn};";
            }
        }

        foreach (array_keys($relationAttrImports) as $attrClass) {
            $imports[] = "use {$attrClass};";
        }

        // Add DataSchema-specific imports
        if ($hasDataSchemas) {
            $imports[] = 'use Illuminate\\Support\\Collection;';
            $imports[] = 'use SchemaCraft\\DataSchema;';
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
        string $actionClassName = '',
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

        // Build nested relationship properties (DataSchema-based)
        foreach ($nestedGroups as $relName => $fields) {
            $nestedLines = $this->buildNestedProperty($table, $relName, $fields, $nullableOverrides, $actionClassName);
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
        string $actionClassName = '',
    ): ?array {
        $rel = $this->findRelationshipByName($table, $relName);
        if ($rel === null) {
            return null;
        }

        $attrName = class_basename($this->relationTypeToAttributeClass($rel->type) ?? 'HasOne');
        $isCollection = in_array($rel->type, ['hasMany', 'belongsToMany', 'morphMany', 'morphToMany']);

        // Build DataSchema class name
        $actionSchemaClassName = $this->buildDataSchemaClassName($actionClassName, $relName);

        // Build extra attribute params for morph relationships
        $extraParams = '';
        if ($rel->type === 'morphOne' || $rel->type === 'morphMany' || $rel->type === 'morphToMany') {
            $morphName = $rel->morphName ?? $relName;
            $extraParams = ", '{$morphName}'";
        }

        $lines = [];

        if ($isCollection) {
            $lines[] = "    #[{$attrName}({$actionSchemaClassName}::class{$extraParams})]";
            $lines[] = "    /** @var Collection<int, {$actionSchemaClassName}> */";
            $lines[] = "    public Collection \${$relName};";
        } else {
            $isNullable = array_key_exists($relName, $nullableOverrides)
                ? $nullableOverrides[$relName]
                : $rel->nullable;
            $nullable = $isNullable ? '?' : '';

            $lines[] = "    #[{$attrName}({$actionSchemaClassName}::class{$extraParams})]";
            $lines[] = "    public {$nullable}{$actionSchemaClassName} \${$relName};";
        }

        return $lines;
    }

    /**
     * Build the DataSchema class name from the action class name and relationship name.
     *
     * E.g., "CreateRecordRecordAction" + "properties" → "CreateRecordPropertiesDataSchema"
     */
    private function buildDataSchemaClassName(string $actionClassName, string $relName): string
    {
        // Strip "Action" suffix, append capitalized relation name + "DataSchema"
        $base = preg_replace('/Action$/', '', $actionClassName);

        return $base.ucfirst(Str::camel($relName)).'DataSchema';
    }

    /**
     * Build DataSchema class code strings for all nested relationships.
     *
     * @param  array<string, string[]>  $nestedGroups
     * @param  array<string, bool>  $nullableOverrides
     * @return string[] Array of PHP class code strings
     */
    private function buildDataSchemaClasses(
        TableDefinition $table,
        array $nestedGroups,
        string $actionClassName,
        string $schemaNamespace,
        array $nullableOverrides,
    ): array {
        $classes = [];

        foreach ($nestedGroups as $relName => $fields) {
            $rel = $this->findRelationshipByName($table, $relName);
            if ($rel === null) {
                continue;
            }

            $actionSchemaClassName = $this->buildDataSchemaClassName($actionClassName, $relName);
            $relatedModelBaseName = class_basename($rel->relatedModel);
            $relatedSchemaClassName = $relatedModelBaseName.'Schema';

            // Resolve columns from the related schema for type info
            $relatedSchemaClass = $schemaNamespace.'\\'.$relatedSchemaClassName;
            $relatedTable = null;
            if (class_exists($relatedSchemaClass)) {
                try {
                    $scanner = new SchemaScanner($relatedSchemaClass);
                    $relatedTable = $scanner->scan();
                } catch (\Throwable) {
                    // Schema can't be scanned
                }
            }

            $hasPivot = false;
            $propertyLines = [];

            foreach ($fields as $field) {
                if (str_contains($field, '.')) {
                    continue; // Deep nesting handled recursively in the future
                }

                $phpType = 'string'; // Default
                $isNullable = false;

                if ($relatedTable !== null) {
                    $column = $this->findColumn($relatedTable, $field);
                    if ($column !== null) {
                        $phpType = $this->phpType($column);
                        $isNullable = $column->nullable;
                    }
                }

                // Check nullable override
                $overrideKey = "{$relName}.*.{$field}";
                if (array_key_exists($overrideKey, $nullableOverrides)) {
                    $isNullable = $nullableOverrides[$overrideKey];
                }

                $nullable = $isNullable ? '?' : '';
                $propName = Str::camel($field);
                $propertyLines[] = "    public {$nullable}{$phpType} \${$propName};";
            }

            // Build the DataSchema class
            $classLines = [];
            $classLines[] = "class {$actionSchemaClassName} extends DataSchema";
            $classLines[] = '{';
            $classLines[] = "    protected static string \$schema = {$relatedSchemaClassName}::class;";

            if (! empty($propertyLines)) {
                $classLines[] = '';
                $classLines[] = implode("\n\n", $propertyLines);
            }

            $classLines[] = '}';

            $classes[] = implode("\n", $classLines);
        }

        return $classes;
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
