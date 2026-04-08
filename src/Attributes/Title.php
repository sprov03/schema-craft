<?php

namespace SchemaCraft\Attributes;

use Attribute;

/**
 * Marks the display title column(s) for a schema.
 *
 * Property-level: uses that single column as the title.
 *   #[Title]
 *   public string $companyName;
 *
 * Class-level with columns: composite title from multiple columns.
 *   #[Title(['first_name', 'last_name'])]
 *   class UserSchema extends Schema { ... }
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_CLASS)]
class Title
{
    /**
     * @param  string[]|null  $columns  Column names for composite title (class-level only).
     */
    public function __construct(
        public ?array $columns = null,
    ) {}
}
