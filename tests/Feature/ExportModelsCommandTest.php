<?php

namespace SchemaCraft\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Orchestra\Testbench\TestCase;
use SchemaCraft\SchemaCraftServiceProvider;

/**
 * schema:export-models writes read-only Eloquent models INTO the SDK package directory, selected by
 * connection (all schemas, not route-filtered like the SDK) and therefore independent of the API.
 */
class ExportModelsCommandTest extends TestCase
{
    private Filesystem $files;

    private string $outputDir;

    protected function getPackageProviders($app): array
    {
        return [SchemaCraftServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Point selection at the fixture schemas; limit to a known-good set so scanning is deterministic.
        $app['config']->set('schema-craft.apis.default.namespaces.schema', 'SchemaCraft\\Tests\\Fixtures\\Schemas');
        $app['config']->set('schema-craft.apis.default.namespaces.model', 'SchemaCraft\\Tests\\Fixtures\\Models');
        $app['config']->set('schema-craft.apis.default.schemas', ['PostSchema', 'CommentSchema', 'TagSchema', 'UserSchema']);
        $app['config']->set('schema-craft.apis.default.sdk.path', 'packages/test-export-models');
        $app['config']->set('schema-craft.apis.default.sdk.namespace', 'Acme\\Sdk');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->files = new Filesystem;
        $this->outputDir = base_path('packages/test-export-models');
    }

    protected function tearDown(): void
    {
        if ($this->files->isDirectory($this->outputDir)) {
            $this->files->deleteDirectory($this->outputDir);
        }
        parent::tearDown();
    }

    public function test_exports_read_only_models_into_the_sdk_package(): void
    {
        $this->artisan('schema:export-models', [
            '--schema-path' => [dirname(__DIR__).'/Fixtures/Schemas'],
            '--force' => true,
        ])->assertSuccessful();

        // The shared base class ships with the package.
        $this->assertFileExists($this->outputDir.'/src/Models/ReadOnlyModel.php');

        // A selected schema produced a flat, self-contained, read-only model.
        $postPath = $this->outputDir.'/src/Models/Post.php';
        $this->assertFileExists($postPath);

        $content = $this->files->get($postPath);
        $this->assertStringContainsString('namespace Acme\\Sdk\\Models;', $content);
        $this->assertStringContainsString('class Post extends ReadOnlyModel', $content);
        $this->assertStringContainsString("protected \$table = ", $content);

        // Schemas excluded by the filter are not exported.
        $this->assertFileDoesNotExist($this->outputDir.'/src/Models/Catalog.php');
    }
}
