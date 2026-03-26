<?php

namespace SchemaCraft\Attributes\Relations;

use Attribute;

/**
 * Declares an inverse polymorphic many-to-many relationship. No column created on this table.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class MorphedByMany
{
    public function __construct(
        public string $model,
        public string $morphName,
        public ?string $table = null,
        public ?string $foreignPivotKey = null,
        public ?string $relatedPivotKey = null,
        public ?string $parentKey = null,
        public ?string $relatedKey = null,
    ) {}
}
