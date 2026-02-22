<?php

namespace SchemaCraft\Attributes;

use Attribute;

/**
 * Marks this relationship to be eager loaded on every query.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class With {}
