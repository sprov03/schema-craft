<?php

namespace SchemaCraft\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Orchestra\Testbench\TestCase;
use SchemaCraft\SchemaCraftServiceProvider;

class GenerateApiCommandTest extends TestCase
{
    private Filesystem $files;

    private array $createdFiles = [];

    protected function getPackageProviders($app): array
    {
        return [SchemaCraftServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->files = new Filesystem;
    }

    protected function tearDown(): void
    {
        // Clean up any files we created
        foreach ($this->createdFiles as $file) {
            if ($this->files->exists($file)) {
                $this->files->delete($file);
            }
        }

        // Clean up generated factory files
        $factoryDir = database_path('factories');
        if (is_dir($factoryDir)) {
            foreach ($this->files->files($factoryDir) as $file) {
                $this->files->delete($file);
            }
        }

        // Clean up generated model test files
        $testUnitDir = base_path('tests/Unit');
        if (is_dir($testUnitDir)) {
            foreach ($this->files->glob($testUnitDir.'/*ModelTest.php') as $file) {
                $this->files->delete($file);
            }
        }

        // Clean up generated controller test files
        $ctrlTestDirs = [
            base_path('tests/Feature/Controllers'),
            base_path('tests/Feature/Controllers/PartnerApi'),
        ];
        foreach ($ctrlTestDirs as $dir) {
            if (is_dir($dir)) {
                foreach ($this->files->glob($dir.'/*ControllerTest.php') as $file) {
                    $this->files->delete($file);
                }
            }
        }

        // Clean up empty directories
        $dirs = [
            app_path('Http/Controllers/Api'),
            app_path('Http/Controllers/PartnerApi'),
            app_path('Models/Services'),
            app_path('Services/PartnerApi'),
            app_path('Http/Requests'),
            app_path('Http/Requests/PartnerApi'),
            app_path('Resources'),
            app_path('Resources/PartnerApi'),
            database_path('factories'),
            base_path('tests/Feature/Controllers/PartnerApi'),
            base_path('tests/Feature/Controllers'),
        ];

        foreach ($dirs as $dir) {
            if (is_dir($dir) && count($this->files->files($dir)) === 0) {
                $this->files->deleteDirectory($dir);
            }
        }

        parent::tearDown();
    }

    private function trackFile(string $path): void
    {
        $this->createdFiles[] = $path;
    }

    // ─── Schema class resolution ──────────────────────────────────

    public function test_fails_for_nonexistent_schema(): void
    {
        $this->artisan('schema:generate', ['schema' => 'NonExistentSchema'])
            ->assertFailed();
    }

    public function test_resolves_short_name_with_schema_suffix(): void
    {
        // PostSchema exists as SchemaCraft\Tests\Fixtures\Schemas\PostSchema
        // but App\Schemas\PostSchema does not, so this should fail gracefully
        $this->artisan('schema:generate', ['schema' => 'Post'])
            ->assertFailed();
    }

    public function test_resolves_fqcn(): void
    {
        // Use the test fixture schema
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        // Track files for cleanup
        $this->trackFile(app_path('Http/Controllers/Api/PostController.php'));
        $this->trackFile(app_path('Models/Services/PostService.php'));
        $this->trackFile(app_path('Http/Requests/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PostResource.php'));
        $this->trackFile(app_path('Resources/CommentResource.php'));
        $this->trackFile(app_path('Resources/TagResource.php'));
    }

    // ─── File generation ──────────────────────────────────────────

    public function test_generates_all_files(): void
    {
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        $expectedFiles = [
            app_path('Http/Controllers/Api/PostController.php'),
            app_path('Models/Services/PostService.php'),
            app_path('Http/Requests/CreatePostRequest.php'),
            app_path('Http/Requests/UpdatePostRequest.php'),
            app_path('Resources/PostResource.php'),
            // Dependency resources from child relationships
            app_path('Resources/CommentResource.php'),
            app_path('Resources/TagResource.php'),
        ];

        foreach ($expectedFiles as $file) {
            $this->trackFile($file);
            $this->assertFileExists($file, "Expected file {$file} to be created");
        }
    }

    public function test_generated_controller_is_valid_php(): void
    {
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        $controllerPath = app_path('Http/Controllers/Api/PostController.php');
        $this->trackFile($controllerPath);
        $this->trackFile(app_path('Models/Services/PostService.php'));
        $this->trackFile(app_path('Http/Requests/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PostResource.php'));
        $this->trackFile(app_path('Resources/CommentResource.php'));
        $this->trackFile(app_path('Resources/TagResource.php'));

        $content = $this->files->get($controllerPath);
        $this->assertStringContainsString('class PostController extends Controller', $content);
        $this->assertStringContainsString('public static function apiRoutes(): void', $content);
    }

    public function test_generated_service_has_crud_methods(): void
    {
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        $servicePath = app_path('Models/Services/PostService.php');
        $this->trackFile(app_path('Http/Controllers/Api/PostController.php'));
        $this->trackFile($servicePath);
        $this->trackFile(app_path('Http/Requests/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PostResource.php'));
        $this->trackFile(app_path('Resources/CommentResource.php'));
        $this->trackFile(app_path('Resources/TagResource.php'));

        $content = $this->files->get($servicePath);
        $this->assertStringContainsString('public static function create(', $content);
        $this->assertStringContainsString('public function update(', $content);
        $this->assertStringContainsString('public function delete(): void', $content);
    }

    public function test_generated_requests_use_schema_rules(): void
    {
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/Api/PostController.php'));
        $this->trackFile(app_path('Models/Services/PostService.php'));
        $this->trackFile(app_path('Http/Requests/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PostResource.php'));
        $this->trackFile(app_path('Resources/CommentResource.php'));
        $this->trackFile(app_path('Resources/TagResource.php'));

        $createContent = $this->files->get(app_path('Http/Requests/CreatePostRequest.php'));
        $updateContent = $this->files->get(app_path('Http/Requests/UpdatePostRequest.php'));

        $this->assertStringContainsString('PostSchema::createRules(', $createContent);
        $this->assertStringContainsString('PostSchema::updateRules(', $updateContent);
    }

    // ─── --force flag ─────────────────────────────────────────────

    public function test_does_not_overwrite_without_force(): void
    {
        // First generation
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        $expectedFiles = [
            app_path('Http/Controllers/Api/PostController.php'),
            app_path('Models/Services/PostService.php'),
            app_path('Http/Requests/CreatePostRequest.php'),
            app_path('Http/Requests/UpdatePostRequest.php'),
            app_path('Resources/PostResource.php'),
            app_path('Resources/CommentResource.php'),
            app_path('Resources/TagResource.php'),
        ];
        foreach ($expectedFiles as $file) {
            $this->trackFile($file);
        }

        // Get modification times
        $mtime = filemtime(app_path('Http/Controllers/Api/PostController.php'));

        // Brief sleep to ensure different mtime
        usleep(10000);

        // Second generation without --force — should skip
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        // File should not have been modified
        $this->assertSame($mtime, filemtime(app_path('Http/Controllers/Api/PostController.php')));
    }

    public function test_overwrites_with_force(): void
    {
        // First generation
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        $expectedFiles = [
            app_path('Http/Controllers/Api/PostController.php'),
            app_path('Models/Services/PostService.php'),
            app_path('Http/Requests/CreatePostRequest.php'),
            app_path('Http/Requests/UpdatePostRequest.php'),
            app_path('Resources/PostResource.php'),
            app_path('Resources/CommentResource.php'),
            app_path('Resources/TagResource.php'),
        ];
        foreach ($expectedFiles as $file) {
            $this->trackFile($file);
        }

        // Write a marker to the file
        $this->files->put(app_path('Http/Controllers/Api/PostController.php'), '<?php // marker');

        // Second generation with --force — should overwrite
        $this->artisan('schema:generate', [
            'schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema',
            '--force' => true,
        ])->assertSuccessful();

        // File should have been overwritten
        $content = $this->files->get(app_path('Http/Controllers/Api/PostController.php'));
        $this->assertStringNotContainsString('// marker', $content);
        $this->assertStringContainsString('class PostController', $content);
    }

    // ─── Dependency resource cascading ──────────────────────────────

    public function test_generates_dependency_resources_for_child_relationships(): void
    {
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        // Track primary files
        $this->trackFile(app_path('Http/Controllers/Api/PostController.php'));
        $this->trackFile(app_path('Models/Services/PostService.php'));
        $this->trackFile(app_path('Http/Requests/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PostResource.php'));

        // PostSchema has HasMany(Comment), BelongsToMany(Tag), MorphMany(Comment)
        // Should generate dependency Resources for Comment and Tag
        $commentResource = app_path('Resources/CommentResource.php');
        $tagResource = app_path('Resources/TagResource.php');
        $this->trackFile($commentResource);
        $this->trackFile($tagResource);

        $this->assertFileExists($commentResource, 'Dependency CommentResource should be generated');
        $this->assertFileExists($tagResource, 'Dependency TagResource should be generated');
    }

    public function test_dependency_resources_contain_valid_php(): void
    {
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/Api/PostController.php'));
        $this->trackFile(app_path('Models/Services/PostService.php'));
        $this->trackFile(app_path('Http/Requests/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PostResource.php'));
        $this->trackFile(app_path('Resources/CommentResource.php'));
        $this->trackFile(app_path('Resources/TagResource.php'));

        $commentContent = $this->files->get(app_path('Resources/CommentResource.php'));
        $this->assertStringContainsString('class CommentResource extends JsonResource', $commentContent);
        $this->assertStringContainsString('namespace App\\Resources;', $commentContent);
        $this->assertStringContainsString('toArray(Request $request)', $commentContent);

        $tagContent = $this->files->get(app_path('Resources/TagResource.php'));
        $this->assertStringContainsString('class TagResource extends JsonResource', $tagContent);
        $this->assertStringContainsString('namespace App\\Resources;', $tagContent);
    }

    public function test_dependency_resources_include_schema_columns(): void
    {
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/Api/PostController.php'));
        $this->trackFile(app_path('Models/Services/PostService.php'));
        $this->trackFile(app_path('Http/Requests/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PostResource.php'));
        $this->trackFile(app_path('Resources/CommentResource.php'));
        $this->trackFile(app_path('Resources/TagResource.php'));

        // CommentSchema has: id, body, user_id, commentable_type, commentable_id, timestamps
        $commentContent = $this->files->get(app_path('Resources/CommentResource.php'));
        $this->assertStringContainsString("'id'", $commentContent);
        $this->assertStringContainsString("'body'", $commentContent);

        // TagSchema has: id, name, slug, timestamps
        $tagContent = $this->files->get(app_path('Resources/TagResource.php'));
        $this->assertStringContainsString("'id'", $tagContent);
        $this->assertStringContainsString("'name'", $tagContent);
        $this->assertStringContainsString("'slug'", $tagContent);
    }

    public function test_does_not_generate_dependency_controllers_or_services(): void
    {
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/Api/PostController.php'));
        $this->trackFile(app_path('Models/Services/PostService.php'));
        $this->trackFile(app_path('Http/Requests/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PostResource.php'));
        $this->trackFile(app_path('Resources/CommentResource.php'));
        $this->trackFile(app_path('Resources/TagResource.php'));

        // Only Resource files should be generated for dependencies — no Controller, Service, or Requests
        $this->assertFileDoesNotExist(app_path('Http/Controllers/Api/CommentController.php'));
        $this->assertFileDoesNotExist(app_path('Models/Services/CommentService.php'));
        $this->assertFileDoesNotExist(app_path('Http/Requests/CreateCommentRequest.php'));
        $this->assertFileDoesNotExist(app_path('Http/Controllers/Api/TagController.php'));
        $this->assertFileDoesNotExist(app_path('Models/Services/TagService.php'));
    }

    public function test_dependency_resources_respect_force_flag(): void
    {
        // First generation
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/Api/PostController.php'));
        $this->trackFile(app_path('Models/Services/PostService.php'));
        $this->trackFile(app_path('Http/Requests/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PostResource.php'));
        $this->trackFile(app_path('Resources/CommentResource.php'));
        $this->trackFile(app_path('Resources/TagResource.php'));

        // Write a marker to the dependency resource
        $this->files->put(app_path('Resources/CommentResource.php'), '<?php // marker');

        // Second generation without --force — dependency should NOT be overwritten
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        $content = $this->files->get(app_path('Resources/CommentResource.php'));
        $this->assertStringContainsString('// marker', $content);

        // Third generation with --force — dependency SHOULD be overwritten
        $this->artisan('schema:generate', [
            'schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema',
            '--force' => true,
        ])->assertSuccessful();

        $content = $this->files->get(app_path('Resources/CommentResource.php'));
        $this->assertStringNotContainsString('// marker', $content);
        $this->assertStringContainsString('class CommentResource', $content);
    }

    public function test_does_not_generate_dependency_resources_for_belongs_to(): void
    {
        // UserSchema has HasMany(Post) — BelongsTo relationships should NOT create deps
        // Generate for User, which has HasMany(Post::class)
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\UserSchema'])
            ->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/Api/UserController.php'));
        $this->trackFile(app_path('Models/Services/UserService.php'));
        $this->trackFile(app_path('Http/Requests/CreateUserRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdateUserRequest.php'));
        $this->trackFile(app_path('Resources/UserResource.php'));

        // UserSchema has HasMany(Post) → PostResource should be generated as dependency
        $this->trackFile(app_path('Resources/PostResource.php'));
        $this->assertFileExists(app_path('Resources/PostResource.php'));

        // PostSchema has BelongsTo(User) and BelongsTo(Category) — these should NOT create deps
        // But Post also has HasMany(Comment) and BelongsToMany(Tag) — these SHOULD cascade
        $this->trackFile(app_path('Resources/CommentResource.php'));
        $this->trackFile(app_path('Resources/TagResource.php'));

        // No controller/service for Post since it's a dependency
        $this->assertFileDoesNotExist(app_path('Http/Controllers/Api/PostController.php'));
    }

    public function test_schema_without_child_relationships_generates_no_dependency_resources(): void
    {
        // CategorySchema has no child relationships — only primary files
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\CategorySchema'])
            ->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/Api/CategoryController.php'));
        $this->trackFile(app_path('Models/Services/CategoryService.php'));
        $this->trackFile(app_path('Http/Requests/CreateCategoryRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdateCategoryRequest.php'));
        $this->trackFile(app_path('Resources/CategoryResource.php'));

        // Only the 5 primary files — no dependency resources
        $resourceDir = app_path('Resources');
        $resourceFiles = $this->files->files($resourceDir);

        $this->assertCount(1, $resourceFiles, 'Only CategoryResource should exist in Resources dir');
    }

    // ─── --api flag ────────────────────────────────────────────────

    public function test_api_option_generates_files_in_custom_namespaces(): void
    {
        config()->set('schema-craft.apis.partner', [
            'namespaces' => [
                'controller' => 'App\\Http\\Controllers\\PartnerApi',
                'request' => 'App\\Http\\Requests\\PartnerApi',
                'resource' => 'App\\Resources\\PartnerApi',
            ],
        ]);

        $this->artisan('schema:generate', [
            'schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\CategorySchema',
            '--api' => 'partner',
        ])->assertSuccessful();

        // Controller, request, resource use the API-specific namespaces
        // Service uses the connection-specific namespace (default: App\Models\Services)
        $expectedFiles = [
            app_path('Http/Controllers/PartnerApi/CategoryController.php'),
            app_path('Models/Services/CategoryService.php'),
            app_path('Http/Requests/PartnerApi/CreateCategoryRequest.php'),
            app_path('Http/Requests/PartnerApi/UpdateCategoryRequest.php'),
            app_path('Resources/PartnerApi/CategoryResource.php'),
        ];

        foreach ($expectedFiles as $file) {
            $this->trackFile($file);
            $this->assertFileExists($file, "Expected file {$file} to be created");
        }

        // Verify the controller uses the partner namespace
        $content = $this->files->get(app_path('Http/Controllers/PartnerApi/CategoryController.php'));
        $this->assertStringContainsString('namespace App\\Http\\Controllers\\PartnerApi;', $content);
    }

    public function test_api_option_uses_custom_resource_namespace_for_dependencies(): void
    {
        config()->set('schema-craft.apis.partner', [
            'namespaces' => [
                'controller' => 'App\\Http\\Controllers\\PartnerApi',
                'request' => 'App\\Http\\Requests\\PartnerApi',
                'resource' => 'App\\Resources\\PartnerApi',
            ],
        ]);

        $this->artisan('schema:generate', [
            'schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema',
            '--api' => 'partner',
        ])->assertSuccessful();

        // Track all files — service uses connection namespace, not API namespace
        $this->trackFile(app_path('Http/Controllers/PartnerApi/PostController.php'));
        $this->trackFile(app_path('Models/Services/PostService.php'));
        $this->trackFile(app_path('Http/Requests/PartnerApi/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/PartnerApi/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PartnerApi/PostResource.php'));
        $this->trackFile(app_path('Resources/PartnerApi/CommentResource.php'));
        $this->trackFile(app_path('Resources/PartnerApi/TagResource.php'));

        // Dependency resources should be in the partner namespace
        $commentContent = $this->files->get(app_path('Resources/PartnerApi/CommentResource.php'));
        $this->assertStringContainsString('namespace App\\Resources\\PartnerApi;', $commentContent);

        $tagContent = $this->files->get(app_path('Resources/PartnerApi/TagResource.php'));
        $this->assertStringContainsString('namespace App\\Resources\\PartnerApi;', $tagContent);
    }

    public function test_api_option_fails_for_invalid_api_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->artisan('schema:generate', [
            'schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema',
            '--api' => 'nonexistent',
        ]);
    }

