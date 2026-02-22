<?php

namespace SchemaCraft\Tests\Fixtures\Schemas;

use Illuminate\Database\Eloquent\Collection;
use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\Fillable;
use SchemaCraft\Attributes\ForeignColumn;
use SchemaCraft\Attributes\Hidden;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Attributes\Relations\HasMany;
use SchemaCraft\Attributes\Unique;
use SchemaCraft\Schema;
use SchemaCraft\Tests\Fixtures\Models\Post;
use SchemaCraft\Traits\TimestampsSchema;

class UserSchema extends Schema
{
    use TimestampsSchema;

    #[Primary]
    #[AutoIncrement]
    public int $id;

    #[Fillable]
    public string $name;

    #[Fillable]
    #[Unique]
    public string $email;

    #[Fillable]
    #[Hidden]
    public string $password;

    /** @var Collection<int, Post> */
    #[HasMany(Post::class)]
    #[ForeignColumn('author_id')]
    public Collection $posts;
}
