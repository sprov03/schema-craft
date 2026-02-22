<?php

namespace SchemaCraft\Migration;

/**
 * Represents a single foreign key change: add or drop.
 */
class ForeignKeyDiff
{
    public function __construct(
        public string $action,
        public string $column,
        public ?string $foreignTable = null,
        public ?string $foreignColumn = null,
        public ?string $onDelete = null,
        public ?string $onUpdate = null,
        public bool $noConstraint = false,
    ) {}
}
