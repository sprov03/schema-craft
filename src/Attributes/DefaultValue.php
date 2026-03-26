<?php

namespace SchemaCraft\Attributes;

use Attribute;

/**
 * Sets a literal default value for a column when the PHP property type
 * prevents using a native default (e.g., class-typed properties like bitmask wrappers).
 *
 * The value is used only for migration generation (->default(...)).
 *
 * Example:
 *   #[DefaultValue(BaseFeatures::NONE)]  // resolves to 0
 *   public AccountFeatures $features;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class DefaultValue
{
    public function __construct(
        public int|float|string|bool|null $value,
    ) {}
}
