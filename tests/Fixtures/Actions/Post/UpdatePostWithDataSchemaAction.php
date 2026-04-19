<?php

namespace SchemaCraft\Tests\Fixtures\Actions\Post;

use Illuminate\Support\Collection;
use SchemaCraft\Action;
use SchemaCraft\Attributes\Actions\ActionMeta;
use SchemaCraft\Attributes\Pivot;
use SchemaCraft\Attributes\Relations\BelongsTo;
use SchemaCraft\Attributes\Relations\BelongsToMany;
use SchemaCraft\Attributes\Relations\HasMany;
use SchemaCraft\DataSchema;
use SchemaCraft\Tests\Fixtures\Models\User;
use SchemaCraft\Tests\Fixtures\Schemas\CommentSchema;
use SchemaCraft\Tests\Fixtures\Schemas\PostSchema;
use SchemaCraft\Tests\Fixtures\Schemas\TagSchema;

#[ActionMeta(route: 'update-post-with-action-schema', method: 'put', label: 'Update Post With Action Schema')]
class UpdatePostWithDataSchemaAction extends Action
{
    protected static string $schema = PostSchema::class;

    public string $title;

    #[BelongsTo(User::class)]
    public User $author;

    #[HasMany(UpdatePostCommentsDataSchema::class)]
    /** @var Collection<int, UpdatePostCommentsDataSchema> */
    public Collection $comments;

    #[BelongsToMany(UpdatePostTagsDataSchema::class)]
    /** @var Collection<int, UpdatePostTagsDataSchema> */
    public Collection $tags;

    public function run(mixed $record, array $mapped): mixed
    {
        return $record;
    }
}

class UpdatePostCommentsDataSchema extends DataSchema
{
    protected static string $schema = CommentSchema::class;

    public string $body;
}

class UpdatePostTagsDataSchema extends DataSchema
{
    protected static string $schema = TagSchema::class;

    public int $id;

    public string $name;

    #[Pivot]
    public ?int $sortOrder;
}
