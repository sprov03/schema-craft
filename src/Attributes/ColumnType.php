<?php

namespace SchemaCraft\Attributes;

use Attribute;

/**
 * Overrides the column type for a BelongsTo FK column or MorphTo _id column.
 *
 * When applied to a BelongsTo property, this type is used for the foreign key
 * column instead of the default 'unsignedBigInteger'.
 * When applied to a MorphTo property, this type is used for the _id column.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class ColumnType
{
    public function __construct(
        public string $type,
    ) {}
}
