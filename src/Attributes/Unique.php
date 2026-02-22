<?php

namespace SchemaCraft\Attributes;

use Attribute;

/**
 * Adds a unique index to the column.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Unique {}
