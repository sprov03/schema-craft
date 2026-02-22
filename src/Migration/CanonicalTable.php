<?php

namespace SchemaCraft\Migration;

/**
 * Canonical representation of a database table.
 *
 * Both sides (schema definitions and actual database state) normalize
 * into this format for comparison by SchemaDiffer.
 */
class CanonicalTable
{
    /**
     * @param  CanonicalColumn[]  $columns
     * @param  CanonicalIndex[]  $compositeIndexes  Multi-column indexes only
     * @param  CanonicalForeignKey[]  $foreignKeys
     */
    public function __construct(
        public string $tableName,
        public array $columns = [],
        public array $compositeIndexes = [],
        public array $foreignKeys = [],
        public bool $hasTimestamps = false,
        public bool $hasSoftDeletes = false,
    ) {}

    /**
     * Find a column by name.
     */
    public function getColumn(string $name): ?CanonicalColumn
    {
        foreach ($this->columns as $column) {
            if ($column->name === $name) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Check if a composite (multi-column) index exists on the given column set.
     */
    public function hasCompositeIndex(array $columns): bool
    {
        $sorted = $columns;
        sort($sorted);

        foreach ($this->compositeIndexes as $index) {
            $indexCols = $index->columns;
            sort($indexCols);

            if ($sorted === $indexCols) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find a foreign key by local column name.
     */
    public function getForeignKey(string $column): ?CanonicalForeignKey
    {
        foreach ($this->foreignKeys as $fk) {
            if ($fk->column === $column) {
                return $fk;
            }
        }

        return null;
    }
}
