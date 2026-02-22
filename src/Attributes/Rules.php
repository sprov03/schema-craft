<?php

namespace SchemaCraft\Attributes;

use Attribute;

/**
 * Add validation rules to a schema property.
 *
 * Rules are appended to the auto-inferred rules from the column type.
 *
 * Usage:
 *   #[Rules('min:3', 'regex:/^[a-z]/')]
 *   public string $slug;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Rules
{
    /** @var string[] */
    public array $rules;

    public function __construct(string ...$rules)
    {
        $this->rules = $rules;
    }
}
