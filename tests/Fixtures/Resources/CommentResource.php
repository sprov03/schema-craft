<?php

namespace SchemaCraft\Tests\Fixtures\Resources;

use SchemaCraft\Attributes\Resources\ResourceSchema;
use SchemaCraft\SchemaCraftResource;
use SchemaCraft\Tests\Fixtures\Schemas\CommentSchema;

/**
 * HasMany recursion target for PostResource. Backed by CommentSchema so the
 * resource-driven Post DTO embeds a CommentData[] relationship that lines up with
 * the schema-driven CommentData dependency DTO (PostSchema hasMany Comment).
 */
#[ResourceSchema(CommentSchema::class)]
class CommentResource extends SchemaCraftResource
{
    public int $id;

    public string $body;
}
