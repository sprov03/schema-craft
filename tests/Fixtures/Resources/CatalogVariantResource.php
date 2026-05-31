<?php

namespace SchemaCraft\Tests\Fixtures\Resources;

use Illuminate\Database\Eloquent\Collection;
use SchemaCraft\Attributes\Resources\HasMany;
use SchemaCraft\Attributes\Resources\HasOne;
use SchemaCraft\Attributes\Resources\ResourceSchema;
use SchemaCraft\SchemaCraftResource;
use SchemaCraft\Tests\Fixtures\Schemas\CatalogVariantSchema;

/**
 * HasMany recursion target for CatalogResource. Declares the relationships the SDK
 * needs to expose for variant responses — the Resource-walked dep walker only pulls
 * what's documented here, so suppliers / reviews / shipment must be declared explicitly
 * rather than inferred from CatalogVariantSchema's relationship graph.
 *
 * Cardinality collapses at the Resource layer: HasMany covers hasMany/morphMany/
 * belongsToMany/hasManyThrough alike — those are schema-side DB mechanics, irrelevant
 * to what the response documents.
 */
#[ResourceSchema(CatalogVariantSchema::class)]
class CatalogVariantResource extends SchemaCraftResource
{
    public int $id;

    public string $sku;

    public float $price;

    #[HasOne(CatalogShipmentResource::class)]
    public ?CatalogShipmentResource $shipment;

    #[HasMany(CatalogReviewResource::class)]
    public Collection $reviews;

    #[HasMany(CatalogSupplierResource::class)]
    public Collection $suppliers;

    #[HasMany(CatalogSupplierResource::class)]
    public Collection $brandSuppliers;
}
