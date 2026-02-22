<?php

namespace SchemaCraft\Attributes;

use Attribute;

/**
 * Override the auto-detected Eloquent cast for this property.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Cast
{
    public function __construct(
        public string $castClass,
    ) {}
}
