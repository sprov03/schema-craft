<?php

namespace SchemaCraft\Migration;

/**
 * Converts a DatabaseTableState (from DatabaseReader) into a CanonicalTable.
 *
 * The key transformation is flattening single-column indexes from
 * DatabaseIndexState[] onto CanonicalColumn::$unique / $index booleans.
 */
class DatabaseTableNormalizer
{
    /**
     * Normalize a DatabaseTableState into a CanonicalTable.
     */
    public function normalize(DatabaseTableState $table): CanonicalTable
    {
        // Build single-column index lookup
        $uniqueColumns = [];
        $indexedColumns = [];

        foreach ($table->indexes as $index) {
            if ($index->primary) {
                continue;
            }

            if (count($index->columns) !== 1) {
                continue;
            }

            $colName = $index->columns[0];

            if ($index->unique) {
                $uniqueColumns[$colName] = true;
            } else {
                $indexedColumns[$colName] = true;
            }
        }

        // Normalize columns with flattened index info
        $columns = [];

        foreach ($table->columns as $col) {
            [$type, $unsigned] = CanonicalColumn::decomposeType($col->type, $col->unsigned);

            $columns[] = new CanonicalColumn(
                name: $col->name,
                type: $type,
                nullable: $col->nullable,
                default: $col->default,
                hasDefault: $col->hasDefault,
                unsigned: $unsigned,
                length: $col->length,
                precision: $col->precision,
                scale: $col->scale,
                unique: isset($uniqueColumns[$col->name]),
                index: isset($indexedColumns[$col->name]),
                primary: $col->primary,
                autoIncrement: $col->autoIncrement,
                expressionDefault: $col->expressionDefault,
            );
        }

        // Multi-column indexes only (single-column flattened above)
        $compositeIndexes = [];

        foreach ($table->indexes as $index) {
            if ($index->primary || count($index->columns) < 2) {
                continue;
            }

            $compositeIndexes[] = new CanonicalIndex(
                columns: $index->columns,
                unique: $index->unique,
            );
        }

        // Foreign keys
        $foreignKeys = [];

        foreach ($table->foreignKeys as $fk) {
            $foreignKeys[] = new CanonicalForeignKey(
                column: $fk->column,
                foreignTable: $fk->foreignTable,
                foreignColumn: $fk->foreignColumn,
                onDelete: $fk->onDelete,
                onUpdate: $fk->onUpdate,
            );
        }

        return new CanonicalTable(
            tableName: $table->tableName,
            columns: $columns,
            compositeIndexes: $compositeIndexes,
            foreignKeys: $foreignKeys,
            hasTimestamps: $table->hasTimestamps(),
            hasSoftDeletes: $table->hasSoftDeletes(),
        );
    }
}
