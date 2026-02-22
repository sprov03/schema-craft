<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Generator\Api\ApiFileWriter;

class ApiFileWriterTest extends TestCase
{
    private ApiFileWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = new ApiFileWriter;
    }

    private function sampleController(): string
    {
        return <<<'PHP'
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Http\Requests\CreatePostRequest;
use App\Http\Requests\UpdatePostRequest;
use Illuminate\Support\Facades\Route;

class PostController extends Controller
{
    public static function apiRoutes(): void
    {
        Route::get('posts', [PostController::class, 'getCollection']);
        Route::get('posts/{post}', [PostController::class, 'get']);
        Route::post('posts', [PostController::class, 'create']);
        Route::put('posts/{post}', [PostController::class, 'update']);
        Route::delete('posts/{post}', [PostController::class, 'delete']);
    }

    public function getCollection()
    {
        // ...
    }

    public function get(Post $post)
    {
        // ...
    }
}
PHP;
    }

    private function sampleService(): string
    {
        return <<<'PHP'
<?php

namespace App\Models\Services;

use App\Models\Post;

class PostService
{
    private Post $post;

    public function __construct(Post $post)
    {
        $this->post = $post;
    }

    public function update(string $title): Post
    {
        $this->post->title = $title;
        $this->post->save();

        return $this->post;
    }

    public function delete(): void
    {
        $this->post->delete();
    }
}
PHP;
    }

    // ─── addRoute ─────────────────────────────────────────────────

    public function test_add_route_inserts_after_last_route(): void
    {
        $result = $this->writer->addRoute(
            $this->sampleController(),
            'put',
            'posts',
            'archive',
            'PostController',
            'post',
        );

        $this->assertStringContainsString(
            "Route::put('posts/{post}/archive', [PostController::class, 'archive']);",
            $result,
        );
    }

    public function test_add_route_preserves_existing_routes(): void
    {
        $result = $this->writer->addRoute(
            $this->sampleController(),
            'put',
            'posts',
            'archive',
            'PostController',
            'post',
        );

        $this->assertStringContainsString("Route::get('posts',", $result);
        $this->assertStringContainsString("Route::delete('posts/{post}',", $result);
    }

    // ─── addControllerMethod ──────────────────────────────────────

    public function test_add_controller_method(): void
    {
        $result = $this->writer->addControllerMethod(
            $this->sampleController(),
            'archive',
            'Post',
            'post',
            'ArchivePostRequest',
        );

        $this->assertStringContainsString('public function archive(ArchivePostRequest $request, Post $post)', $result);
        $this->assertStringContainsString('$post->Service()->archive(', $result);
    }

    public function test_add_controller_method_preserves_existing_methods(): void
    {
        $result = $this->writer->addControllerMethod(
            $this->sampleController(),
            'archive',
            'Post',
            'post',
            'ArchivePostRequest',
        );

        $this->assertStringContainsString('public function getCollection()', $result);
        $this->assertStringContainsString('public function get(Post $post)', $result);
    }

    // ─── addImport ────────────────────────────────────────────────

    public function test_add_import(): void
    {
        $result = $this->writer->addImport(
            $this->sampleController(),
            'App\\Http\\Requests\\ArchivePostRequest',
        );

        $this->assertStringContainsString('use App\\Http\\Requests\\ArchivePostRequest;', $result);
    }

    public function test_add_import_does_not_duplicate(): void
    {
        $result = $this->writer->addImport(
            $this->sampleController(),
            'App\\Models\\Post',
        );

        $this->assertSame(
            substr_count($result, 'use App\\Models\\Post;'),
            1,
        );
    }

    // ─── addServiceMethod ─────────────────────────────────────────

    public function test_add_service_method(): void
    {
        $result = $this->writer->addServiceMethod(
            $this->sampleService(),
            'archive',
            'Post',
            'post',
        );

        $this->assertStringContainsString('public function archive(): Post', $result);
        $this->assertStringContainsString('$this->post->save();', $result);
        $this->assertStringContainsString('return $this->post;', $result);
    }

    public function test_add_service_method_preserves_existing_methods(): void
    {
        $result = $this->writer->addServiceMethod(
            $this->sampleService(),
            'archive',
            'Post',
            'post',
        );

        $this->assertStringContainsString('public function update(string $title): Post', $result);
        $this->assertStringContainsString('public function delete(): void', $result);
    }

    // ─── Multiple actions added sequentially ──────────────────────

    public function test_multiple_actions_can_be_added(): void
    {
        $controller = $this->sampleController();

        // Add first action
        $controller = $this->writer->addImport($controller, 'App\\Http\\Requests\\ArchivePostRequest');
        $controller = $this->writer->addRoute($controller, 'put', 'posts', 'archive', 'PostController', 'post');
        $controller = $this->writer->addControllerMethod($controller, 'archive', 'Post', 'post', 'ArchivePostRequest');

        // Add second action
        $controller = $this->writer->addImport($controller, 'App\\Http\\Requests\\PublishPostRequest');
        $controller = $this->writer->addRoute($controller, 'put', 'posts', 'publish', 'PostController', 'post');
        $controller = $this->writer->addControllerMethod($controller, 'publish', 'Post', 'post', 'PublishPostRequest');

        $this->assertStringContainsString("Route::put('posts/{post}/archive',", $controller);
        $this->assertStringContainsString("Route::put('posts/{post}/publish',", $controller);
        $this->assertStringContainsString('public function archive(', $controller);
        $this->assertStringContainsString('public function publish(', $controller);
    }
}