    // ─── --action flag ────────────────────────────────────────────

    public function test_action_fails_without_existing_api(): void
    {
        $this->artisan('schema:generate', [
            'schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema',
            '--action' => 'cancel',
        ])->assertFailed();
    }

    public function test_action_adds_route_and_method(): void
    {
        // Generate the base API first
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        $expectedFiles = [
            app_path('Http/Controllers/Api/PostController.php'),
            app_path('Models/Services/PostService.php'),
            app_path('Http/Requests/CreatePostRequest.php'),
            app_path('Http/Requests/UpdatePostRequest.php'),
            app_path('Resources/PostResource.php'),
            app_path('Resources/CommentResource.php'),
            app_path('Resources/TagResource.php'),
        ];
        foreach ($expectedFiles as $file) {
            $this->trackFile($file);
        }

        // Add a new action
        $this->artisan('schema:generate', [
            'schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema',
            '--action' => 'archive',
        ])->assertSuccessful();

        $this->trackFile(app_path('Http/Requests/ArchivePostRequest.php'));

        // Check controller was updated with route and method
        $controllerContent = $this->files->get(app_path('Http/Controllers/Api/PostController.php'));
        $this->assertStringContainsString("'archive'", $controllerContent);
        $this->assertStringContainsString('public function archive(', $controllerContent);

        // Check controller uses int ID and model resolver
        $this->assertStringContainsString('int $post_id', $controllerContent);
        $this->assertStringContainsString('Post::forAuthUser()->findOrFail($post_id)', $controllerContent);

        // Check service was updated with typed params
        $serviceContent = $this->files->get(app_path('Models/Services/PostService.php'));
        $this->assertStringContainsString('public function archive(', $serviceContent);
        $this->assertStringContainsString('string $title', $serviceContent);

        // Check request was created
        $this->assertFileExists(app_path('Http/Requests/ArchivePostRequest.php'));
        $requestContent = $this->files->get(app_path('Http/Requests/ArchivePostRequest.php'));
        $this->assertStringContainsString('class ArchivePostRequest extends FormRequest', $requestContent);
    }

