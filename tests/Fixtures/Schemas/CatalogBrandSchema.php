<?php

namespace SchemaCraft\Tests\Fixtures\Schemas;

use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Schema;

/**
 * BelongsTo target for CatalogSchema (FK only) and the `through` model for its
 * hasManyThrough. Pulled into the SDK as a dependency DTO only if reached via a
 * child relationship — belongsTo and hasManyThrough do NOT pull dependencies,
 * so this schema's DTO appears only because CatalogSupplierSchema hasOne's it.
 */
class CatalogBrandSchema extends Schema
{
    #[Primary]
    #[AutoIncrement]
    public int $id;

    public string $name;
}
