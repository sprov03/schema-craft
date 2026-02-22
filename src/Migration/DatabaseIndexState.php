<?php

namespace SchemaCraft\Migration;

/**
 * Immutable value object representing an actual database index.
 */
class DatabaseIndexState
{
    /**
     * @param  string[]  $columns
     */
    public function __construct(
        public string $name,
        public array $columns,
        public bool $unique = false,
        public bool $primary = false,
    ) {}
}