    public function test_action_request_includes_schema_context(): void
    {
        // Generate the base API first
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/Api/PostController.php'));
        $this->trackFile(app_path('Models/Services/PostService.php'));
        $this->trackFile(app_path('Http/Requests/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PostResource.php'));
        $this->trackFile(app_path('Resources/CommentResource.php'));
        $this->trackFile(app_path('Resources/TagResource.php'));

        // Add an action (defaults to update type)
        $this->artisan('schema:generate', [
            'schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema',
            '--action' => 'archive',
        ])->assertSuccessful();

        $this->trackFile(app_path('Http/Requests/ArchivePostRequest.php'));

        $requestContent = $this->files->get(app_path('Http/Requests/ArchivePostRequest.php'));

        // Should have schema import (uses the connection-configured schema namespace)
        $this->assertStringContainsString('use App\\Schemas\\PostSchema;', $requestContent);

        // Should call updateRules() with column names (default action type is update)
        $this->assertStringContainsString('PostSchema::updateRules([', $requestContent);
        $this->assertStringContainsString("'title',", $requestContent);
        $this->assertStringContainsString("'body',", $requestContent);
        $this->assertStringContainsString('])->toArray();', $requestContent);
    }

    public function test_post_method_uses_create_rules(): void
    {
        // Generate the base API first
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/Api/PostController.php'));
        $this->trackFile(app_path('Models/Services/PostService.php'));
        $this->trackFile(app_path('Http/Requests/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PostResource.php'));
        $this->trackFile(app_path('Resources/CommentResource.php'));
        $this->trackFile(app_path('Resources/TagResource.php'));

        // Add an action with POST method — should derive createRules
        $this->artisan('schema:generate', [
            'schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema',
            '--action' => 'duplicate',
            '--method' => 'post',
        ])->assertSuccessful();

        $this->trackFile(app_path('Http/Requests/DuplicatePostRequest.php'));

        $requestContent = $this->files->get(app_path('Http/Requests/DuplicatePostRequest.php'));

        // POST method should produce createRules()
        $this->assertStringContainsString('PostSchema::createRules([', $requestContent);
        $this->assertStringContainsString("'title',", $requestContent);
        $this->assertStringContainsString('])->toArray();', $requestContent);

        // Controller method should accept the request and int ID
        $controllerContent = $this->files->get(app_path('Http/Controllers/Api/PostController.php'));
        $this->assertStringContainsString('DuplicatePostRequest $request', $controllerContent);
        $this->assertStringContainsString('int $post_id', $controllerContent);
        $this->assertStringContainsString("Route::post('posts/{post_id}/duplicate'", $controllerContent);
    }

