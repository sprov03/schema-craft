<?php

namespace SchemaCraft\Attributes;

use Attribute;

/**
 * Specifies a custom foreign key column name for a relationship.
 *
 * @deprecated Use the `foreignKey` parameter on the relationship attribute instead.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class ForeignColumn
{
    public function __construct(
        public string $column,
    ) {}
}
