<?php

namespace SchemaCraft\Attributes\Resources;

use Attribute;

/**
 * Marks a resource property as a has-one relationship, serialized via whenLoaded().
 *
 * Example:
 *   #[HasOne(CampaignDetailResource::class)]
 *   public ?CampaignDetailResource $detail;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class HasOne
{
    public function __construct(
        public readonly string $resource,
    ) {}
}
