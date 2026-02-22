<?php

namespace SchemaCraft\Attributes;

use Attribute;

/**
 * Overrides the column type to BIGINT.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class BigInt {}
