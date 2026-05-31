<?php

namespace SchemaCraft\Tests\Fixtures\Resources;

use SchemaCraft\Attributes\Resources\HasOne;
use SchemaCraft\Attributes\Resources\ResourceSchema;
use SchemaCraft\SchemaCraftResource;
use SchemaCraft\Tests\Fixtures\Schemas\CatalogSupplierSchema;

/**
 * Response Resource for CatalogSupplier. Reached via CatalogVariantResource's `suppliers`
 * and `brandSuppliers` relationships in the Resource-walked SDK dep resolution.
 *
 * Why this exists at all: before the Resource-walked cutover, the schema-walker pulled
 * CatalogSupplier in implicitly via CatalogVariantSchema's belongsToMany/hasManyThrough.
 * The new walker only includes what Resources explicitly document — so the supplier
 * shape is now declared here rather than inferred from the relationship graph.
 */
#[ResourceSchema(CatalogSupplierSchema::class)]
class CatalogSupplierResource extends SchemaCraftResource
{
    public int $id;

    public string $name;

    // CatalogSupplier hasOne CatalogBrand — declared so the SDK walker pulls CatalogBrandData
    // through the Resource layer. Mirrors the existing schema-walked behavior the goldens assert.
    #[HasOne(CatalogBrandResource::class)]
    public ?CatalogBrandResource $brand;
}
