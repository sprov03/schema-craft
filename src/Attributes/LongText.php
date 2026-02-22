<?php

namespace SchemaCraft\Attributes;

use Attribute;

/**
 * Overrides the column type to LONGTEXT.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class LongText {}
