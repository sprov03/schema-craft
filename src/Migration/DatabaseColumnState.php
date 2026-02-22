<?php

namespace SchemaCraft\Migration;

/**
 * Immutable value object representing an actual database column,
 * normalized into the same vocabulary as ColumnDefinition.
 */
class DatabaseColumnState
{
    public function __construct(
        public string $name,
        public string $type,
        public bool $nullable = false,
        public mixed $default = null,
        public bool $hasDefault = false,
        public bool $unsigned = false,
        public ?int $length = null,
        public ?int $precision = null,
        public ?int $scale = null,
        public bool $primary = false,
        public bool $autoIncrement = false,
        public ?string $expressionDefault = null,
    ) {}
}