    public function test_get_action_skips_request_generation(): void
    {
        // Generate the base API first
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/Api/PostController.php'));
        $this->trackFile(app_path('Models/Services/PostService.php'));
        $this->trackFile(app_path('Http/Requests/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PostResource.php'));
        $this->trackFile(app_path('Resources/CommentResource.php'));
        $this->trackFile(app_path('Resources/TagResource.php'));

        // Add action with --method=get
        $this->artisan('schema:generate', [
            'schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema',
            '--action' => 'status',
            '--method' => 'get',
        ])->assertSuccessful();

        // GET should NOT create a request file
        $this->assertFileDoesNotExist(app_path('Http/Requests/StatusPostRequest.php'));

        $controllerContent = $this->files->get(app_path('Http/Controllers/Api/PostController.php'));
        $this->assertStringContainsString("Route::get('posts/{post_id}/status'", $controllerContent);

        // Controller method should use int ID, NOT a request parameter
        $this->assertStringContainsString('public function status(int $post_id)', $controllerContent);
        $this->assertStringContainsString('Post::forAuthUser()->findOrFail($post_id)', $controllerContent);
        $this->assertStringNotContainsString('StatusPostRequest', $controllerContent);

        // Service method should NOT have validated params
        $serviceContent = $this->files->get(app_path('Models/Services/PostService.php'));
        $this->assertStringContainsString('public function status(): Post', $serviceContent);
    }

