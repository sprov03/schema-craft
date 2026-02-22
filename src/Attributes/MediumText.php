<?php

namespace SchemaCraft\Attributes;

use Attribute;

/**
 * Overrides the column type to MEDIUMTEXT.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class MediumText {}
