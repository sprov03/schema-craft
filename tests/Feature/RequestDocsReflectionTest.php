<?php

namespace SchemaCraft\Tests\Feature;

use Orchestra\Testbench\TestCase;
use SchemaCraft\Config\ConfigResolver;
use SchemaCraft\Generator\Sdk\SdkContextBuilder;
use SchemaCraft\Migration\SchemaDiscovery;
use SchemaCraft\SchemaCraftServiceProvider;

/**
 * Proves a type-hinted SchemaCraft\Request is reflected into the API docs payload the
 * same way #[ApiResponse] reflects a Resource — typed request fields + rules, end to end
 * through the real RuntimeRouteScanner → EndpointEnricher → SdkBuildResult pipeline.
 */
class RequestDocsReflectionTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [SchemaCraftServiceProvider::class];
    }

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

    protected function defineRoutes($router): void
    {
        $router->prefix('api')->middleware('api')->group(function () {
            \SchemaCraft\Tests\Fixtures\Api\PersistedItemController::apiRoutes();
        });
    }

    private function searchEndpoint(): array
    {
        $apiConfig = ConfigResolver::resolve('default');
        $schemaClasses = (new SchemaDiscovery)->discover([dirname(__DIR__).'/Fixtures/Schemas']);

        $payload = (new SdkContextBuilder)->build($apiConfig, $schemaClasses)
            ->toApiDocsJson($apiConfig->name, $apiConfig->routeFile, $apiConfig->routePrefix);

        foreach ($payload['schemas'] as $schema) {
            foreach ($schema['endpoints'] ?? [] as $endpoint) {
                if (str_contains($endpoint['path'] ?? '', 'persisted-items/search')) {
                    return $endpoint;
                }
            }
        }

        $this->fail('search endpoint not found in API docs payload');
    }

    public function test_request_backed_endpoint_exposes_typed_request_fields(): void
    {
        $endpoint = $this->searchEndpoint();

        $this->assertArrayHasKey('requestFields', $endpoint);

        $byName = [];
        foreach ($endpoint['requestFields'] as $field) {
            $byName[$field['name']] = $field;
        }

        // every declared filter is documented, typed, with its rules — no schema needed
        $this->assertArrayHasKey('name', $byName);
        $this->assertArrayHasKey('is_active', $byName);
        $this->assertArrayHasKey('max_price', $byName);

        $this->assertTrue($byName['name']['nullable']);
        $this->assertContains('nullable', $byName['max_price']['rules']);
    }
}
