<?php

namespace SchemaCraft\Tests\Fixtures\Schemas;

use Illuminate\Database\Eloquent\Model;
use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Attributes\Relations\BelongsTo;
use SchemaCraft\Attributes\Relations\MorphTo;
use SchemaCraft\Schema;
use SchemaCraft\Tests\Fixtures\Models\User;
use SchemaCraft\Traits\TimestampsSchema;

class CommentSchema extends Schema
{
    use TimestampsSchema;

    #[Primary]
    #[AutoIncrement]
    public int $id;

    public string $body;

    #[BelongsTo(User::class)]
    public User $user;

    #[MorphTo('commentable')]
    public Model $commentable;
}
