<?php

namespace SchemaCraft\Tests\Feature;

use Orchestra\Testbench\TestCase;
use SchemaCraft\Config\ConfigResolver;
use SchemaCraft\Generator\Sdk\SdkContextBuilder;
use SchemaCraft\Migration\SchemaDiscovery;
use SchemaCraft\SchemaCraftServiceProvider;

/**
 * Pins the unified API docs JSON contract returned by GenerateController::apiRoutes.
 *
 * Why this test exists: the visualizer's API tab and the SDK generator now consume the
 * same per-Resource shape from SdkBuildResult — this test guards the contract that both
 * sides depend on. The high-value invariants are:
 *
 *   1. Top-level keys (apiName / routeFile / routePrefix / schemas / warnings / errors)
 *      stay stable; visualizer and SDK both rely on them.
 *   2. The `schemas` map is keyed by bare model name; entries carry the canonical Resource
 *      FQCN in `resourceClass` so consumers never have to play strip/re-append games.
 *   3. Relationships expose `relatedResource` (FQCN), NOT `relatedModel` (stripped name) and
 *      NOT `relatedFields` (the old inline-nesting payload). Visualizer-only carve-outs and
 *      the strip/re-append dance are both gone — re-introducing either would surface here.
 *   4. Columns only carry contract-delivered fields: name, type, nullable, optional computed
 *      flag, optional innerDtoName for rich types. The 8 schema-level badges (primary,
 *      autoIncrement, unique, unsigned, cast, managed, length, default) are gone.
 *
 * Intentional shape changes update this test in the same PR — review the diff explicitly.
 */
