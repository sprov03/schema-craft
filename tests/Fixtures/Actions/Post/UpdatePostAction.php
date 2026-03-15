<?php

namespace SchemaCraft\Tests\Fixtures\Actions\Post;

use SchemaCraft\Action;
use SchemaCraft\Attributes\Actions\ActionMeta;
use SchemaCraft\Attributes\Relations\BelongsTo;
use SchemaCraft\Tests\Fixtures\Models\Category;
use SchemaCraft\Tests\Fixtures\Models\User;
use SchemaCraft\Tests\Fixtures\Schemas\PostSchema;

#[ActionMeta(method: 'put', label: 'Update Post')]
class UpdatePostAction extends Action
{
    protected static string $schema = PostSchema::class;

    public string $title;

    public string $slug;

    public ?string $body;

    #[BelongsTo(User::class)]
    public User $author;

    #[BelongsTo(Category::class)]
    public ?Category $category;

    public function run(mixed $record, array $mapped): mixed
    {
        return $record;
    }
}
