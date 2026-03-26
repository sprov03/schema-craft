<?php

namespace SchemaCraft\Attributes;

use Attribute;

/**
 * Marks a property on an DataSchema as a pivot field.
 *
 * Used on BelongsToMany/MorphToMany DataSchema classes to distinguish
 * pivot table fields from related model fields.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Pivot {}