    public function test_delete_action_skips_request_generation(): void
    {
        // Generate the base API first
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/Api/PostController.php'));
        $this->trackFile(app_path('Models/Services/PostService.php'));
        $this->trackFile(app_path('Http/Requests/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PostResource.php'));
        $this->trackFile(app_path('Resources/CommentResource.php'));
        $this->trackFile(app_path('Resources/TagResource.php'));

        // Add action with --method=delete
        $this->artisan('schema:generate', [
            'schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema',
            '--action' => 'softDelete',
            '--method' => 'delete',
        ])->assertSuccessful();

        // DELETE should NOT create a request file
        $this->assertFileDoesNotExist(app_path('Http/Requests/SoftDeletePostRequest.php'));

        $controllerContent = $this->files->get(app_path('Http/Controllers/Api/PostController.php'));
        $this->assertStringContainsString("Route::delete('posts/{post_id}/soft-delete'", $controllerContent);

        // Controller method should use int ID, NOT a request parameter
        $this->assertStringContainsString('public function softDelete(int $post_id)', $controllerContent);
        $this->assertStringContainsString('Post::forAuthUser()->findOrFail($post_id)', $controllerContent);
        $this->assertStringNotContainsString('SoftDeletePostRequest', $controllerContent);
    }

    public function test_action_default_http_method_is_put(): void
    {
        // Generate the base API first
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/Api/PostController.php'));
        $this->trackFile(app_path('Models/Services/PostService.php'));
        $this->trackFile(app_path('Http/Requests/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PostResource.php'));
        $this->trackFile(app_path('Resources/CommentResource.php'));
        $this->trackFile(app_path('Resources/TagResource.php'));

        // Add action without specifying --method
        $this->artisan('schema:generate', [
            'schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema',
            '--action' => 'archive',
        ])->assertSuccessful();

        $this->trackFile(app_path('Http/Requests/ArchivePostRequest.php'));

        $controllerContent = $this->files->get(app_path('Http/Controllers/Api/PostController.php'));
        $this->assertStringContainsString("Route::put('posts/{post_id}/archive'", $controllerContent);
    }

    public function test_action_route_includes_id_param(): void
    {
        // Generate the base API first
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/Api/PostController.php'));
        $this->trackFile(app_path('Models/Services/PostService.php'));
        $this->trackFile(app_path('Http/Requests/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PostResource.php'));
        $this->trackFile(app_path('Resources/CommentResource.php'));
        $this->trackFile(app_path('Resources/TagResource.php'));

        // Add an action
        $this->artisan('schema:generate', [
            'schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema',
            '--action' => 'publish',
        ])->assertSuccessful();

        $this->trackFile(app_path('Http/Requests/PublishPostRequest.php'));

        $controllerContent = $this->files->get(app_path('Http/Controllers/Api/PostController.php'));

        // Route should include snake_case ID parameter
        $this->assertStringContainsString('{post_id}', $controllerContent);
        $this->assertStringContainsString("Route::put('posts/{post_id}/publish'", $controllerContent);

        // Controller method should use int ID parameter
        $this->assertStringContainsString('int $post_id', $controllerContent);
    }

