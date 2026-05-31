<?php

namespace SchemaCraft\Tests\Fixtures\Schemas;

use Illuminate\Database\Eloquent\Collection;
use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Attributes\Relations\BelongsToMany;
use SchemaCraft\Attributes\Relations\HasManyThrough;
use SchemaCraft\Attributes\Relations\HasOne;
use SchemaCraft\Attributes\Relations\MorphMany;
use SchemaCraft\Schema;
use SchemaCraft\Tests\Fixtures\Models\CatalogBrand;
use SchemaCraft\Tests\Fixtures\Models\CatalogReview;
use SchemaCraft\Tests\Fixtures\Models\CatalogShipment;
use SchemaCraft\Tests\Fixtures\Models\CatalogSupplier;

/**
 * Reached as a hasMany dependency of CatalogSchema, so its DTO is generated via
 * the schema-driven SdkDataGenerator::generate() path (NOT the resource-driven
 * generateFromFields path). This is the fixture that exercises the relationship
 * collection/singular/mixed divergence in the schema-driven DTO:
 *   - hasOne          -> singular  (XData|null)
 *   - morphMany       -> collection (XData[]|null)
 *   - belongsToMany   -> collection (XData[]|null)  per SdkRelationshipCardinality::COLLECTION
 *   - hasManyThrough  -> collection (XData[]|null)  per SdkRelationshipCardinality::COLLECTION
 */
class CatalogVariantSchema extends Schema
{
    #[Primary]
    #[AutoIncrement]
    public int $id;

    public string $sku;

    public float $price;

    #[HasOne(CatalogShipment::class)]
    public CatalogShipment $shipment;

    /** @var Collection<int, CatalogReview> */
    #[MorphMany(CatalogReview::class, 'reviewable')]
    public Collection $reviews;

    /** @var Collection<int, CatalogSupplier> */
    #[BelongsToMany(CatalogSupplier::class)]
    public Collection $suppliers;

    /** @var Collection<int, CatalogSupplier> */
    #[HasManyThrough(CatalogSupplier::class, CatalogBrand::class)]
    public Collection $brandSuppliers;
}
