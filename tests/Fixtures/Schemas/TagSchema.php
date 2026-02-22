<?php

namespace SchemaCraft\Tests\Fixtures\Schemas;

use Illuminate\Database\Eloquent\Collection;
use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\Fillable;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Attributes\Relations\BelongsToMany;
use SchemaCraft\Attributes\Unique;
use SchemaCraft\Schema;
use SchemaCraft\Tests\Fixtures\Models\Post;
use SchemaCraft\Traits\TimestampsSchema;

class TagSchema extends Schema
{
    use TimestampsSchema;

    #[Primary]
    #[AutoIncrement]
    public int $id;

    #[Fillable]
    public string $name;

    #[Fillable]
    #[Unique]
    public string $slug;

    /** @var Collection<int, Post> */
    #[BelongsToMany(Post::class)]
    public Collection $posts;
}
