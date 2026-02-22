<?php

namespace SchemaCraft\Migration;

/**
 * Represents a single index change: add or drop.
 */
class IndexDiff
{
    /**
     * @param  string[]  $columns
     */
    public function __construct(
        public string $action,
        public array $columns,
        public bool $unique = false,
    ) {}
}
