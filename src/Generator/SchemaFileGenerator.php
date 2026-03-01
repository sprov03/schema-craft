<?php

namespace SchemaCraft\Generator;

use Illuminate\Support\Str;
use SchemaCraft\Migration\DatabaseColumnState;
use SchemaCraft\Migration\DatabaseForeignKeyState;
use SchemaCraft\Migration\DatabaseTableState;

class SchemaFileGenerator
{
    /**
     * Columns managed by traits that should be skipped from individual generation.
     */
    private const TIMESTAMP_COLUMNS = ['created_at', 'updated_at'];

    private const SOFT_DELETE_COLUMNS = ['deleted_at'];

    /**
     * Generate schema and model file content for a single table.
     *
     * @param  DatabaseTableState  $table  The table to generate for
     * @param  array<string, DatabaseTableState>  $allTables  All tables keyed by name (for HasMany inference)
     * @param  array<string, string>  $pivotRelationships  Pivot table relationships: ['table_a' => ['table_b' => 'pivot_table_name']]
     * @param  string  $schemaNamespace  Namespace for generated schemas
     * @param  string  $modelNamespace  Namespace for generated models
     * @param  string  $schemaPrefix  Class name prefix for schemas (e.g. 'Prefix' → PrefixAccountSchema)
     * @param  string  $modelPrefix  Class name prefix for models (e.g. 'Prefix' → PrefixAccount)
     * @param  string|null  $connection  DB connection name to emit as $connection property (null = default, skip)
     */
    public function generate(
        DatabaseTableState $table,
        array $allTables = [],
        array $pivotRelationships = [],
        string $schemaNamespace = 'App\\Schemas',
        string $modelNamespace = 'App\\Models',
        string $schemaPrefix = '',
        string $modelPrefix = '',
        ?string $connection = null,
    ): GeneratedSchemaResult {
        $baseModelName = $this->resolveModelName($table->tableName);
        $modelName = $modelPrefix.$baseModelName;
        $schemaName = $schemaPrefix.$baseModelName.'Schema';

        // Check if convention-based table name matches actual table name (use base name without prefix)
        $conventionTableName = Str::snake(Str::pluralStudly($baseModelName));
        $customTableName = $conventionTableName !== $table->tableName ? $table->tableName : null;

        $hasTimestamps = $table->hasTimestamps();
        $hasSoftDeletes = $table->hasSoftDeletes();

        // Build skip list for managed columns
        $skipColumns = [];
        if ($hasTimestamps) {
            $skipColumns = array_merge($skipColumns, self::TIMESTAMP_COLUMNS);
        }
        if ($hasSoftDeletes) {
            $skipColumns = array_merge($skipColumns, self::SOFT_DELETE_COLUMNS);
        }

        // Collect FK column names so they are skipped from regular column generation.
        // All FK columns are now handled via BelongsTo (with #[ColumnType] for non-standard types).
        $fkColumnNames = [];
        foreach ($table->foreignKeys as $fk) {
            $fkColumnNames[] = $fk->column;
        }

        // Also detect MorphTo column pairs and skip both columns
        $morphToColumns = $this->detectMorphToColumns($table);
        foreach ($morphToColumns as $morph) {
            $skipColumns[] = $morph['typeColumn'];
            $skipColumns[] = $morph['idColumn'];
        }

        // Generate properties
        $idProperty = null;
        $columnProperties = [];
        $belongsToProperties = [];
        $hasManyProperties = [];
        $belongsToManyProperties = [];
        $morphToProperties = [];

        // Track imports
        $imports = [];

        // Always need Schema
        $imports[] = 'SchemaCraft\\Schema';

        // Process columns
        foreach ($table->columns as $column) {
            // Skip managed columns
            if (in_array($column->name, $skipColumns, true)) {
                continue;
            }

            // Skip FK columns (handled by BelongsTo)
            if (in_array($column->name, $fkColumnNames, true)) {
                continue;
            }

            // Handle primary key
            if ($column->primary && ($column->autoIncrement || in_array($column->type, ['uuid', 'ulid'], true))) {
                $idProperty = $this->resolveIdColumn($column);

                foreach ($idProperty->attributes as $attr) {
                    $importClass = $this->attributeToImport($attr);
                    if ($importClass !== null) {
                        $imports[] = $importClass;
                    }
                }

                continue;
            }

            // Non-auto-increment string PK (e.g. char(36) UUID without uuid type)
            if ($column->primary && $column->type === 'string' && ! $column->autoIncrement) {
                $attrs = ['#[Primary]'];
                $imports[] = 'SchemaCraft\\Attributes\\Primary';
                if ($column->length !== null && $column->length !== 255) {
                    $attrs[] = "#[Length({$column->length})]";
                    $imports[] = 'SchemaCraft\\Attributes\\Length';
                }
                $idProperty = new GeneratedProperty(
                    name: $column->name,
                    phpType: 'string',
                    attributes: $attrs,
                );

                continue;
            }

            // Regular column
            $prop = $this->resolveColumn($column, $table);
            $columnProperties[] = $prop;

            // Collect imports for attributes
            foreach ($prop->attributes as $attr) {
                $importClass = $this->attributeToImport($attr);
                if ($importClass !== null) {
                    $imports[] = $importClass;
                }
            }

            // Carbon import
            if ($prop->phpType === 'Carbon') {
                $imports[] = 'Illuminate\\Support\\Carbon';
            }
        }

        // If no explicit PK was found but the table has an 'id' column, try it
        if ($idProperty === null) {
            $idCol = $table->getColumn('id');
            if ($idCol !== null && $idCol->primary) {
                if ($idCol->type === 'string' && ! $idCol->autoIncrement) {
                    // Non-auto-increment string PK (e.g. char(36) UUID)
                    $attrs = ['#[Primary]'];
                    $imports[] = 'SchemaCraft\\Attributes\\Primary';
                    if ($idCol->length !== null && $idCol->length !== 255) {
                        $attrs[] = "#[Length({$idCol->length})]";
                        $imports[] = 'SchemaCraft\\Attributes\\Length';
                    }
                    $idProperty = new GeneratedProperty(
                        name: $idCol->name,
                        phpType: 'string',
                        attributes: $attrs,
                    );
                    // Remove from column properties if already generated
                    $columnProperties = array_values(array_filter(
                        $columnProperties,
                        fn (GeneratedProperty $p) => $p->name !== 'id',
                    ));
                } else {
                    $idProperty = $this->resolveIdColumn($idCol);

                    foreach ($idProperty->attributes as $attr) {
                        $importClass = $this->attributeToImport($attr);
                        if ($importClass !== null) {
                            $imports[] = $importClass;
                        }
                    }
                }
            }
        }

        // Build set of column property names to detect collisions with BelongsTo
        $columnPropertyNames = [];
        foreach ($columnProperties as $prop) {
            $columnPropertyNames[$prop->name] = true;
        }

        // Process BelongsTo relationships from foreign keys
        foreach ($table->foreignKeys as $fk) {
            $prop = $this->resolveBelongsTo($fk, $table, $modelNamespace, $modelPrefix);

            // Resolve collision: if the BelongsTo property name matches a column name,
            // rename the BelongsTo property to avoid PHP redeclaration error.
            // e.g. table has `status` (int column) AND `status_id` FK → both want `$status`
            // Solution: rename relationship `$status` → `$statusRelation` with #[ForeignColumn('status_id')]
            if (isset($columnPropertyNames[$prop->name])) {
                $newName = $prop->name.'Relation';
                $attrs = $prop->attributes;
                $attrs[] = "#[ForeignColumn('{$fk->column}')]";
                $imports[] = 'SchemaCraft\\Attributes\\ForeignColumn';

                $prop = new GeneratedProperty(
                    name: $newName,
                    phpType: $prop->phpType,
                    nullable: $prop->nullable,
                    attributes: $attrs,
                    isRelationship: true,
                );
            }

            $belongsToProperties[] = $prop;

            $relatedModel = $modelPrefix.$this->resolveModelName($fk->foreignTable);
            $imports[] = $modelNamespace.'\\'.$relatedModel;
            $imports[] = 'SchemaCraft\\Attributes\\Relations\\BelongsTo';

            foreach ($prop->attributes as $attr) {
                $importClass = $this->attributeToImport($attr);
                if ($importClass !== null) {
                    $imports[] = $importClass;
                }
            }
        }

        // Process MorphTo relationships
        foreach ($morphToColumns as $morph) {
            $morphName = $morph['morphName'];
            $attrs = ["#[MorphTo('{$morphName}')]"];

            // If DB has individual indexes on both _type and _id columns, add #[Index]
            // so SchemaScanner will set index:true on the generated MorphTo columns.
            // Composite indexes are handled separately by resolveCompositeIndexes().
            if (! empty($morph['hasTypeIndex']) && ! empty($morph['hasIdIndex'])) {
                $attrs[] = '#[Index]';
                $imports[] = 'SchemaCraft\\Attributes\\Index';
            }

            // Add #[ColumnType] when the _id column type is not the default unsignedBigInteger.
            $idType = $morph['idColumnType'] ?? 'unsignedBigInteger';
            if (! in_array($idType, ['bigInteger', 'unsignedBigInteger'], true)) {
                $columnTypeValue = $idType;
                if (($morph['idColumnUnsigned'] ?? false) && ! str_starts_with(strtolower($columnTypeValue), 'unsigned')) {
                    $columnTypeValue = 'unsigned'.ucfirst($columnTypeValue);
                }
                $attrs[] = "#[ColumnType('{$columnTypeValue}')]";
                $imports[] = 'SchemaCraft\\Attributes\\ColumnType';
            }

            $morphToProperties[] = new GeneratedProperty(
                name: $morphName,
                phpType: 'Model',
                nullable: $morph['nullable'] ?? false,
                isRelationship: true,
                attributes: $attrs,
            );
            $imports[] = 'Illuminate\\Database\\Eloquent\\Model';
            $imports[] = 'SchemaCraft\\Attributes\\Relations\\MorphTo';
        }

        // Process HasMany inverses (look at all tables for FKs pointing to this table)
        // Collect candidates first, then detect naming collisions
        /** @var array<string, array<array{fkColumn: string, otherTableName: string, relatedModel: string}>> */
        $hasManyCollector = [];

        foreach ($allTables as $otherTableName => $otherTable) {
            foreach ($otherTable->foreignKeys as $fk) {
                if ($fk->foreignTable === $table->tableName) {
                    $relatedModel = $modelPrefix.$this->resolveModelName($otherTableName);
                    $hasManyCollector[$relatedModel][] = [
                        'fkColumn' => $fk->column,
                        'otherTableName' => $otherTableName,
                        'relatedModel' => $relatedModel,
                    ];
                }
            }
        }

        foreach ($hasManyCollector as $relatedModel => $entries) {
            $hasCollision = count($entries) > 1;

            foreach ($entries as $entry) {
                if ($hasCollision) {
                    $propertyName = $this->fkColumnToHasManyName($entry['fkColumn'], $entry['relatedModel']);
                    $attrs = [
                        "#[HasMany({$entry['relatedModel']}::class)]",
                        "#[ForeignColumn('{$entry['fkColumn']}')]",
                    ];
                    $imports[] = 'SchemaCraft\\Attributes\\ForeignColumn';
                } else {
                    $propertyName = Str::camel(Str::plural($entry['relatedModel']));
                    $attrs = ["#[HasMany({$entry['relatedModel']}::class)]"];
                }

                $hasManyProperties[] = new GeneratedProperty(
                    name: $propertyName,
                    phpType: 'Collection',
                    isRelationship: true,
                    attributes: $attrs,
                    docBlock: "/** @var Collection<int, {$entry['relatedModel']}> */",
                );

                $imports[] = $modelNamespace.'\\'.$entry['relatedModel'];
                $imports[] = 'SchemaCraft\\Attributes\\Relations\\HasMany';
                $imports[] = 'Illuminate\\Database\\Eloquent\\Collection';
            }
        }

        // Build set of HasMany property names to detect BelongsToMany collisions
        $hasManyPropertyNames = [];
        foreach ($hasManyProperties as $prop) {
            $hasManyPropertyNames[$prop->name] = true;
        }

        // Process BelongsToMany from pivot tables
        foreach ($pivotRelationships as $relatedTable => $pivotTableName) {
            $relatedModel = $modelPrefix.$this->resolveModelName($relatedTable);
            $propertyName = Str::camel(Str::plural($relatedModel));

            // If this name collides with a HasMany, suffix with "Pivot"
            if (isset($hasManyPropertyNames[$propertyName])) {
                $propertyName = $propertyName.'Pivot';
            }

            $attrs = ["#[BelongsToMany({$relatedModel}::class)]"];

            // Add PivotTable attribute if the name doesn't follow convention
            $expectedPivot = $this->expectedPivotTableName($table->tableName, $relatedTable);
            if ($pivotTableName !== $expectedPivot) {
                $attrs[] = "#[PivotTable('{$pivotTableName}')]";
                $imports[] = 'SchemaCraft\\Attributes\\PivotTable';
            }

            $belongsToManyProperties[] = new GeneratedProperty(
                name: $propertyName,
                phpType: 'Collection',
                isRelationship: true,
                attributes: $attrs,
                docBlock: "/** @var Collection<int, {$relatedModel}> */",
            );

            $imports[] = $modelNamespace.'\\'.$relatedModel;
            $imports[] = 'SchemaCraft\\Attributes\\Relations\\BelongsToMany';
            $imports[] = 'Illuminate\\Database\\Eloquent\\Collection';
        }

        // Timestamps / SoftDeletes imports
        if ($hasTimestamps) {
            $imports[] = 'SchemaCraft\\Traits\\TimestampsSchema';
        }
        if ($hasSoftDeletes) {
            $imports[] = 'SchemaCraft\\Traits\\SoftDeletesSchema';
        }

        // Add Eloquent Relations alias if there are any relationships
        $allRelationships = array_merge($belongsToProperties, $hasManyProperties, $belongsToManyProperties, $morphToProperties);
        if (count($allRelationships) > 0) {
            $imports[] = 'Illuminate\\Database\\Eloquent\\Relations as Eloquent';
        }

        // Detect composite indexes (don't skip MorphTo columns — they may have composite indexes)
        $morphToColumnNames = [];
        foreach ($morphToColumns as $morph) {
            $morphToColumnNames[] = $morph['typeColumn'];
            $morphToColumnNames[] = $morph['idColumn'];
        }
        $compositeSkipColumns = array_diff($skipColumns, $morphToColumnNames);
        // Only skip timestamp/softDelete columns from composite indexes.
        // FK and MorphTo columns should NOT be skipped — they can participate in composite indexes.
        $compositeIndexAttrs = $this->resolveCompositeIndexes($table, $compositeSkipColumns);

        // Build file content
        $schemaContent = $this->buildSchemaFileContent(
            schemaNamespace: $schemaNamespace,
            schemaName: $schemaName,
            imports: $imports,
            hasTimestamps: $hasTimestamps,
            hasSoftDeletes: $hasSoftDeletes,
            idProperty: $idProperty,
            columnProperties: $columnProperties,
            belongsToProperties: $belongsToProperties,
            hasManyProperties: $hasManyProperties,
            belongsToManyProperties: $belongsToManyProperties,
            morphToProperties: $morphToProperties,
            compositeIndexAttrs: $compositeIndexAttrs,
            customTableName: $customTableName,
            connection: $connection,
        );

        $modelContent = $this->buildModelFileContent(
            modelNamespace: $modelNamespace,
            schemaNamespace: $schemaNamespace,
            modelName: $modelName,
            schemaName: $schemaName,
            hasSoftDeletes: $hasSoftDeletes,
            connection: $connection,
        );

        return new GeneratedSchemaResult(
            schemaContent: $schemaContent,
            modelContent: $modelContent,
            schemaClassName: $schemaName,
            modelClassName: $modelName,
            hasSoftDeletes: $hasSoftDeletes,
        );
    }

