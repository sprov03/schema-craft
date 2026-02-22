<?php

namespace SchemaCraft\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Orchestra\Testbench\TestCase;
use SchemaCraft\SchemaCraftServiceProvider;

class GenerateSdkCommandTest extends TestCase
{
    private Filesystem $files;

    private array $createdFiles = [];

    private array $createdDirs = [];

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

        // Clean up API files
        $apiDirs = [
            app_path('Http/Controllers/Api'),
            app_path('Models/Services'),
            app_path('Http/Requests'),
            app_path('Resources'),
        ];

        foreach ($apiDirs as $dir) {
            if (is_dir($dir) && count($this->files->files($dir)) === 0) {
                $this->files->deleteDirectory($dir);
            }
        }

        // Clean up SDK output directory
        foreach ($this->createdDirs as $dir) {
            if (is_dir($dir)) {
                $this->files->deleteDirectory($dir);
            }
        }

        parent::tearDown();
    }

    private function trackFile(string $path): void
    {
        $this->createdFiles[] = $path;
    }

    private function trackDir(string $path): void
    {
        $this->createdDirs[] = $path;
    }

    private function generateApiForPost(): void
    {
        $this->artisan('schema:generate', [
            'schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema',
        ])->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/Api/PostController.php'));
        $this->trackFile(app_path('Models/Services/PostService.php'));
        $this->trackFile(app_path('Http/Requests/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PostResource.php'));
    }

    // ─── Basic generation ──────────────────────────────────────────

    public function test_fails_when_no_schemas_found(): void
    {
        $this->artisan('schema:generate-sdk', [
            '--schema-path' => ['/nonexistent/path'],
        ])->assertFailed();
    }

    public function test_fails_when_no_api_controllers_exist(): void
    {
        $this->artisan('schema:generate-sdk', [
            '--schema-path' => [dirname(__DIR__).'/Fixtures/Schemas'],
        ])->assertFailed();
    }

    public function test_generates_sdk_for_schema_with_api(): void
    {
        $this->generateApiForPost();

        $outputPath = base_path('packages/test-sdk');
        $this->trackDir($outputPath);

        $this->artisan('schema:generate-sdk', [
            '--schema-path' => [dirname(__DIR__).'/Fixtures/Schemas'],
            '--path' => 'packages/test-sdk',
            '--name' => 'acme/test-sdk',
            '--namespace' => 'Acme\\TestSdk',
            '--client' => 'AcmeClient',
        ])->assertSuccessful();

        // Check core files exist
        $this->assertFileExists($outputPath.'/composer.json');
        $this->assertFileExists($outputPath.'/src/SdkConnector.php');
        $this->assertFileExists($outputPath.'/src/AcmeClient.php');
        $this->assertFileExists($outputPath.'/src/Data/PostData.php');
        $this->assertFileExists($outputPath.'/src/Resources/PostResource.php');
    }

    public function test_composer_json_has_correct_metadata(): void
    {
        $this->generateApiForPost();

        $outputPath = base_path('packages/test-sdk');
        $this->trackDir($outputPath);

        $this->artisan('schema:generate-sdk', [
            '--schema-path' => [dirname(__DIR__).'/Fixtures/Schemas'],
            '--path' => 'packages/test-sdk',
            '--name' => 'acme/test-sdk',
            '--namespace' => 'Acme\\TestSdk',
            '--client' => 'AcmeClient',
        ])->assertSuccessful();

        $content = $this->files->get($outputPath.'/composer.json');
        $this->assertStringContainsString('"acme/test-sdk"', $content);
        $this->assertStringContainsString('guzzlehttp/guzzle', $content);
        $this->assertStringContainsString('Acme\\\\TestSdk\\\\', $content);
    }

    public function test_generated_data_class_has_schema_columns(): void
    {
        $this->generateApiForPost();

        $outputPath = base_path('packages/test-sdk');
        $this->trackDir($outputPath);

        $this->artisan('schema:generate-sdk', [
            '--schema-path' => [dirname(__DIR__).'/Fixtures/Schemas'],
            '--path' => 'packages/test-sdk',
            '--name' => 'acme/test-sdk',
            '--namespace' => 'Acme\\TestSdk',
            '--client' => 'AcmeClient',
        ])->assertSuccessful();

        $content = $this->files->get($outputPath.'/src/Data/PostData.php');

        $this->assertStringContainsString('class PostData', $content);
        $this->assertStringContainsString('$title', $content);
        $this->assertStringContainsString('$slug', $content);
        $this->assertStringContainsString('$body', $content);
        $this->assertStringContainsString('fromArray', $content);
    }

    public function test_generated_resource_has_crud_methods(): void
    {
        $this->generateApiForPost();

        $outputPath = base_path('packages/test-sdk');
        $this->trackDir($outputPath);

        $this->artisan('schema:generate-sdk', [
            '--schema-path' => [dirname(__DIR__).'/Fixtures/Schemas'],
            '--path' => 'packages/test-sdk',
            '--name' => 'acme/test-sdk',
            '--namespace' => 'Acme\\TestSdk',
            '--client' => 'AcmeClient',
        ])->assertSuccessful();

        $content = $this->files->get($outputPath.'/src/Resources/PostResource.php');

        $this->assertStringContainsString('public function list()', $content);
        $this->assertStringContainsString('public function get(', $content);
        $this->assertStringContainsString('public function create(', $content);
        $this->assertStringContainsString('public function update(', $content);
        $this->assertStringContainsString('public function delete(', $content);
    }

    public function test_generated_client_has_resource_accessors(): void
    {
        $this->generateApiForPost();

        $outputPath = base_path('packages/test-sdk');
        $this->trackDir($outputPath);

        $this->artisan('schema:generate-sdk', [
            '--schema-path' => [dirname(__DIR__).'/Fixtures/Schemas'],
            '--path' => 'packages/test-sdk',
            '--name' => 'acme/test-sdk',
            '--namespace' => 'Acme\\TestSdk',
            '--client' => 'AcmeClient',
        ])->assertSuccessful();

        $content = $this->files->get($outputPath.'/src/AcmeClient.php');

        $this->assertStringContainsString('class AcmeClient', $content);
        $this->assertStringContainsString('public function posts(): PostResource', $content);
    }

    // ─── --force flag ─────────────────────────────────────────────

    public function test_does_not_overwrite_without_force(): void
    {
        $this->generateApiForPost();

        $outputPath = base_path('packages/test-sdk');
        $this->trackDir($outputPath);

        // First generation
        $this->artisan('schema:generate-sdk', [
            '--schema-path' => [dirname(__DIR__).'/Fixtures/Schemas'],
            '--path' => 'packages/test-sdk',
            '--name' => 'acme/test-sdk',
            '--namespace' => 'Acme\\TestSdk',
            '--client' => 'AcmeClient',
        ])->assertSuccessful();

        // Write a marker
        $this->files->put($outputPath.'/src/AcmeClient.php', '<?php // marker');

        // Second generation without --force
        $this->artisan('schema:generate-sdk', [
            '--schema-path' => [dirname(__DIR__).'/Fixtures/Schemas'],
            '--path' => 'packages/test-sdk',
            '--name' => 'acme/test-sdk',
            '--namespace' => 'Acme\\TestSdk',
            '--client' => 'AcmeClient',
        ])->assertSuccessful();

        // File should not have been overwritten
        $content = $this->files->get($outputPath.'/src/AcmeClient.php');
        $this->assertStringContainsString('// marker', $content);
    }

    public function test_overwrites_with_force(): void
    {
        $this->generateApiForPost();

        $outputPath = base_path('packages/test-sdk');
        $this->trackDir($outputPath);

        // First generation
        $this->artisan('schema:generate-sdk', [
            '--schema-path' => [dirname(__DIR__).'/Fixtures/Schemas'],
            '--path' => 'packages/test-sdk',
            '--name' => 'acme/test-sdk',
            '--namespace' => 'Acme\\TestSdk',
            '--client' => 'AcmeClient',
        ])->assertSuccessful();

        // Write a marker
        $this->files->put($outputPath.'/src/AcmeClient.php', '<?php // marker');

        // Second generation with --force
        $this->artisan('schema:generate-sdk', [
            '--schema-path' => [dirname(__DIR__).'/Fixtures/Schemas'],
            '--path' => 'packages/test-sdk',
            '--name' => 'acme/test-sdk',
            '--namespace' => 'Acme\\TestSdk',
            '--client' => 'AcmeClient',
            '--force' => true,
        ])->assertSuccessful();

        // File should have been overwritten
        $content = $this->files->get($outputPath.'/src/AcmeClient.php');
        $this->assertStringNotContainsString('// marker', $content);
        $this->assertStringContainsString('class AcmeClient', $content);
    }

    // ─── Custom actions ───────────────────────────────────────────

    public function test_includes_custom_actions_in_sdk_resource(): void
    {
        $this->generateApiForPost();

        // Add a custom action to the controller
        $this->artisan('schema:generate', [
            'schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema',
            '--action' => 'archive',
        ])->assertSuccessful();

        $this->trackFile(app_path('Http/Requests/ArchivePostRequest.php'));

        $outputPath = base_path('packages/test-sdk');
        $this->trackDir($outputPath);

        $this->artisan('schema:generate-sdk', [
            '--schema-path' => [dirname(__DIR__).'/Fixtures/Schemas'],
            '--path' => 'packages/test-sdk',
            '--name' => 'acme/test-sdk',
            '--namespace' => 'Acme\\TestSdk',
            '--client' => 'AcmeClient',
        ])->assertSuccessful();

        $content = $this->files->get($outputPath.'/src/Resources/PostResource.php');
        $this->assertStringContainsString('public function archive(', $content);
    }
}
