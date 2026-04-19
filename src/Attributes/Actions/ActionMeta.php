<?php

namespace SchemaCraft\Attributes\Actions;

use Attribute;

/**
 * Declares metadata for an Action class.
 *
 * Apply to an Action subclass to define the route slug, HTTP method,
 * human-readable label, and optional SDK method name override.
 *
 * The route slug is required and determines the registered URL segment
 * (schema-scoped automatically). The SDK method name defaults to the
 * camelCase version of the route slug if not explicitly set.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class ActionMeta
{
    public function __construct(
        /** Local route slug, e.g. 'assign-to-user'. Schema prefix is added automatically. */
        public string $route,
        public string $method = 'put',
        public ?string $label = null,
        public ?string $description = null,
        /** SDK client method name. Defaults to camelCase of $route if not set. */
        public ?string $sdkMethod = null,
    ) {}
}
