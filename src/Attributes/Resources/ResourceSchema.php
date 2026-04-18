<?php

namespace SchemaCraft\Attributes\Resources;

use Attribute;

/**
 * Declares which SchemaCraft schema this resource belongs to.
 *
 * Example:
 *   #[ResourceSchema(CampaignSchema::class)]
 *   class CampaignFullResource extends SchemaCraftResource { ... }
 */
#[Attribute(Attribute::TARGET_CLASS)]
class ResourceSchema
{
    public function __construct(
        public readonly string $schema,
    ) {}
}
