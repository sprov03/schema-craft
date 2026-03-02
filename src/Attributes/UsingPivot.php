<?php

namespace SchemaCraft\Attributes;

use Attribute;

/**
 * Specifies a custom pivot model class for a BelongsToMany relationship.
 *
 * When present, the relationship will chain ->using(PivotModel::class)
 * to use a dedicated Eloquent Pivot model instead of the default.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class UsingPivot
{
    /**
     * @param  class-string  $model  The pivot model class name.
     */
    public function __construct(
        public string $model,
    ) {}
}
