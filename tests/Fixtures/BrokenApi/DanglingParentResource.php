<?php

namespace SchemaCraft\Tests\Fixtures\BrokenApi;

use SchemaCraft\Attributes\Resources\ResourceSchema;
use SchemaCraft\SchemaCraftResource;

/**
 * The documented response shape. It declares a singular relationship to a Resource that fails
 * to scan — so the relationship pointer dangles (the target never gets emitted into the SDK).
 */
#[ResourceSchema(DanglingSchema::class)]
class DanglingParentResource extends SchemaCraftResource
{
    public int $id;

    public ?UnscannableChildResource $child;
}
