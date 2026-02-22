<?php

namespace SchemaCraft\Attributes\Relations;

use Attribute;

/**
 * Declares a has-one relationship. No column created on this table.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class HasOne
{
    public function __construct(
        public string $model,
    ) {}
}
