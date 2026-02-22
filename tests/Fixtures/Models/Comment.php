<?php

namespace SchemaCraft\Tests\Fixtures\Models;

use SchemaCraft\SchemaModel;
use SchemaCraft\Tests\Fixtures\Schemas\CommentSchema;

/** @mixin CommentSchema */
class Comment extends SchemaModel
{
    protected static string $schema = CommentSchema::class;
}
