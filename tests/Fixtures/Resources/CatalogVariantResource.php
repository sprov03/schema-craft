<?php

namespace SchemaCraft\Tests\Fixtures\Resources;

use Illuminate\Database\Eloquent\Collection;
use SchemaCraft\Attributes\CollectionOf;
use SchemaCraft\Attributes\Resources\ResourceSchema;
use SchemaCraft\SchemaCraftResource;
use SchemaCraft\Tests\Fixtures\Schemas\CatalogVariantSchema;

/**
 * Collection recursion target for CatalogResource. Declares the relationships the SDK
 * needs to expose for variant responses — the Resource-walked dep walker only pulls
 * what's documented here, so suppliers / reviews / shipment must be declared explicitly
 * rather than inferred from CatalogVariantSchema's relationship graph.
 *
 * Cardinality at the Resource layer is binary — singular (property type only) vs collection
 * (Collection-typed property + #[CollectionOf]). DB-side mechanics (hasMany / belongsToMany /
 * morphMany / hasManyThrough / etc.) collapse here; they're schema-layer concerns, irrelevant
 * to what the response documents.
 */
#[ResourceSchema(CatalogVariantSchema::class)]
class CatalogVariantResource extends SchemaCraftResource
{
    public int $id;

    public string $sku;

    public float $price;

    public ?CatalogShipmentResource $shipment;

    #[CollectionOf(CatalogReviewResource::class)]
    public Collection $reviews;

    #[CollectionOf(CatalogSupplierResource::class)]
    public Collection $suppliers;

    #[CollectionOf(CatalogSupplierResource::class)]
    public Collection $brandSuppliers;
}
