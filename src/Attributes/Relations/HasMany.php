<?php

namespace SchemaCraft\Attributes\Relations;

use Attribute;

/**
 * Declares a has-many relationship. No column created on this table.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class HasMany
{
    public function __construct(
        public string $model,
    ) {}
}
