<?php

namespace SchemaCraft\Tests\Fixtures\Models;

use SchemaCraft\SchemaModel;
use SchemaCraft\Tests\Fixtures\Schemas\CategorySchema;

/** @mixin CategorySchema */
class Category extends SchemaModel
{
    protected static string $schema = CategorySchema::class;
}
