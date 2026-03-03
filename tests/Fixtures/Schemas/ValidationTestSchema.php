<?php

namespace SchemaCraft\Tests\Fixtures\Schemas;

use Carbon\CarbonInterface;
use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\Length;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Attributes\Relations\BelongsTo;
use SchemaCraft\Attributes\Rules;
use SchemaCraft\Attributes\Text;
use SchemaCraft\Attributes\Unique;
use SchemaCraft\Schema;
use SchemaCraft\Tests\Fixtures\Enums\PostStatus;
use SchemaCraft\Tests\Fixtures\Models\User;
use SchemaCraft\Traits\TimestampsSchema;

class ValidationTestSchema extends Schema
{
    use TimestampsSchema;

    #[Primary]
    #[AutoIncrement]
    public int $id;

    #[Rules('min:3')]
    public string $title;

    #[Unique]
    #[Length(100)]
    public string $slug;

    #[Text]
    public ?string $body;

    public PostStatus $status = PostStatus::Draft;

    public bool $is_active = false;

    public ?CarbonInterface $published_at;

    public int $view_count = 0;

    public array $metadata = [];

    #[BelongsTo(User::class)]
    public User $author;

    #[BelongsTo(User::class)]
    public ?User $editor;
}
