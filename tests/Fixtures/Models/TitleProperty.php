<?php

namespace SchemaCraft\Tests\Fixtures\Models;

use SchemaCraft\SchemaModel;
use SchemaCraft\Tests\Fixtures\Schemas\TitlePropertySchema;

/** @mixin TitlePropertySchema */
class TitleProperty extends SchemaModel
{
    protected static string $schema = TitlePropertySchema::class;
}