    /**
     * Resolve a model name from a table name.
     */
    public function resolveModelName(string $tableName): string
    {
        return Str::studly(Str::singular($tableName));
    }

    /**
     * Detect if a table is a pivot table.
     *
     * A pivot table has exactly 2 FK columns and no other non-PK/timestamp columns.
     *
     * @return array{tableA: string, tableB: string}|null
     */
    public function detectPivotTable(DatabaseTableState $table): ?array
    {
        if (count($table->foreignKeys) !== 2) {
            return null;
        }

        $nonManagedColumns = [];
        $managedNames = array_merge(self::TIMESTAMP_COLUMNS, self::SOFT_DELETE_COLUMNS);
        $fkColumnNames = array_map(fn (DatabaseForeignKeyState $fk) => $fk->column, $table->foreignKeys);

        foreach ($table->columns as $column) {
            if ($column->primary && $column->autoIncrement) {
                continue;
            }
            if (in_array($column->name, $managedNames, true)) {
                continue;
            }
            if (in_array($column->name, $fkColumnNames, true)) {
                continue;
            }

            $nonManagedColumns[] = $column;
        }

        if (count($nonManagedColumns) > 0) {
            return null;
        }

        return [
            'tableA' => $table->foreignKeys[0]->foreignTable,
            'tableB' => $table->foreignKeys[1]->foreignTable,
        ];
    }

