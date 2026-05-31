<?php

namespace SchemaCraft\Tests\Fixtures\Api;

use Illuminate\Support\Facades\Route;
use SchemaCraft\Attributes\Api\ApiResponse;
use SchemaCraft\Tests\Fixtures\Resources\ActionResultResource;
use SchemaCraft\Tests\Fixtures\Resources\PostResource;

/**
 * Minimal API fixture for SDK generation tests.
 *
 * Mirrors the shape ResourceGenerator + ApiCodeGenerator would emit for
 * SchemaCraft\Tests\Fixtures\Schemas\PostSchema. Hand-written and permanent
 * so SDK feature tests can exercise the real RuntimeRouteScanner path
 * without having to generate, register, and tear down per test.
 *
 * Every endpoint is documented with #[ApiResponse] so none is filtered out by
 * SdkContextBuilder's undocumented-endpoint filter: the CRUD reads/writes return
 * PostData, and the void actions (delete/archive) return a structured ActionResultData.
 * Method bodies are intentionally empty — the SDK generator only reads route +
 * return-type + attribute metadata, not behavior.
 */
class PostController
{
    public static function apiRoutes(): void
    {
        Route::get('posts', [self::class, 'getCollection']);
        Route::get('posts/{post}', [self::class, 'get']);
        Route::post('posts', [self::class, 'create']);
        Route::put('posts/{post}', [self::class, 'update']);
        Route::delete('posts/{post}', [self::class, 'delete']);
        Route::post('posts/{post}/archive', [self::class, 'archive']);
    }

    #[ApiResponse(PostResource::class, collection: true)]
    public function getCollection() {}

    #[ApiResponse(PostResource::class)]
    public function get() {}

    #[ApiResponse(PostResource::class)]
    public function create() {}

    #[ApiResponse(PostResource::class)]
    public function update() {}

    #[ApiResponse(ActionResultResource::class)]
    public function delete() {}

    #[ApiResponse(ActionResultResource::class)]
    public function archive() {}
}
