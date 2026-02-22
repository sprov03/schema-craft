<?php

namespace SchemaCraft\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use SchemaCraft\SchemaModel;
use SchemaCraft\Tests\Fixtures\Schemas\PostSchema;

/** @mixin PostSchema */
class Post extends SchemaModel
{
    use SoftDeletes;

    protected static string $schema = PostSchema::class;
}
