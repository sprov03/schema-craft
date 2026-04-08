<?php

namespace SchemaCraft\Attributes;

use Attribute;

/**
 * Designates the display title column(s) for a schema. Class-level only.
 *
 * Single column:
 *   #[Title('company_name')]
 *   class ApiAccountSchema extends Schema { ... }
 *
 * Composite (multiple columns concatenated with a space):
 *   #[Title(['first_name', 'last_name'])]
 *   class UserSchema extends Schema { ... }
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Title
{
    /** @var string[] */
    public array $columns;

    /**
     * @param  string|string[]  $columns  Column name(s) for the display title.
     */
    public function __construct(
        string|array $columns,
    ) {
        $this->columns = is_array($columns) ? $columns : [$columns];
    }
}
