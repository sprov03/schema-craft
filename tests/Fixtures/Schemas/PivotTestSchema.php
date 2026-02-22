<?php

namespace SchemaCraft\Tests\Fixtures\Schemas;

use Illuminate\Database\Eloquent\Collection;
use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\PivotColumns;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Attributes\Relations\BelongsToMany;
use SchemaCraft\Attributes\Relations\HasMany;
use SchemaCraft\Schema;
use SchemaCraft\Tests\Fixtures\Models\Comment;
use SchemaCraft\Tests\Fixtures\Models\Tag;
use SchemaCraft\Tests\Fixtures\Models\User;
use SchemaCraft\Traits\TimestampsSchema;

class PivotTestSchema extends Schema
{
    use TimestampsSchema;

    #[Primary]
    #[AutoIncrement]
    public int $id;

    public string $name;

    /** @var Collection<int, Tag> */
    #[BelongsToMany(Tag::class)]
    #[PivotColumns(['order' => 'integer', 'note' => 'string'])]
    public Collection $tags;

    /** @var Collection<int, User> */
    #[BelongsToMany(User::class)]
    #[PivotColumns(['role' => 'string'])]
    public Collection $members;

    /** @var Collection<int, Comment> */
    #[HasMany(Comment::class)]
    public Collection $comments;
}
