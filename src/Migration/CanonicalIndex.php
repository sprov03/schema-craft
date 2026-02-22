<?php

namespace SchemaCraft\Migration;

/**
 * Canonical representation of a multi-column (composite) index.
 *
 * Single-column indexes are flattened onto CanonicalColumn::$unique / $index.
 */
class CanonicalIndex
{
    /**
     * @param  string[]  $columns
     */
    public function __construct(
        public array $columns,
        public bool $unique = false,
    ) {}
}
