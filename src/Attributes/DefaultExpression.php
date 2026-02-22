<?php

namespace SchemaCraft\Attributes;

use Attribute;

/**
 * Sets an SQL expression as the default value for a column.
 *
 * Unlike regular default values which are literal PHP values, expression defaults
 * are evaluated by the database engine at insert time (e.g., CURRENT_TIMESTAMP).
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class DefaultExpression
{
    public function __construct(
        public string $expression,
    ) {}
}
