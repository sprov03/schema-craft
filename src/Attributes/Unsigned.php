<?php

namespace SchemaCraft\Attributes;

use Attribute;

/**
 * Marks an integer column as unsigned.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Unsigned {}
