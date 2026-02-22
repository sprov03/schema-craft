<?php

namespace SchemaCraft\Migration;

/**
 * Immutable value object representing the complete actual state of a database table.
 */
class DatabaseTableState
{
    /**
     * @param  DatabaseColumnState[]  $columns
     * @param  DatabaseIndexState[]  $indexes
     * @param  DatabaseForeignKeyState[]  $foreignKeys
     */
    public function __construct(
        public string $tableName,
        public array $columns = [],
        public array $indexes = [],
        public array $foreignKeys = [],
    ) {}

    /**
     * Find a column by name.
     */
    public function getColumn(string $name): ?DatabaseColumnState
    {
        foreach ($this->columns as $column) {
            if ($column->name === $name) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Find an index by its column set (order-insensitive).
     */
    public function getIndex(array $columns): ?DatabaseIndexState
    {
        $sorted = $columns;
        sort($sorted);

        foreach ($this->indexes as $index) {
            $indexCols = $index->columns;
            sort($indexCols);

            if ($sorted === $indexCols) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Check if a non-unique index exists on the given column set.
     */
    public function hasIndex(array $columns): bool
    {
        $index = $this->getIndex($columns);

        return $index !== null && ! $index->unique && ! $index->primary;
    }

    /**
     * Check if a unique index exists on the given column set.
     */
    public function hasUniqueIndex(array $columns): bool
    {
        $index = $this->getIndex($columns);

        return $index !== null && $index->unique;
    }

    /**
     * Find a foreign key by local column name.
     */
    public function getForeignKey(string $column): ?DatabaseForeignKeyState
    {
        foreach ($this->foreignKeys as $fk) {
            if ($fk->column === $column) {
                return $fk;
            }
        }

        return null;
    }

    /**
     * Check if the table has timestamp columns (created_at and updated_at).
     */
    public function hasTimestamps(): bool
    {
        return $this->getColumn('created_at') !== null
            && $this->getColumn('updated_at') !== null;
    }

    /**
     * Check if the table has a soft deletes column.
     */
    public function hasSoftDeletes(): bool
    {
        return $this->getColumn('deleted_at') !== null;
    }
}
