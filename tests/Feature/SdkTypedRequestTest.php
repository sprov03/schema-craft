<?php

namespace SchemaCraft\Tests\Feature;

use Orchestra\Testbench\TestCase;
use SchemaCraft\Config\ConfigResolver;
use SchemaCraft\Generator\Sdk\EndpointEnricher;
use SchemaCraft\Generator\Sdk\SdkContextBuilder;
use SchemaCraft\Generator\Sdk\SdkGenerator;
use SchemaCraft\Generator\StubResolver;
use SchemaCraft\Migration\SchemaDiscovery;
use SchemaCraft\SchemaCraftServiceProvider;
use SchemaCraft\Tests\Fixtures\Actions\Post\UpdatePostWithDataSchemaAction;

/**
 * Steps 6–8: the SDK generates typed request DTOs and the Resource method takes one typed
 * $request, for both controller Requests (reflected) and Actions (params projected as shapes:
 * belongsTo → int, hasMany → {Item}Data[]). Verified end-to-end through the real build pipeline.
 */
class SdkTypedRequestTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [SchemaCraftServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('schema-craft.apis.default.namespaces.controller', 'SchemaCraft\\Tests\\Fixtures\\Api');
        $app['config']->set('schema-craft.apis.default.namespaces.schema', 'SchemaCraft\\Tests\\Fixtures\\Schemas');
    }

    protected function defineRoutes($router): void
    {
        $router->prefix('api')->middleware('api')->group(function () {
            \SchemaCraft\Tests\Fixtures\Api\PersistedItemController::apiRoutes();
        });
    }

    /** @return array<string, string> path => content */
    private function generatedFiles(): array
    {
        $apiConfig = ConfigResolver::resolve('default');
        $schemaClasses = (new SchemaDiscovery)->discover([dirname(__DIR__).'/Fixtures/Schemas']);
        $buildResult = (new SdkContextBuilder)->build($apiConfig, $schemaClasses);

        $files = (new SdkGenerator)->generate(
            schemas: $buildResult->schemas,
            packageName: 'acme/test-sdk',
            namespace: 'Acme\\TestSdk',
            clientClassName: 'AcmeClient',
            stubsPath: StubResolver::basePath(),
            version: '1.0.0',
        );

        $map = [];
        foreach ($files as $file) {
            $map[$file->path] = $file->content;
        }

        return $map;
    }

    // ─── Step 6: typed request DTO generated for a controller Request ──────

    public function test_controller_request_generates_typed_request_dto(): void
    {
        $files = $this->generatedFiles();

        $this->assertArrayHasKey('src/Data/SearchPersistedItemsRequestData.php', $files);
        $dto = $files['src/Data/SearchPersistedItemsRequestData.php'];

        $this->assertStringContainsString('class SearchPersistedItemsRequestData', $dto);
        $this->assertStringContainsString('public $name;', $dto);
        $this->assertStringContainsString('public $is_active;', $dto);
        $this->assertStringContainsString('public $max_price;', $dto);
        // toArray() exists so the Resource method can post $request->toArray().
        $this->assertStringContainsString('public function toArray()', $dto);
        $this->assertStringContainsString('fromArray', $dto);
    }

    // ─── Step 7: the Resource method takes the typed $request ──────────────

    public function test_resource_method_takes_typed_request_param(): void
    {
        $files = $this->generatedFiles();

        $resource = $files['src/Resources/PersistedItemResource.php'];

        // imported and used as a single typed param — not flat positional args.
        $this->assertStringContainsString('use Acme\\TestSdk\\Data\\SearchPersistedItemsRequestData;', $resource);
        $this->assertStringContainsString('SearchPersistedItemsRequestData $request', $resource);
        $this->assertStringContainsString('$request->toArray()', $resource);
    }

    // ─── Step 8: an Action projects relationships as data shapes ───────────

    public function test_action_request_dto_projects_relationships_as_shapes(): void
    {
        $endpoint = (new EndpointEnricher)->enrich([
            'source' => 'action',
            'actionClass' => UpdatePostWithDataSchemaAction::class,
            'method' => 'put',
            'path' => 'posts/{post}/update-with-schema',
        ]);

        $this->assertSame('UpdatePostWithDataSchemaActionData', $endpoint['requestDtoName']);

        $byName = [];
        foreach ($endpoint['requestDtoFields'] as $f) {
            $byName[$f['name']] = $f['type'];
        }

        // scalar stays scalar; belongsTo → its FK id as int; hasMany → a collection of the item shape.
        $this->assertSame('string', $byName['title']);
        $this->assertSame('int', $byName['author_id']);
        $this->assertStringEndsWith('Data[]', $byName['comments']);
        $this->assertStringEndsWith('Data[]', $byName['tags']);
    }
}
