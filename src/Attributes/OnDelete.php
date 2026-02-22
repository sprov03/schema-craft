<?php

namespace SchemaCraft\Attributes;

use Attribute;

/**
 * Sets the ON DELETE action for a foreign key constraint.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class OnDelete
{
    public function __construct(
        public string $action = 'restrict',
    ) {}
}
