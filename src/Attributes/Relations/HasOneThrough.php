<?php

namespace SchemaCraft\Attributes\Relations;

use Attribute;

/**
 * Declares a has-one-through relationship. No column created on this table.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class HasOneThrough
{
    public function __construct(
        public string $model,
        public string $through,
        public ?string $firstKey = null,
        public ?string $secondKey = null,
        public ?string $localKey = null,
        public ?string $secondLocalKey = null,
    ) {}
}
