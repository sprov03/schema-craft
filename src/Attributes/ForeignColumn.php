<?php

namespace SchemaCraft\Attributes;

use Attribute;

/**
 * Specifies a custom foreign key column name for a relationship.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class ForeignColumn
{
    public function __construct(
        public string $column,
    ) {}
}
