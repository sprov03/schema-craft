<?php

namespace SchemaCraft\Attributes\Relations;

use Attribute;

/**
 * Declares a polymorphic morph-to relationship.
 * Creates {morphName}_type and {morphName}_id columns.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class MorphTo
{
    public function __construct(
        public string $morphName,
    ) {}
}