    public function test_action_controller_resolves_model_with_model_resolver(): void
    {
        // Generate the base API first
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/Api/PostController.php'));
        $this->trackFile(app_path('Models/Services/PostService.php'));
        $this->trackFile(app_path('Http/Requests/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PostResource.php'));
        $this->trackFile(app_path('Resources/CommentResource.php'));
        $this->trackFile(app_path('Resources/TagResource.php'));

        // Add a GET action
        $this->artisan('schema:generate', [
            'schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema',
            '--action' => 'preview',
            '--method' => 'get',
        ])->assertSuccessful();

        $controllerContent = $this->files->get(app_path('Http/Controllers/Api/PostController.php'));

        // Should resolve model via forAuthUser pattern
        $this->assertStringContainsString('$post = Post::forAuthUser()->findOrFail($post_id)', $controllerContent);
        $this->assertStringContainsString('$post->Service()->preview()', $controllerContent);
    }

    public function test_action_decodes_fk_fields_via_model_resolver(): void
    {
        // Generate the base API first
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/Api/PostController.php'));
        $this->trackFile(app_path('Models/Services/PostService.php'));
        $this->trackFile(app_path('Http/Requests/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PostResource.php'));
        $this->trackFile(app_path('Resources/CommentResource.php'));
        $this->trackFile(app_path('Resources/TagResource.php'));

        // Add a PUT action (generates decoded request properties)
        $this->artisan('schema:generate', [
            'schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema',
            '--action' => 'revise',
        ])->assertSuccessful();

        $this->trackFile(app_path('Http/Requests/RevisePostRequest.php'));

        $controllerContent = $this->files->get(app_path('Http/Controllers/Api/PostController.php'));

        // Primitive fields should use $request->validated()['col']
        $this->assertStringContainsString("\$request->validated()['title']", $controllerContent);

        // FK field (author_id) should be resolved via model resolver
        $this->assertStringContainsString("User::forAuthUser()->findOrFail(\$request->validated()['author_id'])", $controllerContent);

        // FK model should be imported
        $this->assertStringContainsString('use SchemaCraft\Tests\Fixtures\Models\User;', $controllerContent);
    }

    public function test_action_decodes_nullable_fk_fields(): void
    {
        // Generate the base API first
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/Api/PostController.php'));
        $this->trackFile(app_path('Models/Services/PostService.php'));
        $this->trackFile(app_path('Http/Requests/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PostResource.php'));
        $this->trackFile(app_path('Resources/CommentResource.php'));
        $this->trackFile(app_path('Resources/TagResource.php'));

        // Add a PUT action
        $this->artisan('schema:generate', [
            'schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema',
            '--action' => 'reassign',
        ])->assertSuccessful();

        $this->trackFile(app_path('Http/Requests/ReassignPostRequest.php'));

        $controllerContent = $this->files->get(app_path('Http/Controllers/Api/PostController.php'));

        // Nullable FK (category_id) should use the nullable model resolver pattern (find instead of findOrFail)
        $this->assertStringContainsString('Category::forAuthUser()->find(', $controllerContent);
    }

    public function test_action_service_method_has_typed_params(): void
    {
        // Generate the base API first
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/Api/PostController.php'));
        $this->trackFile(app_path('Models/Services/PostService.php'));
        $this->trackFile(app_path('Http/Requests/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PostResource.php'));
        $this->trackFile(app_path('Resources/CommentResource.php'));
        $this->trackFile(app_path('Resources/TagResource.php'));

        // Add a PUT action
        $this->artisan('schema:generate', [
            'schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema',
            '--action' => 'update_content',
        ])->assertSuccessful();

        $this->trackFile(app_path('Http/Requests/UpdateContentPostRequest.php'));

        $serviceContent = $this->files->get(app_path('Models/Services/PostService.php'));

        // Service method should have typed params for primitives
        $this->assertStringContainsString('string $title', $serviceContent);

        // Service method should have typed model params for FKs
        $this->assertStringContainsString('User $author', $serviceContent);

        // Nullable FK should have nullable type hint
        $this->assertStringContainsString('?Category $category', $serviceContent);
    }

    public function test_initial_generate_registers_in_route_file(): void
    {
        // Ensure route file exists
        $routeFile = base_path('routes/api.php');
        $this->trackFile($routeFile);

        $initialContent = "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n";
        $this->files->ensureDirectoryExists(dirname($routeFile));
        $this->files->put($routeFile, $initialContent);

        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/Api/PostController.php'));
        $this->trackFile(app_path('Models/Services/PostService.php'));
        $this->trackFile(app_path('Http/Requests/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PostResource.php'));
        $this->trackFile(app_path('Resources/CommentResource.php'));
        $this->trackFile(app_path('Resources/TagResource.php'));

        // Route file should contain the apiRoutes() registration
        $routeContent = $this->files->get($routeFile);
        $this->assertStringContainsString('PostController::apiRoutes()', $routeContent);
    }

    // ─── Factory generation ──────────────────────────────────────

