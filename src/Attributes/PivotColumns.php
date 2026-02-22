<?php

namespace SchemaCraft\Attributes;

use Attribute;

/**
 * Defines additional columns on a pivot table for a many-to-many relationship.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class PivotColumns
{
    /**
     * @param  array<string, string>  $columns  Column name => column type pairs.
     */
    public function __construct(
        public array $columns,
    ) {}
}
