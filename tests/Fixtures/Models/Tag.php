<?php

namespace SchemaCraft\Tests\Fixtures\Models;

use SchemaCraft\SchemaModel;
use SchemaCraft\Tests\Fixtures\Schemas\TagSchema;

/** @mixin TagSchema */
class Tag extends SchemaModel
{
    protected static string $schema = TagSchema::class;
}
