<?php

namespace SchemaCraft\Tests\Fixtures\Schemas;

use Illuminate\Database\Eloquent\Model;
use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Attributes\Relations\MorphTo;
use SchemaCraft\Schema;

/**
 * Reached as a morphMany dependency of CatalogSchema. Carries a morphTo back to
 * its parent — morphTo produces a `mixed` relationship in the schema-driven DTO
 * and is NOT walked as a dependency (DependencyResolver excludes belongsTo/morphTo).
 */
class CatalogReviewSchema extends Schema
{
    #[Primary]
    #[AutoIncrement]
    public int $id;

    public string $body;

    public int $rating;

    #[MorphTo('reviewable')]
    public Model $reviewable;
}
