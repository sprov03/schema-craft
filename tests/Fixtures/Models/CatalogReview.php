<?php

namespace SchemaCraft\Tests\Fixtures\Models;

use SchemaCraft\SchemaModel;
use SchemaCraft\Tests\Fixtures\Schemas\CatalogReviewSchema;

/** @mixin CatalogReviewSchema */
class CatalogReview extends SchemaModel
{
    protected static string $schema = CatalogReviewSchema::class;
}