    /**
     * Resolve the primary key column to a GeneratedProperty.
     */
    private function resolveIdColumn(DatabaseColumnState $column): GeneratedProperty
    {
        if ($column->type === 'uuid') {
            return new GeneratedProperty(
                name: $column->name,
                phpType: 'string',
                attributes: ['#[Primary]', "#[ColumnType('uuid')]"],
            );
        }

        if ($column->type === 'ulid') {
            return new GeneratedProperty(
                name: $column->name,
                phpType: 'string',
                attributes: ['#[Primary]', "#[ColumnType('ulid')]"],
            );
        }

        return new GeneratedProperty(
            name: $column->name,
            phpType: 'int',
            attributes: ['#[Primary]', '#[AutoIncrement]'],
        );
    }

    /**
     * Resolve a regular column to a GeneratedProperty with reverse type mapping.
     */
    private function resolveColumn(DatabaseColumnState $column, DatabaseTableState $table): GeneratedProperty
    {
        $phpType = $this->canonicalToPhpType($column->type);
        $attributes = [];

        // Type-specific attributes
        match ($column->type) {
            'text' => $attributes[] = '#[Text]',
            'mediumText' => $attributes[] = '#[MediumText]',
            'longText' => $attributes[] = '#[LongText]',
            'bigInteger', 'unsignedBigInteger' => $attributes[] = '#[BigInt]',
            'smallInteger', 'unsignedSmallInteger' => $attributes[] = '#[SmallInt]',
            'tinyInteger', 'unsignedTinyInteger' => $attributes[] = '#[TinyInt]',
            'float' => $attributes[] = '#[FloatColumn]',
            'decimal' => $attributes[] = $this->decimalAttribute($column),
            'date' => $attributes[] = '#[Date]',
            'time' => $attributes[] = '#[Time]',
            'year' => $attributes[] = '#[Year]',
            'string' => $this->addStringLengthAttribute($column, $attributes),
            default => null,
        };

        // Unsigned modifier for integer family and numeric types (non-PK)
        if ($column->unsigned && in_array($column->type, [
            'integer', 'bigInteger', 'smallInteger', 'tinyInteger',
            'unsignedBigInteger', 'unsignedInteger', 'unsignedSmallInteger', 'unsignedTinyInteger',
            'decimal', 'float', 'double',
        ], true)) {
            $attributes[] = '#[Unsigned]';
        }

        // Index attributes
        $indexAttrs = $this->resolveIndexAttributes($column, $table);
        $attributes = array_merge($attributes, $indexAttrs);

        // Expression default (e.g., CURRENT_TIMESTAMP)
        if ($column->expressionDefault !== null) {
            $attributes[] = "#[DefaultExpression('{$column->expressionDefault}')]";
        }

        // Default value (skip for object types like Carbon that can't have string defaults)
        $default = null;
        $hasDefault = false;
        if ($column->hasDefault && $column->default !== null && $phpType !== 'Carbon') {
            $hasDefault = true;
            $default = $column->default;
        }

        return new GeneratedProperty(
            name: $column->name,
            phpType: $phpType,
            nullable: $column->nullable,
            attributes: $attributes,
            default: $default,
            hasDefault: $hasDefault,
        );
    }

