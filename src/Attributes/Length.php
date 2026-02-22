<?php

namespace SchemaCraft\Attributes;

use Attribute;

/**
 * Sets a custom length for a string column.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Length
{
    public function __construct(
        public int $length,
    ) {}
}
