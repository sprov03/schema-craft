<?php

namespace SchemaCraft\Tests\Fixtures\Models;

use SchemaCraft\SchemaModel;
use SchemaCraft\Tests\Fixtures\Schemas\CatalogVariantSchema;

/** @mixin CatalogVariantSchema */
class CatalogVariant extends SchemaModel
{
    protected static string $schema = CatalogVariantSchema::class;
}
