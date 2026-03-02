<?php

namespace SchemaCraft\Generator;

use Illuminate\Support\Str;

/**
 * Renders a PHP schema file from structured editor data.
 *
 * This is the inverse of SchemaScanner — it takes a SchemaEditorPayload
 * (representing UI-edited schema state) and produces valid PHP schema class content.
 * Mirrors the rendering patterns in SchemaFileGenerator::buildSchemaFileContent().
 */
class SchemaContentRenderer
{
    /**
     * Render the full PHP schema file content.
     */
    public function render(SchemaEditorPayload $payload): string
    {
        $imports = [];

        // Base import — all schemas extend Schema
        $imports[] = 'SchemaCraft\\Schema';

        // Separate columns: PK vs regular
        $idProperty = null;
        $columnProperties = [];

        foreach ($payload->columns as $col) {
            if ($col->primary) {
                $idProperty = $this->buildColumnProperty($col, $imports);
            } else {
                $columnProperties[] = $this->buildColumnProperty($col, $imports);
            }
        }

        // Separate relationships by type
        $belongsToProperties = [];
        $hasManyProperties = [];
        $belongsToManyProperties = [];
        $morphToProperties = [];

        foreach ($payload->relationships as $rel) {
            match ($rel->type) {
                'belongsTo' => $belongsToProperties[] = $this->buildBelongsToProperty($rel, $payload->modelNamespace, $imports),
                'hasOne' => $hasManyProperties[] = $this->buildHasOneProperty($rel, $payload->modelNamespace, $imports),
                'hasMany' => $hasManyProperties[] = $this->buildHasManyProperty($rel, $payload->modelNamespace, $imports),
                'belongsToMany' => $belongsToManyProperties[] = $this->buildBelongsToManyProperty($rel, $payload->modelNamespace, $imports),
                'morphTo' => $morphToProperties[] = $this->buildMorphToProperty($rel, $imports),
                'morphOne' => $hasManyProperties[] = $this->buildMorphOneProperty($rel, $payload->modelNamespace, $imports),
                'morphMany' => $hasManyProperties[] = $this->buildMorphManyProperty($rel, $payload->modelNamespace, $imports),
                'morphToMany' => $belongsToManyProperties[] = $this->buildMorphToManyProperty($rel, $payload->modelNamespace, $imports),
                default => null,
            };
        }

        // Traits
        if ($payload->hasTimestamps) {
            $imports[] = 'SchemaCraft\\Traits\\TimestampsSchema';
        }
        if ($payload->hasSoftDeletes) {
            $imports[] = 'SchemaCraft\\Traits\\SoftDeletesSchema';
        }

        // Eloquent Relations alias if any relationships exist
        $allRelationships = array_merge($belongsToProperties, $hasManyProperties, $belongsToManyProperties, $morphToProperties);
        if (count($allRelationships) > 0) {
            $imports[] = 'Illuminate\\Database\\Eloquent\\Relations as Eloquent';
        }

        // Composite indexes
        $compositeIndexAttrs = [];
        foreach ($payload->compositeIndexes as $indexColumns) {
            $colList = implode("', '", $indexColumns);
            $compositeIndexAttrs[] = "#[Index(['{$colList}'])]";
            $imports[] = 'SchemaCraft\\Attributes\\Index';
        }

        // Build the file
        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = "namespace {$payload->schemaNamespace};";
        $lines[] = '';

        // Sorted, deduplicated imports
        $sortedImports = $this->sortImports(array_unique($imports));
        foreach ($sortedImports as $import) {
            $lines[] = "use {$import};";
        }

        $lines[] = '';

        // @method docblock for relationships
        if (count($allRelationships) > 0) {
            $lines[] = '/**';
            foreach ($allRelationships as $rel) {
                $eloquentType = $this->relationshipToEloquentType($rel);
                $returnType = $this->relationshipToReturnType($rel);
                $lines[] = " * @method Eloquent\\{$eloquentType}|{$returnType} {$rel->name}()";
            }
            $lines[] = ' */';
        }

        // Class-level composite index attributes
        foreach ($compositeIndexAttrs as $attr) {
            $lines[] = $attr;
        }

        $lines[] = "class {$payload->schemaName} extends Schema";
        $lines[] = '{';

        // Traits
        if ($payload->hasTimestamps) {
            $lines[] = '    use TimestampsSchema;';
        }
        if ($payload->hasSoftDeletes) {
            $lines[] = '    use SoftDeletesSchema;';
        }

        // Custom table name
        if ($payload->tableName !== null) {
            $lines[] = '';
            $lines[] = '    public static function tableName(): ?string';
            $lines[] = '    {';
            $lines[] = "        return '{$payload->tableName}';";
            $lines[] = '    }';
        }

        // Connection override
        if ($payload->connection !== null) {
            $lines[] = '';
            $lines[] = "    protected static ?string \$connection = '{$payload->connection}';";
        }

        // ID property
        if ($idProperty !== null) {
            $lines[] = '';
            $this->renderProperty($lines, $idProperty);
        }

        // Regular columns
        foreach ($columnProperties as $prop) {
            $lines[] = '';
            $this->renderProperty($lines, $prop);
        }

        // BelongsTo
        foreach ($belongsToProperties as $prop) {
            $lines[] = '';
            $this->renderProperty($lines, $prop);
        }

        // MorphTo
        foreach ($morphToProperties as $prop) {
            $lines[] = '';
            $this->renderProperty($lines, $prop);
        }

        // HasOne / HasMany / MorphOne / MorphMany
        foreach ($hasManyProperties as $prop) {
            $lines[] = '';
            $this->renderProperty($lines, $prop);
        }

        // BelongsToMany / MorphToMany
        foreach ($belongsToManyProperties as $prop) {
            $lines[] = '';
            $this->renderProperty($lines, $prop);
        }

        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Render the model file content for a schema.
     */
    public function renderModel(SchemaEditorPayload $payload): string
    {
        $modelName = Str::replaceLast('Schema', '', $payload->schemaName);

        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = "namespace {$payload->modelNamespace};";
        $lines[] = '';

        if ($payload->hasSoftDeletes) {
            $lines[] = 'use Illuminate\\Database\\Eloquent\\SoftDeletes;';
        }
        $lines[] = "use {$payload->schemaNamespace}\\{$payload->schemaName};";

        $lines[] = '';
        $lines[] = '/**';
        $lines[] = " * @mixin {$payload->schemaName}";
        $lines[] = ' */';
        $lines[] = "class {$modelName} extends BaseModel";
        $lines[] = '{';

        if ($payload->hasSoftDeletes) {
            $lines[] = '    use SoftDeletes;';
            $lines[] = '';
        }

        $lines[] = "    protected static string \$schema = {$payload->schemaName}::class;";

        if ($payload->connection !== null) {
            $lines[] = '';
            $lines[] = "    protected \$connection = '{$payload->connection}';";
        }

        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Build a GeneratedProperty for a regular column (including PK).
     *
     * @param  string[]  $imports  Passed by reference to collect needed imports
     */
    private function buildColumnProperty(EditorColumn $col, array &$imports): GeneratedProperty
    {
        $attributes = [];

        // Primary key
        if ($col->primary) {
            $attributes[] = '#[Primary]';
            $imports[] = 'SchemaCraft\\Attributes\\Primary';
        }

        // AutoIncrement
        if ($col->autoIncrement) {
            $attributes[] = '#[AutoIncrement]';
            $imports[] = 'SchemaCraft\\Attributes\\AutoIncrement';
        }

        // ColumnType override (freeform, e.g. 'uuid', 'ulid')
        if ($col->columnType !== null) {
            $attributes[] = "#[ColumnType('{$col->columnType}')]";
            $imports[] = 'SchemaCraft\\Attributes\\ColumnType';
        }

        // Type override attributes
        if ($col->typeOverride !== null) {
            match ($col->typeOverride) {
                'Text' => (function () use (&$attributes, &$imports) {
                    $attributes[] = '#[Text]';
                    $imports[] = 'SchemaCraft\\Attributes\\Text';
                })(),
                'MediumText' => (function () use (&$attributes, &$imports) {
                    $attributes[] = '#[MediumText]';
                    $imports[] = 'SchemaCraft\\Attributes\\MediumText';
                })(),
                'LongText' => (function () use (&$attributes, &$imports) {
                    $attributes[] = '#[LongText]';
                    $imports[] = 'SchemaCraft\\Attributes\\LongText';
                })(),
                'BigInt' => (function () use (&$attributes, &$imports) {
                    $attributes[] = '#[BigInt]';
                    $imports[] = 'SchemaCraft\\Attributes\\BigInt';
                })(),
                'SmallInt' => (function () use (&$attributes, &$imports) {
                    $attributes[] = '#[SmallInt]';
                    $imports[] = 'SchemaCraft\\Attributes\\SmallInt';
                })(),
                'TinyInt' => (function () use (&$attributes, &$imports) {
                    $attributes[] = '#[TinyInt]';
                    $imports[] = 'SchemaCraft\\Attributes\\TinyInt';
                })(),
                'FloatColumn' => (function () use (&$attributes, &$imports) {
                    $attributes[] = '#[FloatColumn]';
                    $imports[] = 'SchemaCraft\\Attributes\\FloatColumn';
                })(),
                'Decimal' => (function () use ($col, &$attributes, &$imports) {
                    $p = $col->precision ?? 8;
                    $s = $col->scale ?? 2;
                    $attributes[] = "#[Decimal({$p}, {$s})]";
                    $imports[] = 'SchemaCraft\\Attributes\\Decimal';
                })(),
                'Date' => (function () use (&$attributes, &$imports) {
                    $attributes[] = '#[Date]';
                    $imports[] = 'SchemaCraft\\Attributes\\Date';
                })(),
                'Time' => (function () use (&$attributes, &$imports) {
                    $attributes[] = '#[Time]';
                    $imports[] = 'SchemaCraft\\Attributes\\Time';
                })(),
                'Year' => (function () use (&$attributes, &$imports) {
                    $attributes[] = '#[Year]';
                    $imports[] = 'SchemaCraft\\Attributes\\Year';
                })(),
                default => null,
            };
        }

        // Unsigned
        if ($col->unsigned && ! $col->autoIncrement) {
            $attributes[] = '#[Unsigned]';
            $imports[] = 'SchemaCraft\\Attributes\\Unsigned';
        }

        // Length
        if ($col->length !== null && $col->phpType === 'string' && $col->length !== 255) {
            $attributes[] = "#[Length({$col->length})]";
            $imports[] = 'SchemaCraft\\Attributes\\Length';
        }

        // Unique
        if ($col->unique) {
            $attributes[] = '#[Unique]';
            $imports[] = 'SchemaCraft\\Attributes\\Unique';
        }

        // Index
        if ($col->index) {
            $attributes[] = '#[Index]';
            $imports[] = 'SchemaCraft\\Attributes\\Index';
        }

        // Expression default
        if ($col->expressionDefault !== null) {
            $attributes[] = "#[DefaultExpression('{$col->expressionDefault}')]";
            $imports[] = 'SchemaCraft\\Attributes\\DefaultExpression';
        }

        // Fillable
        if ($col->fillable) {
            $attributes[] = '#[Fillable]';
            $imports[] = 'SchemaCraft\\Attributes\\Fillable';
        }

        // Hidden
        if ($col->hidden) {
            $attributes[] = '#[Hidden]';
            $imports[] = 'SchemaCraft\\Attributes\\Hidden';
        }

        // Cast
        if ($col->castClass !== null) {
            $castBaseName = class_basename($col->castClass);
            $attributes[] = "#[Cast({$castBaseName}::class)]";
            $imports[] = 'SchemaCraft\\Attributes\\Cast';
            $imports[] = $col->castClass;
        }

        // Rules
        if ($col->rules !== null && count($col->rules) > 0) {
            $ruleList = implode("', '", $col->rules);
            $attributes[] = "#[Rules('{$ruleList}')]";
            $imports[] = 'SchemaCraft\\Attributes\\Rules';
        }

        // RenamedFrom
        if ($col->renamedFrom !== null) {
            $attributes[] = "#[RenamedFrom('{$col->renamedFrom}')]";
            $imports[] = 'SchemaCraft\\Attributes\\RenamedFrom';
        }

        // Determine PHP type for the property declaration
        $phpType = $this->resolvePhpTypeName($col);

        // Carbon needs import
        if ($phpType === 'Carbon') {
            $imports[] = 'Illuminate\\Support\\Carbon';
        }

        return new GeneratedProperty(
            name: $col->name,
            phpType: $phpType,
            nullable: $col->nullable,
            attributes: $attributes,
            default: $col->default,
            hasDefault: $col->hasDefault,
        );
    }

    /**
     * Build a GeneratedProperty for a BelongsTo relationship.
     *
     * @param  string[]  $imports
     */
    private function buildBelongsToProperty(EditorRelationship $rel, string $modelNamespace, array &$imports): GeneratedProperty
    {
        $relatedBaseName = class_basename($rel->relatedModel);
        $attributes = ["#[BelongsTo({$relatedBaseName}::class)]"];
        $imports[] = 'SchemaCraft\\Attributes\\Relations\\BelongsTo';
        $imports[] = $rel->relatedModel;

        if ($rel->onDelete !== null) {
            $attributes[] = "#[OnDelete('{$rel->onDelete}')]";
            $imports[] = 'SchemaCraft\\Attributes\\OnDelete';
        }

        if ($rel->onUpdate !== null) {
            $attributes[] = "#[OnUpdate('{$rel->onUpdate}')]";
            $imports[] = 'SchemaCraft\\Attributes\\OnUpdate';
        }

        if ($rel->foreignColumn !== null) {
            $attributes[] = "#[ForeignColumn('{$rel->foreignColumn}')]";
            $imports[] = 'SchemaCraft\\Attributes\\ForeignColumn';
        }

        if ($rel->noConstraint) {
            $attributes[] = '#[NoConstraint]';
            $imports[] = 'SchemaCraft\\Attributes\\NoConstraint';
        }

        if ($rel->index) {
            $attributes[] = '#[Index]';
            $imports[] = 'SchemaCraft\\Attributes\\Index';
        }

        if ($rel->columnType !== null) {
            $attributes[] = "#[ColumnType('{$rel->columnType}')]";
            $imports[] = 'SchemaCraft\\Attributes\\ColumnType';
        }

        if ($rel->with) {
            $attributes[] = '#[With]';
            $imports[] = 'SchemaCraft\\Attributes\\With';
        }

        return new GeneratedProperty(
            name: $rel->name,
            phpType: $relatedBaseName,
            nullable: $rel->nullable,
            attributes: $attributes,
            isRelationship: true,
        );
    }

    /**
     * Build a GeneratedProperty for a HasOne relationship.
     *
     * @param  string[]  $imports
     */
    private function buildHasOneProperty(EditorRelationship $rel, string $modelNamespace, array &$imports): GeneratedProperty
    {
        $relatedBaseName = class_basename($rel->relatedModel);
        $attributes = ["#[HasOne({$relatedBaseName}::class)]"];
        $imports[] = 'SchemaCraft\\Attributes\\Relations\\HasOne';
        $imports[] = $rel->relatedModel;

        if ($rel->foreignColumn !== null) {
            $attributes[] = "#[ForeignColumn('{$rel->foreignColumn}')]";
            $imports[] = 'SchemaCraft\\Attributes\\ForeignColumn';
        }

        if ($rel->with) {
            $attributes[] = '#[With]';
            $imports[] = 'SchemaCraft\\Attributes\\With';
        }

        return new GeneratedProperty(
            name: $rel->name,
            phpType: $relatedBaseName,
            nullable: $rel->nullable,
            attributes: $attributes,
            isRelationship: true,
        );
    }

    /**
     * Build a GeneratedProperty for a HasMany relationship.
     *
     * @param  string[]  $imports
     */
    private function buildHasManyProperty(EditorRelationship $rel, string $modelNamespace, array &$imports): GeneratedProperty
    {
        $relatedBaseName = class_basename($rel->relatedModel);
        $attributes = ["#[HasMany({$relatedBaseName}::class)]"];
        $imports[] = 'SchemaCraft\\Attributes\\Relations\\HasMany';
        $imports[] = $rel->relatedModel;
        $imports[] = 'Illuminate\\Database\\Eloquent\\Collection';

        if ($rel->foreignColumn !== null) {
            $attributes[] = "#[ForeignColumn('{$rel->foreignColumn}')]";
            $imports[] = 'SchemaCraft\\Attributes\\ForeignColumn';
        }

        if ($rel->with) {
            $attributes[] = '#[With]';
            $imports[] = 'SchemaCraft\\Attributes\\With';
        }

        return new GeneratedProperty(
            name: $rel->name,
            phpType: 'Collection',
            isRelationship: true,
            attributes: $attributes,
            docBlock: "/** @var Collection<int, {$relatedBaseName}> */",
        );
    }

    /**
     * Build a GeneratedProperty for a BelongsToMany relationship.
     *
     * @param  string[]  $imports
     */
    private function buildBelongsToManyProperty(EditorRelationship $rel, string $modelNamespace, array &$imports): GeneratedProperty
    {
        $relatedBaseName = class_basename($rel->relatedModel);
        $attributes = ["#[BelongsToMany({$relatedBaseName}::class)]"];
        $imports[] = 'SchemaCraft\\Attributes\\Relations\\BelongsToMany';
        $imports[] = $rel->relatedModel;
        $imports[] = 'Illuminate\\Database\\Eloquent\\Collection';

        if ($rel->pivotTable !== null) {
            $attributes[] = "#[PivotTable('{$rel->pivotTable}')]";
            $imports[] = 'SchemaCraft\\Attributes\\PivotTable';
        }

        if ($rel->pivotColumns !== null && count($rel->pivotColumns) > 0) {
            $colPairs = [];
            foreach ($rel->pivotColumns as $colName => $colType) {
                $colPairs[] = "'{$colName}' => '{$colType}'";
            }
            $attributes[] = '#[PivotColumns(['.implode(', ', $colPairs).'])]';
            $imports[] = 'SchemaCraft\\Attributes\\PivotColumns';
        }

        if ($rel->pivotModel !== null) {
            $pivotBaseName = class_basename($rel->pivotModel);
            $attributes[] = "#[UsingPivot({$pivotBaseName}::class)]";
            $imports[] = 'SchemaCraft\\Attributes\\UsingPivot';
            $imports[] = $rel->pivotModel;
        }

        if ($rel->with) {
            $attributes[] = '#[With]';
            $imports[] = 'SchemaCraft\\Attributes\\With';
        }

        return new GeneratedProperty(
            name: $rel->name,
            phpType: 'Collection',
            isRelationship: true,
            attributes: $attributes,
            docBlock: "/** @var Collection<int, {$relatedBaseName}> */",
        );
    }

    /**
     * Build a GeneratedProperty for a MorphTo relationship.
     *
     * @param  string[]  $imports
     */
    private function buildMorphToProperty(EditorRelationship $rel, array &$imports): GeneratedProperty
    {
        $morphName = $rel->morphName ?? $rel->name;
        $attributes = ["#[MorphTo('{$morphName}')]"];
        $imports[] = 'SchemaCraft\\Attributes\\Relations\\MorphTo';
        $imports[] = 'Illuminate\\Database\\Eloquent\\Model';

        if ($rel->index) {
            $attributes[] = '#[Index]';
            $imports[] = 'SchemaCraft\\Attributes\\Index';
        }

        if ($rel->columnType !== null) {
            $attributes[] = "#[ColumnType('{$rel->columnType}')]";
            $imports[] = 'SchemaCraft\\Attributes\\ColumnType';
        }

        if ($rel->with) {
            $attributes[] = '#[With]';
            $imports[] = 'SchemaCraft\\Attributes\\With';
        }

        return new GeneratedProperty(
            name: $morphName,
            phpType: 'Model',
            nullable: $rel->nullable,
            isRelationship: true,
            attributes: $attributes,
        );
    }

    /**
     * Build a GeneratedProperty for a MorphOne relationship.
     *
     * @param  string[]  $imports
     */
    private function buildMorphOneProperty(EditorRelationship $rel, string $modelNamespace, array &$imports): GeneratedProperty
    {
        $relatedBaseName = class_basename($rel->relatedModel);
        $morphName = $rel->morphName ?? $rel->name;
        $attributes = ["#[MorphOne({$relatedBaseName}::class, '{$morphName}')]"];
        $imports[] = 'SchemaCraft\\Attributes\\Relations\\MorphOne';
        $imports[] = $rel->relatedModel;

        if ($rel->with) {
            $attributes[] = '#[With]';
            $imports[] = 'SchemaCraft\\Attributes\\With';
        }

        return new GeneratedProperty(
            name: $rel->name,
            phpType: $relatedBaseName,
            nullable: $rel->nullable,
            isRelationship: true,
            attributes: $attributes,
        );
    }

    /**
     * Build a GeneratedProperty for a MorphMany relationship.
     *
     * @param  string[]  $imports
     */
    private function buildMorphManyProperty(EditorRelationship $rel, string $modelNamespace, array &$imports): GeneratedProperty
    {
        $relatedBaseName = class_basename($rel->relatedModel);
        $morphName = $rel->morphName ?? $rel->name;
        $attributes = ["#[MorphMany({$relatedBaseName}::class, '{$morphName}')]"];
        $imports[] = 'SchemaCraft\\Attributes\\Relations\\MorphMany';
        $imports[] = $rel->relatedModel;
        $imports[] = 'Illuminate\\Database\\Eloquent\\Collection';

        if ($rel->with) {
            $attributes[] = '#[With]';
            $imports[] = 'SchemaCraft\\Attributes\\With';
        }

        return new GeneratedProperty(
            name: $rel->name,
            phpType: 'Collection',
            isRelationship: true,
            attributes: $attributes,
            docBlock: "/** @var Collection<int, {$relatedBaseName}> */",
        );
    }

    /**
     * Build a GeneratedProperty for a MorphToMany relationship.
     *
     * @param  string[]  $imports
     */
    private function buildMorphToManyProperty(EditorRelationship $rel, string $modelNamespace, array &$imports): GeneratedProperty
    {
        $relatedBaseName = class_basename($rel->relatedModel);
        $morphName = $rel->morphName ?? $rel->name;
        $attributes = ["#[MorphToMany({$relatedBaseName}::class, '{$morphName}')]"];
        $imports[] = 'SchemaCraft\\Attributes\\Relations\\MorphToMany';
        $imports[] = $rel->relatedModel;
        $imports[] = 'Illuminate\\Database\\Eloquent\\Collection';

        if ($rel->with) {
            $attributes[] = '#[With]';
            $imports[] = 'SchemaCraft\\Attributes\\With';
        }

        return new GeneratedProperty(
            name: $rel->name,
            phpType: 'Collection',
            isRelationship: true,
            attributes: $attributes,
            docBlock: "/** @var Collection<int, {$relatedBaseName}> */",
        );
    }

    /**
     * Resolve the PHP type name for a column's property declaration.
     */
    private function resolvePhpTypeName(EditorColumn $col): string
    {
        // Auto-increment PKs are always int
        if ($col->primary && $col->autoIncrement) {
            return 'int';
        }

        // ColumnType overrides like 'uuid', 'ulid' map to string
        if ($col->columnType !== null) {
            return match ($col->columnType) {
                'uuid', 'ulid' => 'string',
                default => $col->phpType,
            };
        }

        return $col->phpType;
    }

    /**
     * Get the Eloquent relationship type name for a GeneratedProperty's @method docblock.
     */
    private function relationshipToEloquentType(GeneratedProperty $prop): string
    {
        foreach ($prop->attributes as $attr) {
            if (str_contains($attr, '#[BelongsTo(')) {
                return 'BelongsTo';
            }
            if (str_contains($attr, '#[HasOne(')) {
                return 'HasOne';
            }
            if (str_contains($attr, '#[HasMany(')) {
                return 'HasMany';
            }
            if (str_contains($attr, '#[BelongsToMany(')) {
                return 'BelongsToMany';
            }
            if (str_contains($attr, '#[MorphTo(')) {
                return 'MorphTo';
            }
            if (str_contains($attr, '#[MorphOne(')) {
                return 'MorphOne';
            }
            if (str_contains($attr, '#[MorphMany(')) {
                return 'MorphMany';
            }
            if (str_contains($attr, '#[MorphToMany(')) {
                return 'MorphToMany';
            }
        }

        return 'BelongsTo';
    }

    /**
     * Get the return type for a relationship's @method docblock.
     */
    private function relationshipToReturnType(GeneratedProperty $prop): string
    {
        if ($prop->phpType === 'Collection') {
            foreach ($prop->attributes as $attr) {
                if (preg_match('/\[(?:HasMany|BelongsToMany|MorphMany|MorphToMany)\((\w+)::class/', $attr, $matches)) {
                    return $matches[1];
                }
            }
        }

        if ($prop->phpType === 'Model') {
            return 'Model';
        }

        return $prop->phpType;
    }

    /**
     * Render a GeneratedProperty as PHP lines (indented with 4 spaces).
     *
     * @param  string[]  $lines
     */
    private function renderProperty(array &$lines, GeneratedProperty $prop): void
    {
        if ($prop->docBlock !== null) {
            $lines[] = "    {$prop->docBlock}";
        }

        foreach ($prop->attributes as $attr) {
            $lines[] = "    {$attr}";
        }

        $typeStr = $prop->nullable ? "?{$prop->phpType}" : $prop->phpType;
        $defaultStr = '';

        if ($prop->hasDefault) {
            $defaultStr = ' = '.$this->renderDefault($prop->default, $prop->phpType);
        }

        $lines[] = "    public {$typeStr} \${$prop->name}{$defaultStr};";
    }

    /**
     * Render a default value as a PHP literal.
     */
    private function renderDefault(mixed $value, string $phpType): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            $str = (string) $value;
            if (! str_contains($str, '.')) {
                $str .= '.0';
            }

            return $str;
        }

        if (is_array($value) || $value === '[]' || $value === '{}') {
            return '[]';
        }

        if (is_string($value)) {
            if ($phpType === 'bool') {
                return $value ? 'true' : 'false';
            }
            if ($phpType === 'int') {
                return (string) (int) $value;
            }
            if ($phpType === 'float') {
                return (string) (float) $value;
            }

            return "'".addslashes($value)."'";
        }

        return "'".addslashes((string) $value)."'";
    }

    /**
     * Sort imports: App\* first, then Illuminate\*, then SchemaCraft\*, then others.
     *
     * @param  string[]  $imports
     * @return string[]
     */
    private function sortImports(array $imports): array
    {
        $groups = [
            'app' => [],
            'illuminate' => [],
            'schemaCraft' => [],
            'other' => [],
        ];

        foreach ($imports as $import) {
            if (str_starts_with($import, 'App\\')) {
                $groups['app'][] = $import;
            } elseif (str_starts_with($import, 'Illuminate\\')) {
                $groups['illuminate'][] = $import;
            } elseif (str_starts_with($import, 'SchemaCraft\\')) {
                $groups['schemaCraft'][] = $import;
            } else {
                $groups['other'][] = $import;
            }
        }

        foreach ($groups as &$group) {
            sort($group);
        }

        return array_merge($groups['app'], $groups['illuminate'], $groups['schemaCraft'], $groups['other']);
    }
}
