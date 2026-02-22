<?php

namespace SchemaCraft\Attributes\Relations;

use Attribute;

/**
 * Declares a polymorphic many-to-many relationship. Creates a polymorphic pivot table.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class MorphToMany
{
    public function __construct(
        public string $model,
        public string $morphName,
    ) {}
}
