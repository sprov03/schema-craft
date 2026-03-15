<?php

namespace SchemaCraft\Attributes\Relations;

use Attribute;

/**
 * Declares a belongs-to-many relationship. Creates a pivot table.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class BelongsToMany
{
    /**
     * @param  string[]  $fields  Fields to include from the related model (for nested Action data).
     * @param  string[]  $pivotFields  Extra pivot columns to include.
     */
    public function __construct(
        public string $model,
        public array $fields = [],
        public array $pivotFields = [],
        public bool $sync = true,
    ) {}
}
