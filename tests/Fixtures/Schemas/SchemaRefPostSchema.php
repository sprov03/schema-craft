<?php

namespace SchemaCraft\Tests\Fixtures\Schemas;

use Illuminate\Database\Eloquent\Collection;
use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\Fillable;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Attributes\Relations\BelongsTo;
use SchemaCraft\Attributes\Relations\BelongsToMany;
use SchemaCraft\Attributes\Relations\HasMany;
use SchemaCraft\Attributes\Relations\HasManyThrough;
use SchemaCraft\Schema;
use SchemaCraft\Tests\Fixtures\Models\CatalogSupplier;
use SchemaCraft\Tests\Fixtures\Models\Comment;
use SchemaCraft\Tests\Fixtures\Models\Tag;
use SchemaCraft\Tests\Fixtures\Models\User;
use SchemaCraft\Traits\TimestampsSchema;

/**
 * Fixture proving relation attributes accept a *Schema* class as the related
 * reference (schema-to-schema navigation) in addition to a Model class.
 *
 * Every relation here points at another Schema (UserSchema, CommentSchema, ...).
 * The scanner must resolve these down to their Models (User, Comment, ...) the
 * moment the attribute is read, so downstream consumers stay Model-only.
 * The property *types* remain the concrete Models — that is what Eloquent
 * returns at runtime; only the attribute argument is the Schema.
 */
class SchemaRefPostSchema extends Schema
{
    use TimestampsSchema;

    #[Primary]
    #[AutoIncrement]
    public int $id;

    #[Fillable]
    public string $title;

    // BelongsTo via Schema ref (was: BelongsTo(User::class))
    #[Fillable]
    #[BelongsTo(UserSchema::class, 'author_id')]
    public User $author;

    /** @var Collection<int, Comment> */
    #[HasMany(CommentSchema::class)]
    public Collection $comments;

    /** @var Collection<int, Tag> */
    #[BelongsToMany(TagSchema::class)]
    public Collection $tags;

    // HasManyThrough with BOTH the related and the `through` declared as Schemas.
    /** @var Collection<int, CatalogSupplier> */
    #[HasManyThrough(CatalogSupplierSchema::class, CatalogBrandSchema::class)]
    public Collection $brandSuppliers;
}
