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
            app_path('Http/Controllers/PartnerApi'),
            app_path('Models/Services'),
            app_path('Services/PartnerApi'),
            app_path('Http/Requests'),
            app_path('Http/Requests/PartnerApi'),
            app_path('Resources'),
            app_path('Resources/PartnerApi'),
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
        $this->trackFile(app_path('Resources/CommentResource.php'));
        $this->trackFile(app_path('Resources/TagResource.php'));
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

    // ─── Dependency resolution ─────────────────────────────────────

    public function test_generates_dependency_data_dtos_for_child_relationships(): void
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

        // PostSchema has HasMany(Comment), BelongsToMany(Tag)
        // Dependency Data DTOs should be generated
        $this->assertFileExists($outputPath.'/src/Data/CommentData.php');
        $this->assertFileExists($outputPath.'/src/Data/TagData.php');
    }

    public function test_dependency_data_dtos_contain_valid_php(): void
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

        $commentContent = $this->files->get($outputPath.'/src/Data/CommentData.php');
        $this->assertStringContainsString('class CommentData', $commentContent);
        $this->assertStringContainsString('namespace Acme\\TestSdk\\Data;', $commentContent);
        $this->assertStringContainsString('fromArray', $commentContent);

        $tagContent = $this->files->get($outputPath.'/src/Data/TagData.php');
        $this->assertStringContainsString('class TagData', $tagContent);
        $this->assertStringContainsString('namespace Acme\\TestSdk\\Data;', $tagContent);
    }

    public function test_dependency_schemas_do_not_get_sdk_resources(): void
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

        // Dependency schemas should NOT have SDK Resources (no CRUD endpoints)
        $this->assertFileDoesNotExist($outputPath.'/src/Resources/CommentResource.php');
        $this->assertFileDoesNotExist($outputPath.'/src/Resources/TagResource.php');

        // Primary schema should still have its Resource
        $this->assertFileExists($outputPath.'/src/Resources/PostResource.php');
    }

    public function test_dependency_schemas_not_in_client_accessors(): void
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

        $clientContent = $this->files->get($outputPath.'/src/AcmeClient.php');

        // Primary schema should have a client accessor
        $this->assertStringContainsString('public function posts(): PostResource', $clientContent);

        // Dependency schemas should NOT have client accessors
        $this->assertStringNotContainsString('CommentResource', $clientContent);
        $this->assertStringNotContainsString('TagResource', $clientContent);
        $this->assertStringNotContainsString('comments()', $clientContent);
        $this->assertStringNotContainsString('tags()', $clientContent);
    }

    public function test_dependency_data_dtos_include_schema_columns(): void
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

        // CommentSchema columns: id, body, user_id, commentable_type, commentable_id, timestamps
        $commentContent = $this->files->get($outputPath.'/src/Data/CommentData.php');
        $this->assertStringContainsString('$id', $commentContent);
        $this->assertStringContainsString('$body', $commentContent);

        // TagSchema columns: id, name, slug, timestamps
        $tagContent = $this->files->get($outputPath.'/src/Data/TagData.php');
        $this->assertStringContainsString('$id', $tagContent);
        $this->assertStringContainsString('$name', $tagContent);
        $this->assertStringContainsString('$slug', $tagContent);
    }

    // ─── --api flag ────────────────────────────────────────────────

    public function test_api_option_uses_config_for_sdk_metadata(): void
    {
        config()->set('schema-craft.apis.partner', [
            'namespaces' => [
                'controller' => 'App\\Http\\Controllers\\Api',
                'resource' => 'App\\Resources',
            ],
            'sdk' => [
                'path' => 'packages/partner-sdk',
                'name' => 'acme/partner-sdk',
                'namespace' => 'Acme\\PartnerSdk',
                'client' => 'PartnerClient',
                'version' => '2.0.0',
            ],
        ]);

        $this->generateApiForPost();

        $outputPath = base_path('packages/partner-sdk');
        $this->trackDir($outputPath);

        $this->artisan('schema:generate-sdk', [
            '--schema-path' => [dirname(__DIR__).'/Fixtures/Schemas'],
            '--api' => 'partner',
        ])->assertSuccessful();

        // Check files are at the config-defined path
        $this->assertFileExists($outputPath.'/composer.json');
        $this->assertFileExists($outputPath.'/src/PartnerClient.php');

        // Check composer.json uses config values
        $composerContent = $this->files->get($outputPath.'/composer.json');
        $this->assertStringContainsString('"acme/partner-sdk"', $composerContent);
        $this->assertStringContainsString('"2.0.0"', $composerContent);
        $this->assertStringContainsString('Acme\\\\PartnerSdk\\\\', $composerContent);
    }

    public function test_cli_options_override_config_values(): void
    {
        config()->set('schema-craft.apis.partner', [
            'namespaces' => [
                'controller' => 'App\\Http\\Controllers\\Api',
                'resource' => 'App\\Resources',
            ],
            'sdk' => [
                'path' => 'packages/partner-sdk',
                'name' => 'acme/partner-sdk',
                'namespace' => 'Acme\\PartnerSdk',
                'client' => 'PartnerClient',
                'version' => '2.0.0',
            ],
        ]);

        $this->generateApiForPost();

        $outputPath = base_path('packages/override-sdk');
        $this->trackDir($outputPath);

        $this->artisan('schema:generate-sdk', [
            '--schema-path' => [dirname(__DIR__).'/Fixtures/Schemas'],
            '--api' => 'partner',
            '--path' => 'packages/override-sdk',
            '--name' => 'override/sdk',
            '--sdk-version' => '9.9.9',
        ])->assertSuccessful();

        // CLI --path should override config
        $this->assertFileExists($outputPath.'/composer.json');

        // CLI --name and --sdk-version should override config
        $composerContent = $this->files->get($outputPath.'/composer.json');
        $this->assertStringContainsString('"override/sdk"', $composerContent);
        $this->assertStringContainsString('"9.9.9"', $composerContent);
    }

    public function test_api_option_uses_custom_controller_path_for_discovery(): void
    {
        config()->set('schema-craft.apis.partner', [
            'namespaces' => [
                'controller' => 'App\\Http\\Controllers\\PartnerApi',
                'resource' => 'App\\Resources\\PartnerApi',
            ],
            'sdk' => [
                'path' => 'packages/partner-sdk',
                'name' => 'acme/partner-sdk',
                'namespace' => 'Acme\\PartnerSdk',
                'client' => 'PartnerClient',
            ],
        ]);

        // Generate API for partner namespace
        config()->set('schema-craft.apis.partner.namespaces.service', 'App\\Services\\PartnerApi');
        config()->set('schema-craft.apis.partner.namespaces.request', 'App\\Http\\Requests\\PartnerApi');

        $this->artisan('schema:generate', [
            'schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema',
            '--api' => 'partner',
        ])->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/PartnerApi/PostController.php'));
        $this->trackFile(app_path('Services/PartnerApi/PostService.php'));
        $this->trackFile(app_path('Http/Requests/PartnerApi/CreatePostRequest.php'));
        $this->trackFile(app_path('Http/Requests/PartnerApi/UpdatePostRequest.php'));
        $this->trackFile(app_path('Resources/PartnerApi/PostResource.php'));
        $this->trackFile(app_path('Resources/PartnerApi/CommentResource.php'));
        $this->trackFile(app_path('Resources/PartnerApi/TagResource.php'));

        $outputPath = base_path('packages/partner-sdk');
        $this->trackDir($outputPath);

        $this->artisan('schema:generate-sdk', [
            '--schema-path' => [dirname(__DIR__).'/Fixtures/Schemas'],
            '--api' => 'partner',
        ])->assertSuccessful();

        // Should discover PostController at the partner path
        $this->assertFileExists($outputPath.'/src/Data/PostData.php');
        $this->assertFileExists($outputPath.'/src/Resources/PostResource.php');
    }

    // ─── --sdk-version flag ──────────────────────────────────────────

    public function test_sdk_version_appears_in_composer_json(): void
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
            '--sdk-version' => '3.2.1',
        ])->assertSuccessful();

        $composerContent = $this->files->get($outputPath.'/composer.json');
        $this->assertStringContainsString('"version": "3.2.1"', $composerContent);
    }

    public function test_sdk_version_defaults_from_config(): void
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

        // Default version from config is '0.1.0'
        $composerContent = $this->files->get($outputPath.'/composer.json');
        $this->assertStringContainsString('"version": "0.1.0"', $composerContent);
    }

    // ─── --all flag ──────────────────────────────────────────────────

    public function test_all_flag_generates_sdks_for_all_apis(): void
    {
        config()->set('schema-craft.apis.alpha', [
            'namespaces' => [
                'controller' => 'App\\Http\\Controllers\\Api',
                'resource' => 'App\\Resources',
            ],
            'sdk' => [
                'path' => 'packages/alpha-sdk',
                'name' => 'acme/alpha-sdk',
                'namespace' => 'Acme\\AlphaSdk',
                'client' => 'AlphaClient',
                'version' => '1.0.0',
            ],
        ]);

        $this->generateApiForPost();

        $defaultOutputPath = base_path('packages/sdk');
        $alphaOutputPath = base_path('packages/alpha-sdk');
        $this->trackDir($defaultOutputPath);
        $this->trackDir($alphaOutputPath);

        $this->artisan('schema:generate-sdk', [
            '--schema-path' => [dirname(__DIR__).'/Fixtures/Schemas'],
            '--all' => true,
        ])->assertSuccessful();

        // Both SDKs should be generated
        $this->assertFileExists($defaultOutputPath.'/composer.json');
        $this->assertFileExists($alphaOutputPath.'/composer.json');

        // Verify they have different package names
        $defaultComposer = $this->files->get($defaultOutputPath.'/composer.json');
        $this->assertStringContainsString('"my-app/sdk"', $defaultComposer);

        $alphaComposer = $this->files->get($alphaOutputPath.'/composer.json');
        $this->assertStringContainsString('"acme/alpha-sdk"', $alphaComposer);
        $this->assertStringContainsString('"1.0.0"', $alphaComposer);
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