    public function test_generates_factory_by_default(): void
    {
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/Api/PostController.php'));
        $this->trackFile(app_path('Models/Services/PostService.php'));
        $this->trackFile(app_path('Http/Requests/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PostResource.php'));
        $this->trackFile(app_path('Resources/CommentResource.php'));
        $this->trackFile(app_path('Resources/TagResource.php'));

        $factoryPath = database_path('factories/PostFactory.php');
        $this->assertFileExists($factoryPath, 'Factory file should be generated by default');
    }

    public function test_no_factory_flag_skips_factory(): void
    {
        $this->artisan('schema:generate', [
            'schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema',
            '--no-factory' => true,
        ])->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/Api/PostController.php'));
        $this->trackFile(app_path('Models/Services/PostService.php'));
        $this->trackFile(app_path('Http/Requests/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PostResource.php'));
        $this->trackFile(app_path('Resources/CommentResource.php'));
        $this->trackFile(app_path('Resources/TagResource.php'));

        $factoryPath = database_path('factories/PostFactory.php');
        $this->assertFileDoesNotExist($factoryPath, 'Factory file should not be generated with --no-factory');
    }

    public function test_generated_factory_has_correct_structure(): void
    {
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/Api/PostController.php'));
        $this->trackFile(app_path('Models/Services/PostService.php'));
        $this->trackFile(app_path('Http/Requests/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PostResource.php'));
        $this->trackFile(app_path('Resources/CommentResource.php'));
        $this->trackFile(app_path('Resources/TagResource.php'));

        $factoryPath = database_path('factories/PostFactory.php');
        $content = $this->files->get($factoryPath);

        $this->assertStringContainsString('namespace Database\Factories;', $content);
        $this->assertStringContainsString('class PostFactory', $content);
        $this->assertStringContainsString('public static function makeDefault(array $data = []): Post', $content);
        $this->assertStringContainsString('public static function createDefault(array $data = []): Post', $content);
        $this->assertStringContainsString('public static function createDefaults(int $number, array $data = []): Collection', $content);
    }

    public function test_generated_factory_is_valid_php(): void
    {
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/Api/PostController.php'));
        $this->trackFile(app_path('Models/Services/PostService.php'));
        $this->trackFile(app_path('Http/Requests/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PostResource.php'));
        $this->trackFile(app_path('Resources/CommentResource.php'));
        $this->trackFile(app_path('Resources/TagResource.php'));

        $factoryPath = database_path('factories/PostFactory.php');

        exec("php -l {$factoryPath} 2>&1", $output, $exitCode);
        $this->assertEquals(0, $exitCode, "Generated factory has syntax errors:\n".implode("\n", $output));
    }

    public function test_generated_factory_includes_belongs_to_auto_association(): void
    {
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/Api/PostController.php'));
        $this->trackFile(app_path('Models/Services/PostService.php'));
        $this->trackFile(app_path('Http/Requests/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PostResource.php'));
        $this->trackFile(app_path('Resources/CommentResource.php'));
        $this->trackFile(app_path('Resources/TagResource.php'));

        $factoryPath = database_path('factories/PostFactory.php');
        $content = $this->files->get($factoryPath);

        // PostSchema has BelongsTo(User) as 'author' and BelongsTo(Category)
        $this->assertStringContainsString('UserFactory::createDefault()', $content);
        $this->assertStringContainsString('CategoryFactory::createDefault()', $content);
    }

    // ─── Model test generation ───────────────────────────────────

    public function test_generates_model_test_by_default(): void
    {
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/Api/PostController.php'));
        $this->trackFile(app_path('Models/Services/PostService.php'));
        $this->trackFile(app_path('Http/Requests/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PostResource.php'));
        $this->trackFile(app_path('Resources/CommentResource.php'));
        $this->trackFile(app_path('Resources/TagResource.php'));

        $testPath = base_path('tests/Unit/PostModelTest.php');
        $this->assertFileExists($testPath, 'Model test file should be generated by default');
    }

    public function test_no_test_flag_skips_model_test(): void
    {
        $this->artisan('schema:generate', [
            'schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema',
            '--no-test' => true,
        ])->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/Api/PostController.php'));
        $this->trackFile(app_path('Models/Services/PostService.php'));
        $this->trackFile(app_path('Http/Requests/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PostResource.php'));
        $this->trackFile(app_path('Resources/CommentResource.php'));
        $this->trackFile(app_path('Resources/TagResource.php'));

        $testPath = base_path('tests/Unit/PostModelTest.php');
        $this->assertFileDoesNotExist($testPath, 'Model test file should not be generated with --no-test');
    }

    public function test_generated_model_test_has_correct_structure(): void
    {
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/Api/PostController.php'));
        $this->trackFile(app_path('Models/Services/PostService.php'));
        $this->trackFile(app_path('Http/Requests/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PostResource.php'));
        $this->trackFile(app_path('Resources/CommentResource.php'));
        $this->trackFile(app_path('Resources/TagResource.php'));

        $testPath = base_path('tests/Unit/PostModelTest.php');
        $content = $this->files->get($testPath);

        $this->assertStringContainsString('class PostModelTest extends TestCase', $content);
        $this->assertStringContainsString('use RefreshDatabase;', $content);
        $this->assertStringContainsString('PostFactory::createDefault()', $content);
        $this->assertStringContainsString('test_can_create_model', $content);
    }