    /**
     * Resolve a BelongsTo relationship from a foreign key.
     */
    private function resolveBelongsTo(
        DatabaseForeignKeyState $fk,
        DatabaseTableState $table,
        string $modelNamespace,
        string $modelPrefix = '',
    ): GeneratedProperty {
        $relatedModel = $modelPrefix.$this->resolveModelName($fk->foreignTable);
        $propertyName = $this->fkColumnToPropertyName($fk->column);

        // Check nullable on the FK column
        $fkColumn = $table->getColumn($fk->column);
        $nullable = $fkColumn !== null && $fkColumn->nullable;

        $attributes = ["#[BelongsTo({$relatedModel}::class)]"];

        // OnDelete
        if ($fk->onDelete !== 'no action' && $fk->onDelete !== 'restrict') {
            $attributes[] = "#[OnDelete('{$fk->onDelete}')]";
        }

        // OnUpdate
        if ($fk->onUpdate !== 'no action' && $fk->onUpdate !== 'restrict') {
            $attributes[] = "#[OnUpdate('{$fk->onUpdate}')]";
        }

        // If the FK column name doesn't match convention, add explicit ForeignColumn
        $conventionFkColumn = Str::snake($propertyName).'_id';
        if ($conventionFkColumn !== $fk->column) {
            $attributes[] = "#[ForeignColumn('{$fk->column}')]";
        }

        // Add #[Index] only if the FK column has a standalone single-column index in the DB.
        // BelongsTo no longer hardcodes index:true — it must be explicit.
        if ($this->columnHasStandaloneIndex($fk->column, $table)) {
            $attributes[] = '#[Index]';
        }

        // Add #[ColumnType] when the FK column type is not the default unsignedBigInteger.
        if ($fkColumn !== null && ! in_array($fkColumn->type, ['bigInteger', 'unsignedBigInteger'], true)) {
            $columnTypeValue = $fkColumn->type;
            if ($fkColumn->unsigned && ! str_starts_with(strtolower($columnTypeValue), 'unsigned')) {
                $columnTypeValue = 'unsigned'.ucfirst($columnTypeValue);
            }
            $attributes[] = "#[ColumnType('{$columnTypeValue}')]";
        }

        return new GeneratedProperty(
            name: $propertyName,
            phpType: $relatedModel,
            nullable: $nullable,
            attributes: $attributes,
            isRelationship: true,
        );
    }

