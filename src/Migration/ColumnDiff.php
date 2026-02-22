<?php

namespace SchemaCraft\Migration;

/**
 * Represents a single column change: add, modify, drop, or rename.
 *
 * When action is 'rename':
 *  - oldColumnName holds the previous column name
 *  - columnName holds the new column name
 *  - desired holds the new column's canonical state
 *  - actual holds the old column's canonical state
 */
class ColumnDiff
{
    public function __construct(
        public string $action,
        public string $columnName,
        public ?CanonicalColumn $desired = null,
        public ?CanonicalColumn $actual = null,
        public ?string $oldColumnName = null,
    ) {}
}
