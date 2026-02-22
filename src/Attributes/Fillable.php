<?php

namespace SchemaCraft\Attributes;

use Attribute;

/**
 * Marks this property as mass assignable on the Eloquent model.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Fillable {}
