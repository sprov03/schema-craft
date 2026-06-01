<?php

namespace SchemaCraft\Tests\Fixtures\Resources;

use SchemaCraft\Attributes\Resources\ResourceSchema;
use SchemaCraft\SchemaCraftResource;
use SchemaCraft\Tests\Fixtures\Schemas\CatalogReviewSchema;

/**
 * Documented response resource for CatalogReview. Generates through the resource-driven
 * path so its morphTo `reviewable` is typed against ReviewableContractResource (the
 * common-fields contract) instead of `mixed`. Surfaced from CatalogResource so the
 * dependency walker reaches it through Resources.
 */
#[ResourceSchema(CatalogReviewSchema::class)]
class CatalogReviewResource extends SchemaCraftResource
{
    public int $id;

    public string $body;

    public int $rating;

    public string $reviewable_type;

    public int $reviewable_id;

    // Trust-based: target morph Resources are expected to expose ReviewableContractResource's
    // shape. The trust gap closes when discriminated-union types land (see the
    // discriminated-union-sdk-types task). Singular Resource type — no attribute needed; the
    // property type carries target FQCN and nullability.
    public ?ReviewableContractResource $reviewable;
}
