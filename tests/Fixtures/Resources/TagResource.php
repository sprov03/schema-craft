<?php

namespace SchemaCraft\Tests\Fixtures\Resources;

use SchemaCraft\Attributes\Resources\ResourceSchema;
use SchemaCraft\SchemaCraftResource;
use SchemaCraft\Tests\Fixtures\Schemas\TagSchema;

/**
 * HasMany recursion target for PostResource. Backed by TagSchema; the resource side
 * models PostSchema's BelongsToMany(Tag) as a serialized list (resource relation attrs
 * have no belongsToMany variant), producing a TagData[] field on the Post DTO.
 */
#[ResourceSchema(TagSchema::class)]
class TagResource extends SchemaCraftResource
{
    public int $id;

    public string $name;

    public string $slug;
}
