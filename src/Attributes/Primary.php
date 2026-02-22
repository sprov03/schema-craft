<?php

namespace SchemaCraft\Attributes;

use Attribute;

/**
 * Marks a column as the primary key.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Primary {}
