<?php

namespace SchemaCraft\Attributes\Relations;

use Attribute;

/**
 * Declares a belongs-to relationship. Creates a foreign key column on this table.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class BelongsTo
{
    /**
     * @param  string[]  $fields  Fields to include from the related model (for nested Action data).
     */
    public function __construct(
        public string $model,
        public array $fields = [],
        public bool $createRelated = false,
    ) {}
}
