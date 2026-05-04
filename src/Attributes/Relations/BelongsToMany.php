<?php

namespace SchemaCraft\Attributes\Relations;

use Attribute;

/**
 * Declares a belongs-to-many relationship. Creates a pivot table.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class BelongsToMany
{
    public function __construct(
        public string $model,
        public ?string $table = null,
        public ?string $foreignPivotKey = null,
        public ?string $relatedPivotKey = null,
        public ?string $parentKey = null,
        public ?string $relatedKey = null,
        public ?string $using = null,
    ) {}
}
