<?php

namespace SchemaCraft\Attributes;

use Attribute;

/**
 * Overrides the column type to DECIMAL with the given precision and scale.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Decimal
{
    public function __construct(
        public int $precision = 8,
        public int $scale = 2,
    ) {}
}
