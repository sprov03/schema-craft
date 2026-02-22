<?php

namespace SchemaCraft\Attributes;

use Attribute;

/**
 * Sets the ON UPDATE action for a foreign key constraint.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class OnUpdate
{
    public function __construct(
        public string $action = 'restrict',
    ) {}
}