class ApiRoutesShapeTest extends TestCase
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
            \SchemaCraft\Tests\Fixtures\Api\CatalogController::apiRoutes();
        });
    }

    /**
     * Build the unified API docs payload the way GenerateController::apiRoutes does, but with
     * the fixture schema directory wired in explicitly so the test doesn't depend on the host
     * app's discovery config. The visualizer's apiRoutes is a thin wrapper around this same
     * pipeline (SdkContextBuilder->build + SdkBuildResult->toApiDocsJson); testing the projection
     * directly is what catches contract drift either consumer would feel.
     */
    private function payload(): array
    {
        $apiConfig = ConfigResolver::resolve('default');
        $schemaClasses = (new SchemaDiscovery)->discover([dirname(__DIR__).'/Fixtures/Schemas']);

        $result = (new SdkContextBuilder)->build($apiConfig, $schemaClasses);

        return $result->toApiDocsJson($apiConfig->name, $apiConfig->routeFile, $apiConfig->routePrefix);
    }

    public function test_top_level_keys_stay_stable(): void
    {
        $payload = $this->payload();

        $this->assertArrayHasKey('apiName', $payload);
        $this->assertArrayHasKey('routeFile', $payload);
        $this->assertArrayHasKey('routePrefix', $payload);
        $this->assertArrayHasKey('schemas', $payload);
        $this->assertArrayHasKey('warnings', $payload);
        $this->assertArrayHasKey('errors', $payload);

        // Old shape's `groups` and `unassigned` are gone — they were per-consumer carve-outs.
        $this->assertArrayNotHasKey('groups', $payload);
        $this->assertArrayNotHasKey('unassigned', $payload);
    }

    public function test_schemas_keyed_by_bare_model_name_and_carry_canonical_fqcn(): void
    {
        $payload = $this->payload();

        $this->assertIsArray($payload['schemas']);
        $this->assertArrayHasKey('Catalog', $payload['schemas']);

        $catalog = $payload['schemas']['Catalog'];

        // Bare model name in the key + the entry; resourceClass carries the FQCN so consumers
        // can display or transform it without playing strip/re-append games.
        $this->assertSame('Catalog', $catalog['modelName']);
        $this->assertSame(
            'SchemaCraft\\Tests\\Fixtures\\Resources\\CatalogResource',
            $catalog['resourceClass'],
        );
        $this->assertSame(
            'SchemaCraft\\Tests\\Fixtures\\Schemas\\CatalogSchema',
            $catalog['schemaClass'],
        );
    }

    public function test_each_schema_entry_has_the_expected_shape(): void
    {
        $payload = $this->payload();

        foreach ($payload['schemas'] as $modelKey => $entry) {
            $this->assertArrayHasKey('modelName', $entry, "schema [{$modelKey}] missing modelName");
            $this->assertArrayHasKey('resourceClass', $entry, "schema [{$modelKey}] missing resourceClass");
            $this->assertArrayHasKey('schemaClass', $entry, "schema [{$modelKey}] missing schemaClass");
            $this->assertArrayHasKey('isDependencyOnly', $entry, "schema [{$modelKey}] missing isDependencyOnly");
            $this->assertArrayHasKey('tableName', $entry, "schema [{$modelKey}] missing tableName");
            $this->assertArrayHasKey('fields', $entry, "schema [{$modelKey}] missing fields");
            $this->assertArrayNotHasKey('columns', $entry, "schema [{$modelKey}] still carries the old columns bucket");
            $this->assertArrayNotHasKey('relationships', $entry, "schema [{$modelKey}] still carries the old relationships bucket");
            $this->assertArrayHasKey('endpoints', $entry, "schema [{$modelKey}] missing endpoints");
            $this->assertArrayHasKey('customActions', $entry, "schema [{$modelKey}] missing customActions");
        }
    }

    public function test_fields_only_carry_contract_delivered_keys(): void
    {
        $payload = $this->payload();
        $catalog = $payload['schemas']['Catalog'];

        // Columns + nested-shape fields share one `fields` list now. Allowed keys are the union
        // of the column contract and the nested-shape contract; dead schema-level metadata stays out.
        $allowedKeys = ['name', 'type', 'nullable', 'computed', 'innerDtoName', 'options', 'isCollection', 'isNestedShape', 'nestedShapeClass'];
        $forbiddenKeys = ['primary', 'autoIncrement', 'unique', 'unsigned', 'cast', 'managed', 'length', 'default', 'relatedModel', 'relatedFields'];

        foreach ($catalog['fields'] as $f) {
            $this->assertArrayHasKey('name', $f);

            foreach (array_keys($f) as $key) {
                $this->assertContains(
                    $key,
                    $allowedKeys,
                    "Field [{$f['name']}] carries unexpected key [{$key}] — the contract only delivers ".implode(', ', $allowedKeys).'.',
                );
                $this->assertNotContains(
                    $key,
                    $forbiddenKeys,
                    "Field [{$f['name']}] re-introduces dead key [{$key}].",
                );
            }
        }
    }

    public function test_nested_shape_fields_carry_the_resource_fqcn_pointer(): void
    {
        $payload = $this->payload();
        $catalog = $payload['schemas']['Catalog'];

        // Former "relationships" are now nested-shape fields inside the one `fields` list.
        $nested = array_values(array_filter($catalog['fields'], fn ($f) => ! empty($f['isNestedShape'])));
        $this->assertNotEmpty($nested, 'Catalog should declare nested-shape fields (former relationships)');

        foreach ($nested as $f) {
            $this->assertArrayHasKey('name', $f);
            $this->assertArrayHasKey('nestedShapeClass', $f, "nested field [{$f['name']}] missing nestedShapeClass (FQCN pointer)");
            $this->assertArrayHasKey('isCollection', $f);

            // The full FQCN, not a stripped bare name — consumers resolve it in the shared schemas map.
            $this->assertStringContainsString(
                '\\',
                $f['nestedShapeClass'],
                "nested field [{$f['name']}].nestedShapeClass should be a FQCN, not [{$f['nestedShapeClass']}]",
            );

            // The old keys are GONE — one fields list, one pointer key (nestedShapeClass).
            $this->assertArrayNotHasKey('relatedResource', $f, "nested field [{$f['name']}] still uses the old relatedResource key");
            $this->assertArrayNotHasKey('relatedModel', $f);
            $this->assertArrayNotHasKey('relatedFields', $f);
        }
    }

    public function test_dependency_resources_register_in_the_schemas_map_too(): void
    {
        $payload = $this->payload();

        // The Catalog SDK declares HasMany/HasOne/BelongsTo to several other Resources;
        // post Resource-walked cutover, each of those becomes its own entry in the schemas
        // map (isDependencyOnly: true). The visualizer renders nested relationships by
        // walking these entries; the SDK generates DTOs from the same map.
        $expectedDeps = ['CatalogVariant', 'CatalogShipment', 'CatalogBrand', 'CatalogReview', 'CatalogSupplier'];
        foreach ($expectedDeps as $depKey) {
            $this->assertArrayHasKey($depKey, $payload['schemas'], "dep schema [{$depKey}] missing from unified payload");
            $this->assertTrue(
                $payload['schemas'][$depKey]['isDependencyOnly'],
                "dep schema [{$depKey}] should be isDependencyOnly: true",
            );
        }

        // Catalog itself is primary, not dep-only.
        $this->assertFalse($payload['schemas']['Catalog']['isDependencyOnly']);
    }
}
