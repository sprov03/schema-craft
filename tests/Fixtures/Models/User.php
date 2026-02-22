<?php

namespace SchemaCraft\Tests\Fixtures\Models;

use SchemaCraft\SchemaModel;
use SchemaCraft\Tests\Fixtures\Schemas\UserSchema;

/** @mixin UserSchema */
class User extends SchemaModel
{
    protected static string $schema = UserSchema::class;
}
