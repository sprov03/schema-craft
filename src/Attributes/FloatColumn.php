<?php

namespace SchemaCraft\Attributes;

use Attribute;

/**
 * Overrides float type to use SQL FLOAT instead of DOUBLE.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class FloatColumn {}
