<?php

namespace SchemaCraft\Attributes\Relations;

use Attribute;

/**
 * Declares a belongs-to relationship. Creates a foreign key column on this table.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class BelongsTo
{
    public function __construct(
        public string $model,
    ) {}
}
