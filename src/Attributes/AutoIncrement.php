<?php

namespace SchemaCraft\Attributes;

use Attribute;

/**
 * Marks an integer primary key as auto-incrementing.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class AutoIncrement {}
