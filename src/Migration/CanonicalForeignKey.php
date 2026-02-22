<?php

namespace SchemaCraft\Migration;

/**
 * Canonical representation of a foreign key constraint.
 *
 * onDelete and onUpdate are always lowercased.
 */
class CanonicalForeignKey
{
    public function __construct(
        public string $column,
        public string $foreignTable,
        public string $foreignColumn,
        public string $onDelete = 'no action',
        public string $onUpdate = 'no action',
    ) {
        // Normalize 'restrict' → 'no action' since MySQL treats them identically
        $this->onDelete = $this->onDelete === 'restrict' ? 'no action' : $this->onDelete;
        $this->onUpdate = $this->onUpdate === 'restrict' ? 'no action' : $this->onUpdate;
    }
}
