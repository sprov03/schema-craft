<?php

namespace SchemaCraft\Tests\Fixtures\Models;

use SchemaCraft\SchemaModel;
use SchemaCraft\Tests\Fixtures\Schemas\CatalogSupplierSchema;

/** @mixin CatalogSupplierSchema */
class CatalogSupplier extends SchemaModel
{
    protected static string $schema = CatalogSupplierSchema::class;
}
