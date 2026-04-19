<?php

namespace SchemaCraft\Tests\Fixtures\Actions\Comment;

use Illuminate\Database\Eloquent\Model;
use SchemaCraft\Action;
use SchemaCraft\Attributes\Actions\ActionMeta;
use SchemaCraft\Attributes\Relations\BelongsTo;
use SchemaCraft\Attributes\Relations\MorphTo;
use SchemaCraft\Tests\Fixtures\Models\User;
use SchemaCraft\Tests\Fixtures\Schemas\CommentSchema;

#[ActionMeta(route: 'create-comment', method: 'post', label: 'Create Comment')]
class CreateCommentAction extends Action
{
    protected static string $schema = CommentSchema::class;

    public string $body;

    #[BelongsTo(User::class)]
    public User $user;

    #[MorphTo('commentable')]
    public Model $commentable;

    public function run(mixed $record, array $mapped): mixed
    {
        return $record;
    }
}
