<?php

namespace SchemaCraft\Tests\Fixtures\Actions\Post;

use SchemaCraft\Action;
use SchemaCraft\Attributes\Actions\ActionMeta;
use SchemaCraft\Attributes\Relations\BelongsTo;
use SchemaCraft\Attributes\Relations\BelongsToMany;
use SchemaCraft\Attributes\Relations\HasMany;
use SchemaCraft\Tests\Fixtures\Models\Comment;
use SchemaCraft\Tests\Fixtures\Models\Tag;
use SchemaCraft\Tests\Fixtures\Models\User;
use SchemaCraft\Tests\Fixtures\Schemas\PostSchema;

#[ActionMeta(method: 'put', label: 'Update Post With Relations')]
class UpdatePostWithRelationsAction extends Action
{
    protected static string $schema = PostSchema::class;

    public string $title;

    #[BelongsTo(User::class)]
    public User $author;

    #[HasMany(Comment::class, fields: ['body'])]
    public array $comments;

    #[BelongsToMany(Tag::class, fields: ['name'])]
    public array $tags;

    public function run(mixed $record, array $mapped): mixed
    {
        return $record;
    }
}
