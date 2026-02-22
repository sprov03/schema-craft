<?php

namespace SchemaCraft\Attributes;

use Attribute;

/**
 * Skip foreign key constraint, but still index the column.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class NoConstraint {}