    /**
     * Convert FK column name to a property name.
     * owner_id → owner, created_by → createdBy
     */
    private function fkColumnToPropertyName(string $columnName): string
    {
        if (str_ends_with($columnName, '_id')) {
            $columnName = substr($columnName, 0, -3);
        }

        return Str::camel($columnName);
    }

    /**
     * Generate a HasMany property name from the FK column, used when there are collisions.
     *
     * originator_user_id + "Loan" → "originatorUserLoans"
     * processor_user_id + "Loan" → "processorUserLoans"
     */
    private function fkColumnToHasManyName(string $fkColumn, string $relatedModel): string
    {
        $prefix = $this->fkColumnToPropertyName($fkColumn);
        $pluralModel = Str::plural($relatedModel);

        return Str::camel($prefix.$pluralModel);
    }

    /**
     * Detect MorphTo column pairs ({name}_type + {name}_id without FK constraint).
     *
     * @return array<array{morphName: string, typeColumn: string, idColumn: string, nullable: bool, hasTypeIndex: bool, hasIdIndex: bool}>
     */
    private function detectMorphToColumns(DatabaseTableState $table): array
    {
        $fkColumnNames = array_map(fn (DatabaseForeignKeyState $fk) => $fk->column, $table->foreignKeys);

        // Build set of columns that have single-column non-primary indexes
        $indexedColumns = [];
        foreach ($table->indexes as $index) {
            if ($index->primary || count($index->columns) !== 1) {
                continue;
            }
            $indexedColumns[$index->columns[0]] = true;
        }

        $morphs = [];
        $columnsByName = [];
        foreach ($table->columns as $col) {
            $columnsByName[$col->name] = $col;
        }

        foreach ($table->columns as $column) {
            if (! str_ends_with($column->name, '_type')) {
                continue;
            }

            $morphName = substr($column->name, 0, -5); // strip _type
            $idColumnName = $morphName.'_id';

            // Check that the _id column exists
            if (! isset($columnsByName[$idColumnName])) {
                continue;
            }

            // Check that the _id column does NOT have a FK constraint
            if (in_array($idColumnName, $fkColumnNames, true)) {
                continue;
            }

            $idColumn = $columnsByName[$idColumnName];

            // Skip morphs with asymmetric nullable (_type NOT NULL but _id NULL or vice versa).
            // SchemaScanner uses a single nullable flag for both columns, so asymmetric nullable
            // can't round-trip through MorphTo — generate as regular columns instead.
            if ($column->nullable !== $idColumn->nullable) {
                continue;
            }

            $nullable = $column->nullable;

            $morphs[] = [
                'morphName' => $morphName,
                'typeColumn' => $column->name,
                'idColumn' => $idColumnName,
                'nullable' => $nullable,
                'hasTypeIndex' => isset($indexedColumns[$column->name]),
                'hasIdIndex' => isset($indexedColumns[$idColumnName]),
                'idColumnType' => $idColumn->type,
                'idColumnUnsigned' => $idColumn->unsigned,
            ];
        }

        return $morphs;
    }

