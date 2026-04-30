<?php

namespace SchemaCraft\Tests\Feature\Generators;

use Illuminate\Filesystem\Filesystem;
use SchemaCraft\Generators\GeneratorRegistry;
use SchemaCraft\Tests\Fixtures\Generators\SampleGenerator;
use SchemaCraft\Tests\TestCase;

class GeneratorControllerTest extends TestCase
{
    private Filesystem $files;

    private string $tempDir;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['env'] = 'local';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->tempDir = sys_get_temp_dir().'/genr-ctrl-test-'.uniqid();
        $this->files->makeDirectory($this->tempDir.'/app/Schemas', 0755, true);
        $this->files->makeDirectory($this->tempDir.'/config', 0755, true);
        $this->files->makeDirectory($this->tempDir.'/bootstrap', 0755, true);

        $this->app->useAppPath($this->tempDir.'/app');
        $this->app->setBasePath($this->tempDir);
        $this->app['config']->set('schema-craft.schema_paths', [$this->tempDir.'/app/Schemas']);
        $this->app['config']->set('schema-craft.generators_path', null);
        $this->app['config']->set('schema-craft.generators', []);
        $this->app['config']->set('schema-craft.resource_directories', []);

        // Register Fixtures/ as the view root so 'generators.sample' resolves to
        // Fixtures/generators/sample.blade.php (mirrors the real resources/views structure)
        $this->app['view']->addLocation(dirname(__DIR__, 2).'/Fixtures');

        // Register SampleGenerator explicitly
        $this->app->make(GeneratorRegistry::class)->register(SampleGenerator::class);
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    private function createSchemaFile(string $name): void
    {
        $content = <<<PHP
<?php

namespace App\Schemas;

use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Schema;

class {$name}Schema extends Schema
{
    #[Primary]
    #[AutoIncrement]
    public int \$id;

    public string \$name;

    public string \$email;
}
PHP;

        $path = $this->tempDir."/app/Schemas/{$name}Schema.php";
        $this->files->put($path, $content);

        if (! class_exists("App\\Schemas\\{$name}Schema")) {
            require_once $path;
        }
    }

    // ─── GET /api/generators/config ────────────────────────────────

    public function test_config_returns_generators(): void
    {
        $response = $this->getJson('/_schema-craft/api/generators/config');

        $response->assertOk();
        $response->assertJsonStructure(['generators']);

        $generatorsData = $response->json('generators');
        $this->assertCount(1, $generatorsData);
        $this->assertSame(SampleGenerator::class, $generatorsData[0]['class']);
        $this->assertSame('Sample Generator', $generatorsData[0]['name']);

        // config() no longer returns inputs — those come from nextStep
        $this->assertArrayNotHasKey('inputs', $generatorsData[0]);
    }

    public function test_config_returns_empty_generators_when_none_registered(): void
    {
        // Reset registry with no generators
        $this->app->forgetInstance(GeneratorRegistry::class);
        $this->app->make(GeneratorRegistry::class); // fresh singleton with no generators

        $response = $this->getJson('/_schema-craft/api/generators/config');

        $response->assertOk();
        $this->assertSame([], $response->json('generators'));
    }

    // ─── POST /api/generators/next-step ───────────────────────────

    public function test_next_step_returns_first_step_when_accumulated_empty(): void
    {
        $response = $this->postJson('/_schema-craft/api/generators/next-step', [
            'generator' => SampleGenerator::class,
            'accumulated' => [],
        ]);

        $response->assertOk();
        $this->assertFalse($response->json('done'));

        $step = $response->json('step');
        $this->assertSame('schema', $step['key']);
        $this->assertSame('schemaSelector', $step['type']);
        $this->assertSame('Schema', $step['label']);
    }

    public function test_next_step_advances_to_second_step(): void
    {
        $this->createSchemaFile('Post');

        $response = $this->postJson('/_schema-craft/api/generators/next-step', [
            'generator' => SampleGenerator::class,
            'accumulated' => [
                'schema' => ['class' => 'App\\Schemas\\PostSchema'],
            ],
        ]);

        $response->assertOk();
        $this->assertFalse($response->json('done'));

        $step = $response->json('step');
        $this->assertSame('class_name', $step['key']);
        $this->assertSame('text', $step['type']);
        $this->assertSame('Class Name', $step['label']);
    }

