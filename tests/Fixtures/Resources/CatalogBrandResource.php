<?php

namespace SchemaCraft\Tests\Fixtures\Resources;

use SchemaCraft\Attributes\Resources\ResourceSchema;
use SchemaCraft\SchemaCraftResource;
use SchemaCraft\Tests\Fixtures\Schemas\CatalogBrandSchema;

/**
 * BelongsTo recursion target for CatalogResource. A belongsTo on the resource side
 * is serialized as a single nested resource (whenLoaded), so its DTO field is a
 * single CatalogBrandData|null in the generated Catalog DTO.
 */
#[ResourceSchema(CatalogBrandSchema::class)]
class CatalogBrandResource extends SchemaCraftResource
{
    public int $id;

    public string $name;
}