    /**
     * Map canonical DB type to PHP type.
     */
    private function canonicalToPhpType(string $canonicalType): string
    {
        return match ($canonicalType) {
            'string', 'text', 'mediumText', 'longText', 'binary' => 'string',
            'integer', 'bigInteger', 'smallInteger', 'tinyInteger',
            'unsignedBigInteger', 'unsignedInteger', 'unsignedSmallInteger', 'unsignedTinyInteger',
            'year' => 'int',
            'boolean' => 'bool',
            'double', 'float', 'decimal' => 'float',
            'json' => 'array',
            'timestamp', 'date', 'time' => 'Carbon',
            'uuid', 'ulid' => 'string',
            default => 'string',
        };
    }

    /**
     * Build the #[Decimal(P, S)] attribute string.
     */
    private function decimalAttribute(DatabaseColumnState $column): string
    {
        $precision = $column->precision ?? 8;
        $scale = $column->scale ?? 2;

        return "#[Decimal({$precision}, {$scale})]";
    }

    /**
     * Add #[Length(N)] attribute if string length is not the default 255.
     */
    private function addStringLengthAttribute(DatabaseColumnState $column, array &$attributes): void
    {
        if ($column->length !== null && $column->length !== 255) {
            $attributes[] = "#[Length({$column->length})]";
        }
    }

    /**
     * Resolve single-column index attributes for a column.
     *
     * @return string[]
     */
    private function resolveIndexAttributes(DatabaseColumnState $column, DatabaseTableState $table): array
    {
        $attributes = [];

        foreach ($table->indexes as $index) {
            if ($index->primary) {
                continue;
            }

            if (count($index->columns) !== 1) {
                continue;
            }

            if ($index->columns[0] !== $column->name) {
                continue;
            }

            if ($index->unique) {
                $attributes[] = '#[Unique]';
            } else {
                $attributes[] = '#[Index]';
            }
        }

        return $attributes;
    }

