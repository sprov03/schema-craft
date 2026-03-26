<?php

namespace SchemaCraft\Attributes\Relations;

use Attribute;

/**
 * Declares a polymorphic morph-one relationship. No column created on this table.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class MorphOne
{
    public function __construct(
        public string $model,
        public string $morphName,
        public ?string $type = null,
        public ?string $id = null,
        public ?string $localKey = null,
    ) {}
}
