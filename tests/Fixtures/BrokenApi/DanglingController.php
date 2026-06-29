<?php

namespace SchemaCraft\Tests\Fixtures\BrokenApi;

use SchemaCraft\Attributes\Api\ApiResponse;

/**
 * Maps to DanglingSchema by name (DanglingController → Dangling → DanglingSchema). Its one
 * endpoint returns DanglingParentResource, whose `child` relationship dangles.
 */
class DanglingController
{
    #[ApiResponse(DanglingParentResource::class)]
    public function show(): DanglingParentResource
    {
        // Body never runs — the SDK build is static analysis of the route + #[ApiResponse].
        return new DanglingParentResource(new \stdClass);
    }
}
