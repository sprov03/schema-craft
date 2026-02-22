<?php

namespace SchemaCraft\Migration;

/**
 * Immutable value object representing an actual database foreign key constraint.
 */
class DatabaseForeignKeyState
{
    public function __construct(
        public string $column,
        public string $foreignTable,
        public string $foreignColumn,
        public string $onDelete = 'no action',
        public string $onUpdate = 'no action',
    ) {}
}
