<?php

namespace SchemaCraft\Attributes\Relations;

use Attribute;

/**
 * Declares a belongs-to-many relationship. Creates a pivot table.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class BelongsToMany
{
    public function __construct(
        public string $model,
    ) {}
}
