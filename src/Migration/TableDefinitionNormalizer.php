<?php

namespace SchemaCraft\Migration;

use Illuminate\Support\Str;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Scanner\TableDefinition;

/**
 * Converts a TableDefinition (from SchemaScanner) into a CanonicalTable.
 *
 * Strips schema-only metadata (castType, attributes, renamedFrom) and
 * resolves foreign keys from BelongsTo relationships.
 */
class TableDefinitionNormalizer
{
    private const TIMESTAMP_COLUMNS = ['created_at', 'updated_at'];

    private const SOFT_DELETE_COLUMNS = ['deleted_at'];

    /**
     * Normalize a TableDefinition into a CanonicalTable.
     */
    public function normalize(TableDefinition $table): CanonicalTable
    {
        $skipColumns = $this->managedColumnNames($table);
        $columns = [];

        foreach ($table->columns as $col) {
            if (in_array($col->name, $skipColumns, true)) {
                continue;
            }

            $columns[] = $this->normalizeColumn($col);
        }

        // Composite indexes from class-level #[Index] attributes
        $compositeIndexes = [];

        foreach ($table->compositeIndexes as $indexColumns) {
            $compositeIndexes[] = new CanonicalIndex(
                columns: $indexColumns,
                unique: false,
            );
        }

        // Foreign keys from BelongsTo relationships
        $foreignKeys = [];

        foreach ($table->relationships as $rel) {
            if ($rel->type !== 'belongsTo' || $rel->noConstraint) {
                continue;
            }

            $fkColumn = $rel->foreignColumn ?? Str::snake($rel->name).'_id';
            $relatedModel = $rel->relatedModel;
            $foreignTable = (new $relatedModel)->getTable();

            $foreignKeys[] = new CanonicalForeignKey(
                column: $fkColumn,
                foreignTable: $foreignTable,
                foreignColumn: 'id',
                onDelete: strtolower($rel->onDelete ?? 'no action'),
                onUpdate: strtolower($rel->onUpdate ?? 'no action'),
            );
        }

        return new CanonicalTable(
            tableName: $table->tableName,
            columns: $columns,
            compositeIndexes: $compositeIndexes,
            foreignKeys: $foreignKeys,
            hasTimestamps: $table->hasTimestamps,
            hasSoftDeletes: $table->hasSoftDeletes,
        );
    }

    /**
     * Extract rename map from TableDefinition columns.
     *
     * @return array<string, string> ['new_name' => 'old_name']
     */
    public static function extractRenameMap(TableDefinition $table): array
    {
        $map = [];

        foreach ($table->columns as $col) {
            if ($col->renamedFrom !== null) {
                $map[$col->name] = $col->renamedFrom;
            }
        }

        return $map;
    }

    /**
     * Normalize a ColumnDefinition into a CanonicalColumn.
     */
    private function normalizeColumn(ColumnDefinition $col): CanonicalColumn
    {
        [$type, $unsigned] = CanonicalColumn::decomposeType($col->columnType, $col->unsigned);

        return new CanonicalColumn(
            name: $col->name,
            type: $type,
            nullable: $col->nullable,
            default: $col->default,
            hasDefault: $col->hasDefault,
            unsigned: $unsigned,
            length: $col->length,
            precision: $col->precision,
            scale: $col->scale,
            unique: $col->unique,
            index: $col->index,
            primary: $col->primary,
            autoIncrement: $col->autoIncrement,
            expressionDefault: $col->expressionDefault,
        );
    }

    /**
     * Get column names managed by timestamps()/softDeletes() shorthand.
     *
     * @return string[]
     */
    private function managedColumnNames(TableDefinition $table): array
    {
        $skip = [];

        if ($table->hasTimestamps) {
            $skip = array_merge($skip, self::TIMESTAMP_COLUMNS);
        }

        if ($table->hasSoftDeletes) {
            $skip = array_merge($skip, self::SOFT_DELETE_COLUMNS);
        }

        return $skip;
    }
}