    /**
     * Check if a column has a standalone single-column non-primary index.
     */
    private function columnHasStandaloneIndex(string $columnName, DatabaseTableState $table): bool
    {
        foreach ($table->indexes as $index) {
            if ($index->primary) {
                continue;
            }

            if (count($index->columns) !== 1) {
                continue;
            }

            if ($index->columns[0] === $columnName) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve composite (multi-column) indexes as class-level attributes.
     *
     * @param  string[]  $skipColumns
     * @return string[]
     */
    private function resolveCompositeIndexes(DatabaseTableState $table, array $skipColumns): array
    {
        $attributes = [];

        foreach ($table->indexes as $index) {
            if ($index->primary) {
                continue;
            }

            if (count($index->columns) < 2) {
                continue;
            }

            // Skip indexes where all columns are in the skip list
            $relevantColumns = array_filter(
                $index->columns,
                fn (string $col) => ! in_array($col, $skipColumns, true),
            );

            if (count($relevantColumns) === 0) {
                continue;
            }

            $colList = implode("', '", $index->columns);
            $attributes[] = "#[Index(['{$colList}'])]";
        }

        return $attributes;
    }

    /**
     * Map a generated attribute string to its import FQCN.
     */
    private function attributeToImport(string $attribute): ?string
    {
        return match (true) {
            str_contains($attribute, '#[Text]') => 'SchemaCraft\\Attributes\\Text',
            str_contains($attribute, '#[MediumText]') => 'SchemaCraft\\Attributes\\MediumText',
            str_contains($attribute, '#[LongText]') => 'SchemaCraft\\Attributes\\LongText',
            str_contains($attribute, '#[BigInt]') => 'SchemaCraft\\Attributes\\BigInt',
            str_contains($attribute, '#[SmallInt]') => 'SchemaCraft\\Attributes\\SmallInt',
            str_contains($attribute, '#[TinyInt]') => 'SchemaCraft\\Attributes\\TinyInt',
            str_contains($attribute, '#[FloatColumn]') => 'SchemaCraft\\Attributes\\FloatColumn',
            str_contains($attribute, '#[Decimal') => 'SchemaCraft\\Attributes\\Decimal',
            str_contains($attribute, '#[Date]') => 'SchemaCraft\\Attributes\\Date',
            str_contains($attribute, '#[Time]') => 'SchemaCraft\\Attributes\\Time',
            str_contains($attribute, '#[Year]') => 'SchemaCraft\\Attributes\\Year',
            str_contains($attribute, '#[Length') => 'SchemaCraft\\Attributes\\Length',
            str_contains($attribute, '#[Unsigned]') => 'SchemaCraft\\Attributes\\Unsigned',
            str_contains($attribute, '#[Unique]') => 'SchemaCraft\\Attributes\\Unique',
            str_contains($attribute, '#[Index') => 'SchemaCraft\\Attributes\\Index',
            str_contains($attribute, '#[OnDelete') => 'SchemaCraft\\Attributes\\OnDelete',
            str_contains($attribute, '#[OnUpdate') => 'SchemaCraft\\Attributes\\OnUpdate',
            str_contains($attribute, '#[BelongsTo') => 'SchemaCraft\\Attributes\\Relations\\BelongsTo',
            str_contains($attribute, '#[HasMany') => 'SchemaCraft\\Attributes\\Relations\\HasMany',
            str_contains($attribute, '#[BelongsToMany') => 'SchemaCraft\\Attributes\\Relations\\BelongsToMany',
            str_contains($attribute, '#[MorphTo') => 'SchemaCraft\\Attributes\\Relations\\MorphTo',
            str_contains($attribute, '#[PivotTable') => 'SchemaCraft\\Attributes\\PivotTable',
            str_contains($attribute, '#[ForeignColumn') => 'SchemaCraft\\Attributes\\ForeignColumn',
            str_contains($attribute, '#[ColumnType') => 'SchemaCraft\\Attributes\\ColumnType',
            str_contains($attribute, '#[DefaultExpression') => 'SchemaCraft\\Attributes\\DefaultExpression',
            str_contains($attribute, '#[Primary]') => 'SchemaCraft\\Attributes\\Primary',
            str_contains($attribute, '#[AutoIncrement]') => 'SchemaCraft\\Attributes\\AutoIncrement',
            default => null,
        };
    }

    /**
     * Build the full schema file content.
     *
     * @param  GeneratedProperty[]  $columnProperties
     * @param  GeneratedProperty[]  $belongsToProperties
     * @param  GeneratedProperty[]  $hasManyProperties
     * @param  GeneratedProperty[]  $belongsToManyProperties
     * @param  GeneratedProperty[]  $morphToProperties
     * @param  string[]  $compositeIndexAttrs
     */
    private function buildSchemaFileContent(
        string $schemaNamespace,
        string $schemaName,
        array $imports,
        bool $hasTimestamps,
        bool $hasSoftDeletes,
        ?GeneratedProperty $idProperty,
        array $columnProperties,
        array $belongsToProperties,
        array $hasManyProperties,
        array $belongsToManyProperties,
        array $morphToProperties,
        array $compositeIndexAttrs,
        ?string $customTableName = null,
        ?string $connection = null,
    ): string {
        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = "namespace {$schemaNamespace};";
        $lines[] = '';

        // Sort and deduplicate imports
        $sortedImports = $this->sortImports(array_unique($imports));
        foreach ($sortedImports as $import) {
            $lines[] = "use {$import};";
        }

        $lines[] = '';

        // Build @method docblock
        $allRelationships = array_merge($belongsToProperties, $hasManyProperties, $belongsToManyProperties, $morphToProperties);
        if (count($allRelationships) > 0) {
            $lines[] = '/**';
            foreach ($allRelationships as $rel) {
                $eloquentType = $this->relationshipToEloquentType($rel);
                $returnType = $this->relationshipToReturnType($rel);
                $lines[] = " * @method Eloquent\\{$eloquentType}|{$returnType} {$rel->name}()";
            }
            $lines[] = ' */';
        }

        // Class declaration with composite index attributes
        foreach ($compositeIndexAttrs as $attr) {
            $lines[] = $attr;
        }
        $lines[] = "class {$schemaName} extends Schema";
        $lines[] = '{';

        // Traits
        if ($hasTimestamps) {
            $lines[] = '    use TimestampsSchema;';
        }
        if ($hasSoftDeletes) {
            $lines[] = '    use SoftDeletesSchema;';
        }

        // Custom table name override (when convention doesn't match)
        if ($customTableName !== null) {
            $lines[] = '';
            $lines[] = '    public static function tableName(): ?string';
            $lines[] = '    {';
            $lines[] = "        return '{$customTableName}';";
            $lines[] = '    }';
        }

        // DB connection override (for multi-database setups)
        if ($connection !== null) {
            $lines[] = '';
            $lines[] = "    protected static ?string \$connection = '{$connection}';";
        }

        // Id property
        if ($idProperty !== null) {
            $lines[] = '';
            $this->renderProperty($lines, $idProperty);
        }

        // Regular column properties
        foreach ($columnProperties as $prop) {
            $lines[] = '';
            $this->renderProperty($lines, $prop);
        }

        // BelongsTo properties
        foreach ($belongsToProperties as $prop) {
            $lines[] = '';
            $this->renderProperty($lines, $prop);
        }

        // MorphTo properties
        foreach ($morphToProperties as $prop) {
            $lines[] = '';
            $this->renderProperty($lines, $prop);
        }

        // HasMany properties
        foreach ($hasManyProperties as $prop) {
            $lines[] = '';
            $this->renderProperty($lines, $prop);
        }

        // BelongsToMany properties
        foreach ($belongsToManyProperties as $prop) {
            $lines[] = '';
            $this->renderProperty($lines, $prop);
        }

        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Build the model file content.
     */
    private function buildModelFileContent(
        string $modelNamespace,
        string $schemaNamespace,
        string $modelName,
        string $schemaName,
        bool $hasSoftDeletes,
        ?string $connection = null,
    ): string {
        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = "namespace {$modelNamespace};";
        $lines[] = '';

        if ($hasSoftDeletes) {
            $lines[] = 'use Illuminate\\Database\\Eloquent\\SoftDeletes;';
        }
        $lines[] = "use {$schemaNamespace}\\{$schemaName};";

        $lines[] = '';
        $lines[] = '/**';
        $lines[] = " * @mixin {$schemaName}";
        $lines[] = ' */';
        $lines[] = "class {$modelName} extends BaseModel";
        $lines[] = '{';

        if ($hasSoftDeletes) {
            $lines[] = '    use SoftDeletes;';
            $lines[] = '';
        }

        $lines[] = "    protected static string \$schema = {$schemaName}::class;";

        if ($connection !== null) {
            $lines[] = '';
            $lines[] = "    protected \$connection = '{$connection}';";
        }

        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Render a property with its attributes and docblock.
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
            // Ensure floats always have a decimal point
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
     * Sort imports: App\Models\* first, then Illuminate\*, then SchemaCraft\*.
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

    /**
     * Get the Eloquent relationship type name for a property.
     */
    private function relationshipToEloquentType(GeneratedProperty $prop): string
    {
        foreach ($prop->attributes as $attr) {
            if (str_contains($attr, '#[BelongsTo(')) {
                return 'BelongsTo';
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
        }

        return 'BelongsTo';
    }

    /**
     * Get the return type for a relationship's @method docblock.
     */
    private function relationshipToReturnType(GeneratedProperty $prop): string
    {
        if ($prop->phpType === 'Collection') {
            // Extract model name from the HasMany/BelongsToMany attribute
            foreach ($prop->attributes as $attr) {
                if (preg_match('/\[(?:HasMany|BelongsToMany|MorphMany|MorphToMany)\((\w+)::class\)/', $attr, $matches)) {
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
     * Get the expected pivot table name for two tables (alphabetical order).
     */
    private function expectedPivotTableName(string $tableA, string $tableB): string
    {
        $singularA = Str::singular(Str::snake($tableA));
        $singularB = Str::singular(Str::snake($tableB));

        $names = [$singularA, $singularB];
        sort($names);

        return implode('_', $names);
    }
}
