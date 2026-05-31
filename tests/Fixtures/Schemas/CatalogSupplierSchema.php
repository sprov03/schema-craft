<?php

namespace SchemaCraft\Tests\Fixtures\Schemas;

use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Attributes\Relations\HasOne;
use SchemaCraft\Schema;
use SchemaCraft\Tests\Fixtures\Models\CatalogBrand;

/**
 * Reached as a belongsToMany dependency of CatalogVariantSchema. Its hasOne back
 * to CatalogBrand is what pulls CatalogBrandSchema into the dependency tree
 * (belongsTo/hasManyThrough alone would never have).
 */
class CatalogSupplierSchema extends Schema
{
    #[Primary]
    #[AutoIncrement]
    public int $id;

    public string $name;

    #[HasOne(CatalogBrand::class)]
    public CatalogBrand $brand;
}
