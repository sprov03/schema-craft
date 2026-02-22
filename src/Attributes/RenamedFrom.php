<?php

namespace SchemaCraft\Attributes;

use Attribute;

/**
 * Declares that this column was previously named something else.
 * Produces a renameColumn() migration instead of separate add + drop.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class RenamedFrom
{
    public function __construct(
        public string $from,
    ) {}
}
