<?php

namespace SchemaCraft\Tests\Fixtures\Schemas;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\Decimal;
use SchemaCraft\Attributes\Fillable;
use SchemaCraft\Attributes\Hidden;
use SchemaCraft\Attributes\Index;
use SchemaCraft\Attributes\Length;
use SchemaCraft\Attributes\OnDelete;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Attributes\Relations\BelongsTo;
use SchemaCraft\Attributes\Relations\BelongsToMany;
use SchemaCraft\Attributes\Relations\HasMany;
use SchemaCraft\Attributes\Relations\MorphMany;
use SchemaCraft\Attributes\Text;
use SchemaCraft\Attributes\Unique;
use SchemaCraft\Attributes\Unsigned;
use SchemaCraft\Attributes\With;
use SchemaCraft\Schema;
use SchemaCraft\Tests\Fixtures\Casts\AddressData;
use SchemaCraft\Tests\Fixtures\Enums\PostStatus;
use SchemaCraft\Tests\Fixtures\Models\Category;
use SchemaCraft\Tests\Fixtures\Models\Comment;
use SchemaCraft\Tests\Fixtures\Models\Tag;
use SchemaCraft\Tests\Fixtures\Models\User;
use SchemaCraft\Traits\SoftDeletesSchema;
use SchemaCraft\Traits\TimestampsSchema;

#[Index(['status', 'published_at'])]
class PostSchema extends Schema
{
    use SoftDeletesSchema;
    use TimestampsSchema;

    #[Primary]
    #[AutoIncrement]
    public int $id;

    #[Fillable]
    public string $title;

    #[Fillable]
    #[Unique]
    public string $slug;

    #[Fillable]
    #[Length(100)]
    public string $subtitle;

    #[Fillable]
    #[Text]
    public ?string $body;

    #[Fillable]
    public PostStatus $status = PostStatus::Draft;

    #[Fillable]
    #[Decimal(10, 2)]
    #[Unsigned]
    public float $price;

    #[Unsigned]
    public int $view_count = 0;

    public bool $is_featured = false;

    public ?CarbonInterface $published_at;

    #[Hidden]
    public array $metadata = [];

    public ?AddressData $address;

    #[Fillable]
    #[BelongsTo(User::class)]
    #[OnDelete('cascade')]
    #[Index]
    #[With]
    public User $author;

    #[Fillable]
    #[BelongsTo(Category::class)]
    public ?Category $category;

    /** @var Collection<int, Comment> */
    #[HasMany(Comment::class)]
    public Collection $comments;

    /** @var Collection<int, Tag> */
    #[BelongsToMany(Tag::class)]
    public Collection $tags;

    /** @var Collection<int, Comment> */
    #[MorphMany(Comment::class, 'commentable')]
    public Collection $morphComments;
}
