<?php

namespace SchemaCraft\Attributes\Resources;

use Attribute;

/**
 * Marks a resource property as a belongs-to relationship, embedded inline via whenLoaded().
 *
 * Example:
 *   #[BelongsTo(UserResource::class)]
 *   public ?UserResource $user;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class BelongsTo
{
    public function __construct(
        public readonly string $resource,
    ) {}
}