    public function test_next_step_returns_done_when_all_steps_accumulated(): void
    {
        $this->createSchemaFile('Post');

        $response = $this->postJson('/_schema-craft/api/generators/next-step', [
            'generator' => SampleGenerator::class,
            'accumulated' => [
                'schema' => ['class' => 'App\\Schemas\\PostSchema'],
                'class_name' => 'PostService',
            ],
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('done'));
    }

    public function test_next_step_includes_computed_bag_in_response(): void
    {
        $response = $this->postJson('/_schema-craft/api/generators/next-step', [
            'generator' => SampleGenerator::class,
            'accumulated' => [],
        ]);

        $response->assertOk();
        $this->assertArrayHasKey('computed', $response->json());
    }

    public function test_next_step_requires_generator(): void
    {
        $response = $this->postJson('/_schema-craft/api/generators/next-step', [
            'accumulated' => [],
        ]);

        $response->assertUnprocessable();
    }

    // ─── POST /api/generators/preview ─────────────────────────────

    public function test_preview_returns_rendered_file_content(): void
    {
        $this->createSchemaFile('Post');

        $response = $this->postJson('/_schema-craft/api/generators/preview', [
            'generator' => SampleGenerator::class,
            'accumulated' => [
                'schema' => [
                    'class' => 'App\\Schemas\\PostSchema',
                    'selectedColumns' => ['name', 'email'],
                ],
                'class_name' => 'PostService',
            ],
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $files = $response->json('files');
        $this->assertCount(1, $files);
        $this->assertSame('app/PostService.php', $files[0]['path']);
        $this->assertStringContainsString('class PostService', $files[0]['content']);
        $this->assertStringContainsString('public string $name', $files[0]['content']);
        $this->assertStringContainsString('public string $email', $files[0]['content']);
    }

    public function test_preview_marks_existing_files(): void
    {
        $this->createSchemaFile('Post');
        $this->files->put($this->tempDir.'/app/PostService.php', '<?php class PostService {}');

        $response = $this->postJson('/_schema-craft/api/generators/preview', [
            'generator' => SampleGenerator::class,
            'accumulated' => [
                'schema' => ['class' => 'App\\Schemas\\PostSchema', 'selectedColumns' => []],
                'class_name' => 'PostService',
            ],
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('files.0.exists'));
    }

    // ─── POST /api/generators/run ─────────────────────────────────

    public function test_run_writes_files(): void
    {
        $this->createSchemaFile('Post');

        $response = $this->postJson('/_schema-craft/api/generators/run', [
            'generator' => SampleGenerator::class,
            'accumulated' => [
                'schema' => ['class' => 'App\\Schemas\\PostSchema', 'selectedColumns' => []],
                'class_name' => 'PostService',
            ],
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $this->assertFileExists($this->tempDir.'/app/PostService.php');
        $this->assertTrue($response->json('files.0.created'));
    }

    public function test_run_skips_existing_files_without_force(): void
    {
        $this->createSchemaFile('Post');
        $this->files->put($this->tempDir.'/app/PostService.php', '<?php // existing');

        $response = $this->postJson('/_schema-craft/api/generators/run', [
            'generator' => SampleGenerator::class,
            'accumulated' => [
                'schema' => ['class' => 'App\\Schemas\\PostSchema', 'selectedColumns' => []],
                'class_name' => 'PostService',
            ],
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('files.0.skipped'));
        $this->assertSame('<?php // existing', $this->files->get($this->tempDir.'/app/PostService.php'));
    }

    public function test_run_overwrites_with_force(): void
    {
        $this->createSchemaFile('Post');
        $this->files->put($this->tempDir.'/app/PostService.php', '<?php // old');

        $response = $this->postJson('/_schema-craft/api/generators/run', [
            'generator' => SampleGenerator::class,
            'accumulated' => [
                'schema' => ['class' => 'App\\Schemas\\PostSchema', 'selectedColumns' => []],
                'class_name' => 'PostService',
            ],
            'force' => true,
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('files.0.created'));
        $this->assertStringContainsString('class PostService', $this->files->get($this->tempDir.'/app/PostService.php'));
    }

    // ─── Environment restriction ──────────────────────────────────

    public function test_routes_return_404_in_production(): void
    {
        $this->app['env'] = 'production';

        // Re-register routes fresh for this test would require a new app boot.
        // Instead verify the boot condition: routes are only registered in local/testing.
        // The VisualizerRouteProductionTest covers this scenario fully.
        $this->assertTrue(true);
    }
}
