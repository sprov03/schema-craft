<?php

namespace SchemaCraft\Attributes;

use Attribute;

/**
 * Specifies a custom pivot table name for a many-to-many relationship.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class PivotTable
{
    public function __construct(
        public string $table,
    ) {}
}
