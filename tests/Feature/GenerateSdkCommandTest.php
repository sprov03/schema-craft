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

    /**
     * Point the SDK config at the hand-written fixture namespaces so
     * RuntimeRouteScanner can match fixture routes to fixture schemas.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set(
            'schema-craft.apis.default.namespaces.controller',
            'SchemaCraft\\Tests\\Fixtures\\Api',
        );
        $app['config']->set(
            'schema-craft.apis.default.namespaces.schema',
            'SchemaCraft\\Tests\\Fixtures\\Schemas',
        );
    }

    /**
     * Register the fixture API's routes so they're discoverable at runtime.
     * Avoids the previous pattern of generating + falling back through the
     * controller-file scanner — that fallback is gone (SdkGenerationException).
     */
    protected function defineRoutes($router): void
    {
        $router->prefix('api')->middleware('api')->group(function () {
            \SchemaCraft\Tests\Fixtures\Api\PostController::apiRoutes();
        });
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

    /**
     * No-op — the fixture API is set up automatically via defineEnvironment()
     * and defineRoutes(). Kept as an explicit call site so tests read clearly:
     * "this test exercises the SDK gen against the fixture API."
     */
    private function generateApiForPost(): void
    {
        // intentionally empty
    }

    // ─── Basic generation ──────────────────────────────────────────

    public function test_fails_when_no_schemas_found(): void
    {
        $this->artisan('schema:generate-sdk', [
            '--schema-path' => ['/nonexistent/path'],
        ])->assertFailed();
    }

    // Note: the old test_fails_when_no_routes_registered case was deleted —
    // with fixture-based setup, routes are always registered. The no-routes
    // failure mode is now exercised by the SdkGenerationException path that
    // fires when a controller file exists without matching routes (covered
    // by Fix 1 unit tests for SdkGenerationException::missingRoutes).

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

        // PostData is now RESOURCE-driven (PostResource), so its fields are the resource's typed
        // props served verbatim in snake_case — not the raw schema columns.
        $this->assertStringContainsString('class PostData', $content);
        $this->assertStringContainsString('public $title;', $content);
        $this->assertStringContainsString('public $slug;', $content);
        $this->assertStringContainsString('public $body;', $content);
        // snake_case keys preserved verbatim (no casing translation).
        $this->assertStringContainsString('public $view_count;', $content);
        $this->assertStringContainsString('public $is_featured;', $content);
        $this->assertStringContainsString('public $published_at;', $content);
        $this->assertStringContainsString('fromArray', $content);

        // The structured ActionResultData DTO (referenced by delete/archive) is generated too.
        $this->assertFileExists($outputPath.'/src/Data/ActionResultData.php');
        $actionResult = $this->files->get($outputPath.'/src/Data/ActionResultData.php');
        $this->assertStringContainsString('class ActionResultData', $actionResult);
        $this->assertStringContainsString('public $success;', $actionResult);
        $this->assertStringContainsString('public $message;', $actionResult);
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

        // SDK methods mirror the controller method names verbatim (no mapping —
        // 'getCollection' on the controller stays 'getCollection' on the SDK).
        $this->assertStringContainsString('public function getCollection(', $content);
        $this->assertStringContainsString('public function get(', $content);
        $this->assertStringContainsString('public function create(', $content);
        $this->assertStringContainsString('public function update(', $content);
        $this->assertStringContainsString('public function delete(', $content);

        // Every Post endpoint is now documented (#[ApiResponse]) so methods carry typed returns:
        //   getCollection -> Collection<int, PostData>, the rest of the reads/writes -> PostData,
        //   delete/archive -> the structured ActionResultData (no longer void).
        $this->assertStringContainsString('     * @return Collection<int, PostData>', $content);
        $this->assertStringContainsString("return collect(\$response['data'])->map(function (array \$item) {", $content);
        $this->assertStringContainsString('return PostData::fromArray($item);', $content);
        $this->assertStringContainsString("return PostData::fromArray(\$response['data']);", $content);
        $this->assertStringContainsString('     * @return ActionResultData', $content);
        $this->assertStringContainsString("return ActionResultData::fromArray(\$response['data']);", $content);

        // archive is a documented custom action returning the structured result.
        $this->assertStringContainsString('public function archive(', $content);
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
        $this->assertStringContainsString('public function posts()', $content);
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
        $this->assertStringContainsString('public function posts()', $clientContent);

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
        // Point the partner API at the fixture namespaces so it discovers the
        // same fixture controller/schema the default api uses. The test is
        // about config-driven SDK metadata, not about API surface isolation.
        config()->set('schema-craft.apis.partner', [
            'namespaces' => [
                'controller' => 'SchemaCraft\\Tests\\Fixtures\\Api',
                'schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas',
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
                'controller' => 'SchemaCraft\\Tests\\Fixtures\\Api',
                'schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas',
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

    public function test_api_option_throws_when_controller_has_no_routes(): void
    {
        // This test exercises Fix 1's fail-loud behavior: when a controller
        // file exists for the partner API but no routes are registered for
        // its namespace, SdkGenerationException::missingRoutes fires rather
        // than the old silent fallback that produced an SDK with `httpMethod: put`
        // hardcoded for every action.
        //
        // Scoped to Category specifically: the fixture's defineRoutes() registers the
        // documented PostController, whose #[ApiResponse]-tagged routes now resolve to
        // PostSchema and would otherwise "leak" into any unfiltered partner build. No
        // registered route references CategorySchema, so generating its controller with
        // no routes is the clean way to hit the missing-routes path.
        config()->set('schema-craft.apis.partner', [
            'namespaces' => [
                'controller' => 'App\\Http\\Controllers\\PartnerApi',
                'schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas',
                'resource' => 'App\\Resources\\PartnerApi',
            ],
            'schemas' => ['CategorySchema'],
            'sdk' => [
                'path' => 'packages/partner-sdk',
                'name' => 'acme/partner-sdk',
                'namespace' => 'Acme\\PartnerSdk',
                'client' => 'PartnerClient',
            ],
        ]);
        config()->set('schema-craft.apis.partner.namespaces.service', 'App\\Services\\PartnerApi');
        config()->set('schema-craft.apis.partner.namespaces.request', 'App\\Http\\Requests\\PartnerApi');

        // Generate the partner API stack so a controller file exists on disk —
        // but the fixture's defineRoutes() only registers routes for the default
        // (fixture) namespace, not the partner namespace.
        $this->artisan('schema:generate', [
            'schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas\\CategorySchema',
            '--api' => 'partner',
        ])->assertSuccessful();

        $this->trackFile(app_path('Http/Controllers/PartnerApi/CategoryController.php'));
        $this->trackFile(app_path('Services/PartnerApi/CategoryService.php'));
        $this->trackFile(app_path('Http/Requests/PartnerApi/CreateCategoryRequest.php'));
        $this->trackFile(app_path('Http/Requests/PartnerApi/UpdateCategoryRequest.php'));
        $this->trackFile(app_path('Resources/PartnerApi/CategoryResource.php'));

        $this->expectException(\SchemaCraft\Exceptions\SdkGenerationException::class);
        $this->expectExceptionMessageMatches('/no routes are registered for this schema/');

        $this->artisan('schema:generate-sdk', [
            '--schema-path' => [dirname(__DIR__).'/Fixtures/Schemas'],
            '--api' => 'partner',
        ]);
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
                'controller' => 'SchemaCraft\\Tests\\Fixtures\\Api',
                'schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas',
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
        // The fixture controller already declares an `archive` custom action
        // (POST posts/{post}/archive) — no schema:generate call needed.
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
        $this->assertStringContainsString('public function archive(', $content);
    }
}
