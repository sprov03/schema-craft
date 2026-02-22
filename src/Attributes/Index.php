<?php

namespace SchemaCraft\Attributes;

use Attribute;

/**
 * Adds an index to the column or defines a composite index at the class level.
 *
 * When applied to a property, creates a single-column index.
 * When applied to a class, creates a composite index using the specified columns.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Index
{
    /**
     * @param  string[]|null  $columns  Column names for composite index (class-level only).
     */
    public function __construct(
        public ?array $columns = null,
    ) {}
}
