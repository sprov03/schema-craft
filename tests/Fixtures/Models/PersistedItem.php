<?php

namespace SchemaCraft\Tests\Fixtures\Models;

use SchemaCraft\SchemaModel;
use SchemaCraft\Tests\Fixtures\Schemas\PersistedItemSchema;

/**
 * @mixin PersistedItemSchema
 */
class PersistedItem extends SchemaModel
{
    protected static string $schema = PersistedItemSchema::class;

    protected $table = 'persisted_items';
}
