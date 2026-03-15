<?php

namespace SchemaCraft\Attributes\Relations;

use Attribute;

/**
 * Declares a polymorphic morph-many relationship. No column created on this table.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class MorphMany
{
    /**
     * @param  string[]  $fields  Fields to include from the related model (for nested Action data).
     */
    public function __construct(
        public string $model,
        public string $morphName,
        public array $fields = [],
        public bool $sync = false,
    ) {}
}
