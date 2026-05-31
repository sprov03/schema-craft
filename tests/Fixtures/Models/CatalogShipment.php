<?php

namespace SchemaCraft\Tests\Fixtures\Models;

use SchemaCraft\SchemaModel;
use SchemaCraft\Tests\Fixtures\Schemas\CatalogShipmentSchema;

/** @mixin CatalogShipmentSchema */
class CatalogShipment extends SchemaModel
{
    protected static string $schema = CatalogShipmentSchema::class;
}