    public function test_generated_model_test_includes_relationship_tests(): void
    {
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/Api/PostController.php'));
        $this->trackFile(app_path('Models/Services/PostService.php'));
        $this->trackFile(app_path('Http/Requests/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PostResource.php'));
        $this->trackFile(app_path('Resources/CommentResource.php'));
        $this->trackFile(app_path('Resources/TagResource.php'));

        $testPath = base_path('tests/Unit/PostModelTest.php');
        $content = $this->files->get($testPath);

        // PostSchema has BelongsTo(User) as 'author' and BelongsTo(Category)
        $this->assertStringContainsString('test_author_relationship', $content);
        $this->assertStringContainsString('test_category_relationship', $content);
    }

    public function test_generated_model_test_is_valid_php(): void
    {
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/Api/PostController.php'));
        $this->trackFile(app_path('Models/Services/PostService.php'));
        $this->trackFile(app_path('Http/Requests/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PostResource.php'));
        $this->trackFile(app_path('Resources/CommentResource.php'));
        $this->trackFile(app_path('Resources/TagResource.php'));

        $testPath = base_path('tests/Unit/PostModelTest.php');

        exec("php -l {$testPath} 2>&1", $output, $exitCode);
        $this->assertEquals(0, $exitCode, "Generated model test has syntax errors:\n".implode("\n", $output));
    }

    // ─── Controller test generation ──────────────────────────────

    public function test_generates_controller_test_by_default(): void
    {
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/Api/PostController.php'));
        $this->trackFile(app_path('Models/Services/PostService.php'));
        $this->trackFile(app_path('Http/Requests/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PostResource.php'));
        $this->trackFile(app_path('Resources/CommentResource.php'));
        $this->trackFile(app_path('Resources/TagResource.php'));

        $testPath = base_path('tests/Feature/Controllers/PostControllerTest.php');
        $this->assertFileExists($testPath, 'Controller test file should be generated by default');
    }

    public function test_no_test_flag_skips_controller_test(): void
    {
        $this->artisan('schema:generate', [
            'schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema',
            '--no-test' => true,
        ])->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/Api/PostController.php'));
        $this->trackFile(app_path('Models/Services/PostService.php'));
        $this->trackFile(app_path('Http/Requests/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PostResource.php'));
        $this->trackFile(app_path('Resources/CommentResource.php'));
        $this->trackFile(app_path('Resources/TagResource.php'));

        $testPath = base_path('tests/Feature/Controllers/PostControllerTest.php');
        $this->assertFileDoesNotExist($testPath, 'Controller test should not be generated with --no-test');
    }

    public function test_generated_controller_test_has_crud_methods(): void
    {
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/Api/PostController.php'));
        $this->trackFile(app_path('Models/Services/PostService.php'));
        $this->trackFile(app_path('Http/Requests/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PostResource.php'));
        $this->trackFile(app_path('Resources/CommentResource.php'));
        $this->trackFile(app_path('Resources/TagResource.php'));

        $testPath = base_path('tests/Feature/Controllers/PostControllerTest.php');
        $content = $this->files->get($testPath);

        $this->assertStringContainsString('test_can_get_collection', $content);
        $this->assertStringContainsString('test_can_get_single', $content);
        $this->assertStringContainsString('test_can_create', $content);
        $this->assertStringContainsString('test_can_update', $content);
        $this->assertStringContainsString('test_can_delete', $content);
        $this->assertStringContainsString("actingAs(\$user, 'sanctum')", $content);
    }

    public function test_generated_controller_test_is_valid_php(): void
    {
        $this->artisan('schema:generate', ['schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema'])
            ->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/Api/PostController.php'));
        $this->trackFile(app_path('Models/Services/PostService.php'));
        $this->trackFile(app_path('Http/Requests/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PostResource.php'));
        $this->trackFile(app_path('Resources/CommentResource.php'));
        $this->trackFile(app_path('Resources/TagResource.php'));

        $testPath = base_path('tests/Feature/Controllers/PostControllerTest.php');

        exec("php -l {$testPath} 2>&1", $output, $exitCode);
        $this->assertEquals(0, $exitCode, "Generated controller test has syntax errors:\n".implode("\n", $output));
    }

    public function test_api_option_places_controller_test_in_api_directory(): void
    {
        config()->set('schema-craft.apis.partner', [
            'namespaces' => [
                'controller' => 'App\\Http\\Controllers\\PartnerApi',
                'service' => 'App\\Services\\PartnerApi',
                'request' => 'App\\Http\\Requests\\PartnerApi',
                'resource' => 'App\\Resources\\PartnerApi',
            ],
        ]);

        $this->artisan('schema:generate', [
            'schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\CategorySchema',
            '--api' => 'partner',
        ])->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/PartnerApi/CategoryController.php'));
        $this->trackFile(app_path('Services/PartnerApi/CategoryService.php'));
        $this->trackFile(app_path('Http/Requests/PartnerApi/CreateCategoryRequest.php'));
        $this->trackFile(app_path('Http/Requests/PartnerApi/UpdateCategoryRequest.php'));
        $this->trackFile(app_path('Resources/PartnerApi/CategoryResource.php'));

        // Controller test should be in the PartnerApi directory
        $testPath = base_path('tests/Feature/Controllers/PartnerApi/CategoryControllerTest.php');
        $this->assertFileExists($testPath, 'Controller test should be placed in API-specific directory');
    }
}
