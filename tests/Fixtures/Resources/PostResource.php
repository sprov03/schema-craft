<?php

namespace SchemaCraft\Tests\Fixtures\Resources;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use SchemaCraft\Attributes\Resources\CollectionOf;
use SchemaCraft\Attributes\Resources\ResourceSchema;
use SchemaCraft\SchemaCraftResource;
use SchemaCraft\Tests\Fixtures\Enums\PostStatus;
use SchemaCraft\Tests\Fixtures\Schemas\PostSchema;

/**
 * Documented response resource for PostController. Because every Post endpoint now resolves
 * this resource (#[ApiResponse]), PostData is generated via the resource-driven
 * generateFromFields path using exactly the fields declared here — not the raw PostSchema columns.
 *
 * Props are snake_case to match the API wire keys verbatim (no casing translation). The two
 * CollectionOf relations mirror PostSchema's comments (hasMany) and tags (belongsToMany) and
 * produce CommentData[]/TagData[] fields whose DTOs also arrive as Resource-walked dependencies.
 */
#[ResourceSchema(PostSchema::class)]
class PostResource extends SchemaCraftResource
{
    public int $id;

    public string $title;

    public string $slug;

    public ?string $subtitle;

    public ?string $body;

    public PostStatus $status; // string-backed enum -> string

    public float $price;

    public int $view_count;

    public bool $is_featured;

    public ?CarbonInterface $published_at; // datetime -> string

    #[CollectionOf(CommentResource::class)]
    public Collection $comments;

    #[CollectionOf(TagResource::class)]
    public Collection $tags;
}
