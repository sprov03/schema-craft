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

    private function sampleTest(): string
    {
        return <<<'PHP'
<?php

namespace Tests\Feature\Controllers;

use Database\Factories\PostFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_get_collection(): void
    {
        $user = UserFactory::createDefault();
        $this->actingAs($user, 'sanctum');

        PostFactory::createDefaults(2);

        $response = $this->getJson('/api/posts');

        $response->assertOk();
    }

    public function test_can_delete(): void
    {
        $user = UserFactory::createDefault();
        $this->actingAs($user, 'sanctum');

        $post = PostFactory::createDefault();

        $response = $this->deleteJson('/api/posts/' . $post->id);

        $response->assertNoContent();
    }
}
PHP;
    }

    // ─── addRoute ─────────────────────────────────────────────────

    public function test_add_route_inserts_after_last_route(): void
    {
        $renderedRoute = "Route::put('posts/{post}/archive', [PostController::class, 'archive']);";

        $result = $this->writer->addRoute(
            $this->sampleController(),
            $renderedRoute,
        );

        $this->assertStringContainsString(
            "Route::put('posts/{post}/archive', [PostController::class, 'archive']);",
            $result,
        );
    }

    public function test_add_route_preserves_existing_routes(): void
    {
        $renderedRoute = "Route::put('posts/{post}/archive', [PostController::class, 'archive']);";

        $result = $this->writer->addRoute(
            $this->sampleController(),
            $renderedRoute,
        );

        $this->assertStringContainsString("Route::get('posts',", $result);
        $this->assertStringContainsString("Route::delete('posts/{post}',", $result);
    }

    // ─── addControllerMethod ──────────────────────────────────────

    public function test_add_controller_method(): void
    {
        $renderedMethod = <<<'PHP'
    public function archive(ArchivePostRequest $request, Post $post): \Illuminate\Http\JsonResponse
    {
        $post->Service()->archive(
            ...$request->validated()
        );

        return response()->json(null, 200);
    }
PHP;

        $result = $this->writer->addControllerMethod(
            $this->sampleController(),
            $renderedMethod,
        );

        $this->assertStringContainsString('public function archive(ArchivePostRequest $request, Post $post)', $result);
        $this->assertStringContainsString('$post->Service()->archive(', $result);
    }

    public function test_add_controller_method_preserves_existing_methods(): void
    {
        $renderedMethod = <<<'PHP'
    public function archive(ArchivePostRequest $request, Post $post): \Illuminate\Http\JsonResponse
    {
        $post->Service()->archive(
            ...$request->validated()
        );

        return response()->json(null, 200);
    }
PHP;

        $result = $this->writer->addControllerMethod(
            $this->sampleController(),
            $renderedMethod,
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
        $renderedMethod = <<<'PHP'
    public function archive(mixed ...$validated): Post
    {
        // TODO: Implement archive logic

        $this->post->save();

        return $this->post;
    }
PHP;

        $result = $this->writer->addServiceMethod(
            $this->sampleService(),
            $renderedMethod,
        );

        $this->assertStringContainsString('public function archive(mixed ...$validated): Post', $result);
        $this->assertStringContainsString('$this->post->save();', $result);
        $this->assertStringContainsString('return $this->post;', $result);
    }

    public function test_add_service_method_preserves_existing_methods(): void
    {
        $renderedMethod = <<<'PHP'
    public function archive(mixed ...$validated): Post
    {
        // TODO: Implement archive logic

        $this->post->save();

        return $this->post;
    }
PHP;

        $result = $this->writer->addServiceMethod(
            $this->sampleService(),
            $renderedMethod,
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
        $controller = $this->writer->addRoute(
            $controller,
            "Route::put('posts/{post}/archive', [PostController::class, 'archive']);",
        );
        $archiveMethod = <<<'PHP'
    public function archive(ArchivePostRequest $request, Post $post): \Illuminate\Http\JsonResponse
    {
        $post->Service()->archive(
            ...$request->validated()
        );

        return response()->json(null, 200);
    }
PHP;
        $controller = $this->writer->addControllerMethod($controller, $archiveMethod);

        // Add second action
        $controller = $this->writer->addImport($controller, 'App\\Http\\Requests\\PublishPostRequest');
        $controller = $this->writer->addRoute(
            $controller,
            "Route::put('posts/{post}/publish', [PostController::class, 'publish']);",
        );
        $publishMethod = <<<'PHP'
    public function publish(PublishPostRequest $request, Post $post): \Illuminate\Http\JsonResponse
    {
        $post->Service()->publish(
            ...$request->validated()
        );

        return response()->json(null, 200);
    }
PHP;
        $controller = $this->writer->addControllerMethod($controller, $publishMethod);

        $this->assertStringContainsString("Route::put('posts/{post}/archive',", $controller);
        $this->assertStringContainsString("Route::put('posts/{post}/publish',", $controller);
        $this->assertStringContainsString('public function archive(', $controller);
        $this->assertStringContainsString('public function publish(', $controller);
    }

    // ─── addTestMethod ────────────────────────────────────────────

    public function test_add_test_method(): void
    {
        $renderedMethod = <<<'PHP'
    public function test_can_archive(): void
    {
        $user = UserFactory::createDefault();
        $this->actingAs($user, 'sanctum');

        $post = PostFactory::createDefault();

        $response = $this->getJson('/api/posts/' . $post->id . '/archive');

        $response->assertOk();
    }
PHP;

        $result = $this->writer->addTestMethod(
            $this->sampleTest(),
            $renderedMethod,
        );

        $this->assertStringContainsString('public function test_can_archive(): void', $result);
        $this->assertStringContainsString("getJson('/api/posts/' . \$post->id . '/archive')", $result);
    }

    public function test_add_test_method_preserves_existing_tests(): void
    {
        $renderedMethod = <<<'PHP'
    public function test_can_archive(): void
    {
        // ...
    }
PHP;

        $result = $this->writer->addTestMethod(
            $this->sampleTest(),
            $renderedMethod,
        );

        $this->assertStringContainsString('public function test_can_get_collection(): void', $result);
        $this->assertStringContainsString('public function test_can_delete(): void', $result);
    }

    // ─── addControllerRegistration ────────────────────────────────

    public function test_add_controller_registration(): void
    {
        $routeFile = "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n";

        $result = $this->writer->addControllerRegistration(
            $routeFile,
            'App\\Http\\Controllers\\Api\\PostController',
            'PostController',
        );

        $this->assertStringContainsString('\\App\\Http\\Controllers\\Api\\PostController::apiRoutes();', $result);
    }

    public function test_add_controller_registration_does_not_duplicate(): void
    {
        $routeFile = "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\n\\App\\Http\\Controllers\\Api\\PostController::apiRoutes();\n";

        $result = $this->writer->addControllerRegistration(
            $routeFile,
            'App\\Http\\Controllers\\Api\\PostController',
            'PostController',
        );

        $this->assertSame(
            1,
            substr_count($result, 'PostController::apiRoutes()'),
        );
    }
}
