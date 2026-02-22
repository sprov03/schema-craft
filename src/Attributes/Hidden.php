<?php

namespace SchemaCraft\Attributes;

use Attribute;

/**
 * Marks this property as hidden for serialization on the Eloquent model.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Hidden {}
