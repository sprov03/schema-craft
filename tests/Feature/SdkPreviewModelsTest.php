<?php

namespace SchemaCraft\Tests\Feature;

use Orchestra\Testbench\TestCase;
use SchemaCraft\SchemaCraftServiceProvider;

/**
 * The visualizer SDK export must ship models alongside the API client: when previewing/generating an
 * SDK, the read-only models are appended to the same file set so the diff shows them and the write
 * includes them. This exercises the GUI path (sdk/preview), which is the workflow actually used.
 */
class SdkPreviewModelsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [SchemaCraftServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // buildSdkFiles discovers via ConfigResolver::schemaDirectories(), which reads db_connections
        // (namespaceToPath resolves the fixtures namespace via Composer PSR-4).
        $app['config']->set('schema-craft.db_connections', [
            'default' => [
                'connection' => 'testing',
                'namespaces' => [
                    'schema' => 'SchemaCraft\\Tests\\Fixtures\\Schemas',
                    'model' => 'SchemaCraft\\Tests\\Fixtures\\Models',
                ],
            ],
        ]);
        $app['config']->set('schema-craft.apis.default.namespaces.controller', 'SchemaCraft\\Tests\\Fixtures\\Api');
        $app['config']->set('schema-craft.apis.default.namespaces.schema', 'SchemaCraft\\Tests\\Fixtures\\Schemas');
        $app['config']->set('schema-craft.apis.default.namespaces.model', 'SchemaCraft\\Tests\\Fixtures\\Models');
        // Keep the set narrow + deterministic; Post has a registered controller for the SDK side.
        $app['config']->set('schema-craft.apis.default.schemas', ['PostSchema']);
        $app['config']->set('schema-craft.apis.default.sdk.path', 'packages/test-preview-sdk');
        $app['config']->set('schema-craft.apis.default.sdk.namespace', 'Acme\\Sdk');
    }

    protected function defineRoutes($router): void
    {
        $router->prefix('api')->middleware('api')->group(function () {
            \SchemaCraft\Tests\Fixtures\Api\PostController::apiRoutes();
        });
    }

    public function test_sdk_preview_includes_read_only_model_files(): void
    {
        $response = $this->postJson('/_schema-craft/api/sdk/preview', [
            'api' => 'default',
            'force' => true,
        ]);

        $response->assertSuccessful();

        $paths = array_map(fn ($f) => $f['path'], $response->json('files'));

        // The API client is still produced...
        $this->assertTrue(
            (bool) array_filter($paths, fn ($p) => str_ends_with($p, '/src/Resources/PostResource.php')),
            'Expected the SDK resource to be present. Got: '.implode(', ', $paths),
        );

        // ...and the models now ride along in the same package.
        $this->assertTrue(
            (bool) array_filter($paths, fn ($p) => str_ends_with($p, '/src/Models/ReadOnlyModel.php')),
            'Expected the read-only base model. Got: '.implode(', ', $paths),
        );
        $this->assertTrue(
            (bool) array_filter($paths, fn ($p) => str_ends_with($p, '/src/Models/Post.php')),
            'Expected the Post model. Got: '.implode(', ', $paths),
        );
    }

    public function test_preview_warns_on_the_generation_page_when_schemas_filter_is_set(): void
    {
        // This API pins schemas => ['PostSchema'] (see defineEnvironment), so the generation-page
        // preview must surface the model-export warning about a possibly-incomplete relation set.
        $response = $this->postJson('/_schema-craft/api/sdk/preview', [
            'api' => 'default',
            'force' => true,
        ]);

        $response->assertSuccessful();

        $messages = array_column($response->json('warnings') ?? [], 'message');
        $this->assertNotEmpty(
            array_filter($messages, fn ($m) => str_contains($m, "'schemas' filter is set")),
            'Expected a schemas-filter warning in the preview response. Got: '.implode(' | ', $messages),
        );
    }
}
