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
            $this->assertArrayHasKey('columns', $entry, "schema [{$modelKey}] missing columns");
            $this->assertArrayHasKey('relationships', $entry, "schema [{$modelKey}] missing relationships");
            $this->assertArrayHasKey('endpoints', $entry, "schema [{$modelKey}] missing endpoints");
            $this->assertArrayHasKey('customActions', $entry, "schema [{$modelKey}] missing customActions");
        }
    }

    public function test_columns_only_carry_contract_delivered_fields(): void
    {
        $payload = $this->payload();
        $catalog = $payload['schemas']['Catalog'];

        $allowedColumnKeys = ['name', 'type', 'nullable', 'computed', 'innerDtoName', 'options'];
        $forbiddenColumnKeys = ['primary', 'autoIncrement', 'unique', 'unsigned', 'cast', 'managed', 'length', 'default'];

        foreach ($catalog['columns'] as $col) {
            $this->assertArrayHasKey('name', $col);
            $this->assertArrayHasKey('type', $col);
            $this->assertArrayHasKey('nullable', $col);

            foreach (array_keys($col) as $key) {
                $this->assertContains(
                    $key,
                    $allowedColumnKeys,
                    "Column [{$col['name']}] carries unexpected key [{$key}] — the contract only delivers "
                    .implode(', ', $allowedColumnKeys).'. Dead schema-level badges were removed in the visualizer/SDK alignment.',
                );
                $this->assertNotContains(
                    $key,
                    $forbiddenColumnKeys,
                    "Column [{$col['name']}] re-introduces dead badge field [{$key}] — those were schema-level metadata the Resource layer doesn't expose.",
                );
            }
        }
    }

    public function test_relationships_use_related_resource_fqcn_not_related_model_or_related_fields(): void
    {
        $payload = $this->payload();
        $catalog = $payload['schemas']['Catalog'];

        $this->assertNotEmpty($catalog['relationships'], 'Catalog should declare relationships');

        foreach ($catalog['relationships'] as $rel) {
            $this->assertArrayHasKey('name', $rel);
            $this->assertArrayHasKey('type', $rel);
            $this->assertArrayHasKey('relatedResource', $rel, "relationship [{$rel['name']}] missing relatedResource (FQCN)");
            $this->assertArrayHasKey('isCollection', $rel);

            // The full FQCN, not a stripped bare name. If something starts emitting only the
            // basename or a model name, that's the strip/re-append dance creeping back.
            $this->assertStringContainsString(
                '\\',
                $rel['relatedResource'],
                "relationship [{$rel['name']}].relatedResource should be a FQCN, not [{$rel['relatedResource']}]",
            );

            // The pre-alignment fields are GONE — both were visualizer-specific carve-outs
            // that codified divergence between the visualizer and the SDK pipeline.
            $this->assertArrayNotHasKey(
                'relatedModel',
                $rel,
                "relationship [{$rel['name']}] re-introduced the stripped relatedModel field. See SdkResourceNaming for the canonical transform.",
            );
            $this->assertArrayNotHasKey(
                'relatedFields',
                $rel,
                "relationship [{$rel['name']}] re-introduced the inline relatedFields nesting. Consumers walk the flat schemas map instead.",
            );
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
