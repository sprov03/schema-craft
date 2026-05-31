<?php

namespace SchemaCraft\Tests\Fixtures\Resources;

use SchemaCraft\Attributes\Resources\ResourceSchema;
use SchemaCraft\SchemaCraftResource;
use SchemaCraft\Tests\Fixtures\Schemas\CatalogShipmentSchema;

/**
 * HasOne recursion target for CatalogResource. Declares managed columns (timestamps +
 * soft-deletes) explicitly — Resource-walked SDK gen doesn't auto-inject anything from
 * schema traits; what's documented is what ships.
 */
#[ResourceSchema(CatalogShipmentSchema::class)]
class CatalogShipmentResource extends SchemaCraftResource
{
    public int $id;

    public string $carrier;

    public ?string $shipped_at;

    public ?string $created_at;

    public ?string $updated_at;

    public ?string $deleted_at;
}
