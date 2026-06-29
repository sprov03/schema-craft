<?php

namespace SchemaCraft\Tests\Fixtures\Models;

use SchemaCraft\SchemaModel;
use SchemaCraft\Tests\Fixtures\Schemas\SchemaRefPostSchema;

/** @mixin SchemaRefPostSchema */
class SchemaRefPost extends SchemaModel
{
    protected static string $schema = SchemaRefPostSchema::class;

    protected $table = 'schema_ref_posts';
}
