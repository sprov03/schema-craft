<?php

namespace SchemaCraft\Attributes;

use Attribute;

/**
 * Specifies a custom pivot table name for a many-to-many relationship.
 *
 * @deprecated Use the `table` parameter on BelongsToMany or MorphToMany instead.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class PivotTable
{
    public function __construct(
        public string $table,
    ) {}
}
