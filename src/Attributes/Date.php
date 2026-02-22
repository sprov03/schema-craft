<?php

namespace SchemaCraft\Attributes;

use Attribute;

/**
 * Overrides Carbon type to use date column instead of timestamp.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Date {}
