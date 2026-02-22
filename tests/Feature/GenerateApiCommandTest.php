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

        // Clean up empty directories
        $dirs = [
            app_path('Http/Controllers/Api'),
            app_path('Models/Services'),
            app_path('Http/Requests'),
            app_path('Resources'),
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

        // Check controller was updated
        $controllerContent = $this->files->get(app_path('Http/Controllers/Api/PostController.php'));
        $this->assertStringContainsString("'archive'", $controllerContent);
        $this->assertStringContainsString('public function archive(', $controllerContent);

        // Check service was updated
        $serviceContent = $this->files->get(app_path('Models/Services/PostService.php'));
        $this->assertStringContainsString('public function archive()', $serviceContent);

        // Check request was created
        $this->assertFileExists(app_path('Http/Requests/ArchivePostRequest.php'));
        $requestContent = $this->files->get(app_path('Http/Requests/ArchivePostRequest.php'));
        $this->assertStringContainsString('class ArchivePostRequest extends FormRequest', $requestContent);
    }
}
