<?php

namespace SchemaCraft\Attributes\Relations;

use Attribute;

/**
 * Declares a polymorphic many-to-many relationship. Creates a polymorphic pivot table.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class MorphToMany
{
    /**
     * @param  string[]  $fields  Fields to include from the related model (for nested Action data).
     * @param  string[]  $pivotFields  Extra pivot columns to include.
     */
    public function __construct(
        public string $model,
        public string $morphName,
        public array $fields = [],
        public array $pivotFields = [],
        public bool $sync = true,
    ) {}
}
