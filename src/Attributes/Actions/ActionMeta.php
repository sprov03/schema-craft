<?php

namespace SchemaCraft\Attributes\Actions;

use Attribute;

/**
 * Declares metadata for an Action class.
 *
 * Apply to an Action subclass to define the HTTP method,
 * human-readable label, and description for the operation.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class ActionMeta
{
    public function __construct(
        public string $method = 'put',
        public ?string $label = null,
        public ?string $description = null,
    ) {}
}
