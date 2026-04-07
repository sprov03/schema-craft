<?php

namespace SchemaCraft\Tests\Fixtures\Actions\Comment;

use Illuminate\Database\Eloquent\Model;
use SchemaCraft\Action;
use SchemaCraft\Attributes\Actions\ActionMeta;
use SchemaCraft\Attributes\Relations\MorphTo;
use SchemaCraft\Tests\Fixtures\Schemas\CommentSchema;

#[ActionMeta(method: 'post', label: 'Create Nullable Comment')]
class CreateNullableCommentAction extends Action
{
    protected static string $schema = CommentSchema::class;

    public string $body;

    #[MorphTo('commentable')]
    public ?Model $commentable;

    public function run(mixed $record, array $mapped): mixed
    {
        return $record;
    }
}
