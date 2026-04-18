<?php

namespace SchemaCraft\Attributes\Resources;

use Attribute;

/**
 * Marks a resource property as a has-many relationship, serialized via whenLoaded().
 *
 * Example:
 *   #[HasMany(CampaignCostResource::class)]
 *   public Collection $costs;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class HasMany
{
    public function __construct(
        public readonly string $resource,
    ) {}
}
