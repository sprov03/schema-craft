<?php

namespace SchemaCraft\Tests\Fixtures\Models;

use SchemaCraft\SchemaModel;
use SchemaCraft\Tests\Fixtures\Schemas\CatalogBrandSchema;

/** @mixin CatalogBrandSchema */
class CatalogBrand extends SchemaModel
{
    protected static string $schema = CatalogBrandSchema::class;
}
