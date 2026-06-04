<?php

namespace SchemaCraft\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Orchestra\Testbench\TestCase;
use SchemaCraft\SchemaCraftServiceProvider;

/**
 * Runtime validation of generated SDK Resource classes.
 *
 * ─────────────────────────────────────────────────────────────────
 * WHY THIS TEST EXISTS — DO NOT WEAKEN OR DELETE WITHOUT READING
 * ─────────────────────────────────────────────────────────────────
 *
 * Resources are the per-model API surface every consumer reaches through
 * `$client->{resource}()->{method}(...)`. Each method is generated code with
 * a specific shape: path, HTTP verb, body payload, and return-type hydration.
 *
 * SdkGoldenTest pins the source text via string match — but a refactor can emit
 * syntactically-equivalent code whose runtime behavior is broken (missed
 * inner-DTO wrap, wrong array→DTO mapping, missing `use` import for a response
 * DTO referenced by short name). String tests miss that class of regression.
 * This test catches it by actually invoking each method against a recording
 * mock connector.
 *
 * This is also the test that found and forced the fix for the missing-`use`
 * generator bug on 2026-06-02 (Resources only imported the primary DTO; any
 * endpoint returning a different DTO threw "class not found" at consumer
 * runtime). Without runtime invocation, that bug was invisible.
 *
 * ─────────────────────────────────────────────────────────────────
 * CONTRACTS PINNED (a refactor that breaks any of these breaks consumers)
 * ─────────────────────────────────────────────────────────────────
 *
 *  1. Resource constructor takes ONE arg: the SdkConnector instance.
 *     — Client/test code can construct Resources directly with a connector.
 *
 *  2. Each generated method emits exactly ONE connector call.
 *     — No retries, no batching, no implicit pagination loops in Resources.
 *
 *  3. Path construction:
 *     - collection methods → "api/{plural-kebab}"
 *     - single methods    → "api/{plural-kebab}/{id}"
 *     - custom actions    → "api/{plural-kebab}/{id}/{action-kebab}"
 *     — pluralization + kebab-case slug is the documented URL contract.
 *
 *  4. HTTP verb per method:
 *     - get/getCollection → GET
 *     - create            → POST
 *     - update            → PUT
 *     - delete            → DELETE
 *     - custom actions    → respect the route's declared method
 *     — changing any of these breaks server-side route matching.
 *
 *  5. Body payload uses field names from FormRequest as-is (no casing change).
 *     — consumers read DTO properties verbatim and pass them straight back.
 *
 *  6. Return type:
 *     - single methods    → typed `{Model}Data` instance
 *     - collection methods → `Illuminate\Support\Collection<int, {Model}Data>`
 *     - actions with #[ApiResponse] → the typed response DTO
 *     - void delete       → void
 *     — typed returns are why consumers don't have to array-index responses.
 *
 *  7. Inner DTO hydration on response: response['data'] is unwrapped, then
 *     each field whose schema declares a nested type (JsonColumn / Bitmask /
 *     Collection / Resource relationship) is constructed via {Inner}::fromArray
 *     before the parent DTO sees it.
 *     — a regression here means the parent DTO's property is a raw array, not
 *       a typed instance — surface looks fine but consumers can't chain.
 *
 *  8. Endpoint-specific response DTOs (e.g. action returning ActionResultData)
 *     are imported via `use` at the top of the Resource file.
 *     — without this, the short-name reference throws "class not found" at
 *       consumer runtime even though the SDK generates cleanly. This is the
 *       generator bug class this test originally surfaced; the import-set
 *       assertion below is the guard against it returning.
 */
class SdkResourceRuntimeTest extends TestCase
{
    private const SDK_NAMESPACE = 'Acme\\ResourceRuntimeSdk';

    private const TEST_NAMESPACE = 'SchemaCraft\\Tests\\Runtime\\Resource';

    private Filesystem $files;

    private array $createdDirs = [];

    /**
     * Source-text snapshot of the generated CatalogResource, captured during the first
     * setUp before tearDown wipes the on-disk SDK. Needed because the import-set test
     * inspects the file as text (the eval'd in-memory class doesn't carry `use` lines).
     */
    private static ?string $catalogResourceSource = null;

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

    protected function setUp(): void
    {
        parent::setUp();
        $this->files = new Filesystem;
        $this->generateAndLoadSdk();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdDirs as $dir) {
            if (is_dir($dir)) {
                $this->files->deleteDirectory($dir);
            }
        }
        parent::tearDown();
    }

    private function generateAndLoadSdk(): void
    {
        if (class_exists(self::TEST_NAMESPACE.'\\Resources\\CatalogResource')) {
            return;
        }

        $outputPath = base_path('packages/resource-runtime-sdk');
        $this->createdDirs[] = $outputPath;

        $this->artisan('schema:generate-sdk', [
            '--schema-path' => [dirname(__DIR__).'/Fixtures/Schemas'],
            '--path' => 'packages/resource-runtime-sdk',
            '--name' => 'acme/resource-runtime-sdk',
            '--namespace' => self::SDK_NAMESPACE,
            '--client' => 'TestClient',
        ])->assertSuccessful();

        // Snapshot the Resource source NOW — tearDown wipes the dir between tests,
        // so disk-based assertions in later tests need this captured copy.
        self::$catalogResourceSource = file_get_contents($outputPath.'/src/Resources/CatalogResource.php');

        // Load every Data DTO (Resources reference them by short class name).
        foreach ($this->files->files($outputPath.'/src/Data') as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $stripped = preg_replace('/^<\?php\s+namespace\s+\S+;\s+/s', '', $file->getContents());
            eval('namespace '.self::TEST_NAMESPACE.'\\Data; '.$stripped);
        }

        // Resources import their Data DTOs by FQCN from the generated namespace.
        // Rewrite that prefix to point at the Data DTOs we just eval'd into the test
        // namespace so PHP's `use` resolution finds them. Other use lines
        // (Illuminate\Support\Collection, etc.) stay untouched.
        $generatedDataPrefix = self::SDK_NAMESPACE.'\\Data\\';
        $testDataPrefix = self::TEST_NAMESPACE.'\\Data\\';

        foreach ($this->files->files($outputPath.'/src/Resources') as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $contents = $file->getContents();
            $stripped = preg_replace('/^<\?php\s+namespace\s+\S+;\s+/s', '', $contents);
            $stripped = str_replace($generatedDataPrefix, $testDataPrefix, $stripped);
            eval('namespace '.self::TEST_NAMESPACE.'\\Resources; '.$stripped);
        }
    }

    private function dto(string $shortName): string
    {
        return self::TEST_NAMESPACE.'\\Data\\'.$shortName;
    }

    private function resource(string $shortName): string
    {
        return self::TEST_NAMESPACE.'\\Resources\\'.$shortName;
    }

    /**
     * Build a recording mock connector with pre-canned responses keyed by (method, path).
     * Records every call so the test can assert on the EXACT path / method / body sent.
     */
    private function mockConnector(array $cannedResponses = []): object
    {
        return new class($cannedResponses) {
            public array $calls = [];

            public function __construct(public array $cannedResponses) {}

            public function get($path)
            {
                $this->calls[] = ['method' => 'GET', 'path' => $path, 'body' => null];

                return $this->cannedResponses['GET '.$path] ?? ['data' => []];
            }

            public function post($path, array $data)
            {
                $this->calls[] = ['method' => 'POST', 'path' => $path, 'body' => $data];

                return $this->cannedResponses['POST '.$path] ?? ['data' => []];
            }

            public function put($path, array $data)
            {
                $this->calls[] = ['method' => 'PUT', 'path' => $path, 'body' => $data];

                return $this->cannedResponses['PUT '.$path] ?? ['data' => []];
            }

            public function patch($path, array $data)
            {
                $this->calls[] = ['method' => 'PATCH', 'path' => $path, 'body' => $data];

                return $this->cannedResponses['PATCH '.$path] ?? ['data' => []];
            }

            public function delete($path, array $data = [])
            {
                $this->calls[] = ['method' => 'DELETE', 'path' => $path, 'body' => $data];

                return $this->cannedResponses['DELETE '.$path] ?? ['data' => null];
            }
        };
    }

    // ─────────────────────────────────────────────────────────────
    // GET collection — list endpoint
    // ─────────────────────────────────────────────────────────────

    public function test_get_collection_calls_correct_path_and_returns_typed_dtos(): void
    {
        $connector = $this->mockConnector([
            'GET api/catalog' => ['data' => [
                ['id' => 1, 'name' => 'A'],
                ['id' => 2, 'name' => 'B'],
            ]],
        ]);

        $resourceClass = $this->resource('CatalogResource');
        $resource = new $resourceClass($connector);
        $items = $resource->getCollection();

        $this->assertCount(1, $connector->calls);
        $this->assertSame('GET', $connector->calls[0]['method']);
        $this->assertSame('api/catalog', $connector->calls[0]['path']);

        // Contract #6: collection methods MUST return Illuminate\Support\Collection,
        // not a bare array. Consumers chain ->map/->filter/->first on the result.
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $items);

        $this->assertCount(2, $items);
        foreach ($items as $item) {
            $this->assertInstanceOf($this->dto('CatalogData'), $item);
        }
        $this->assertSame('A', $items[0]->name);
        $this->assertSame('B', $items[1]->name);
    }

    // ─────────────────────────────────────────────────────────────
    // GET single — interpolates path parameter
    // ─────────────────────────────────────────────────────────────

    public function test_get_single_interpolates_id_into_path(): void
    {
        $connector = $this->mockConnector([
            'GET api/catalog/42' => ['data' => ['id' => 42, 'name' => 'Widget']],
        ]);

        $resourceClass = $this->resource('CatalogResource');
        $resource = new $resourceClass($connector);
        $item = $resource->get(42);

        $this->assertSame('GET', $connector->calls[0]['method']);
        $this->assertSame('api/catalog/42', $connector->calls[0]['path']);

        $this->assertInstanceOf($this->dto('CatalogData'), $item);
        $this->assertSame(42, $item->id);
        $this->assertSame('Widget', $item->name);
    }

    // ─────────────────────────────────────────────────────────────
    // POST create — sends body, returns typed DTO
    // ─────────────────────────────────────────────────────────────

    public function test_create_sends_body_and_returns_typed_dto(): void
    {
        $connector = $this->mockConnector([
            'POST api/catalog' => ['data' => ['id' => 100, 'name' => 'NewItem']],
        ]);

        $resourceClass = $this->resource('CatalogResource');
        $resource = new $resourceClass($connector);

        // The create() signature comes from CreateCatalogRequest's FormRequest fields —
        // we pass via reflection to be tolerant of parameter ordering changes.
        $created = $this->invokeCreate($resource, ['name' => 'NewItem', 'price' => 9.99]);

        $this->assertSame('POST', $connector->calls[0]['method']);
        $this->assertSame('api/catalog', $connector->calls[0]['path']);
        $this->assertNotEmpty($connector->calls[0]['body']);

        $this->assertInstanceOf($this->dto('CatalogData'), $created);
        $this->assertSame(100, $created->id);
        $this->assertSame('NewItem', $created->name);
    }

    // ─────────────────────────────────────────────────────────────
    // PUT update — interpolates id, sends body
    // ─────────────────────────────────────────────────────────────

    public function test_update_uses_put_with_id_in_path(): void
    {
        $connector = $this->mockConnector([
            'PUT api/catalog/7' => ['data' => ['id' => 7, 'name' => 'Updated']],
        ]);

        $resourceClass = $this->resource('CatalogResource');
        $resource = new $resourceClass($connector);

        // Updates take ($id, ...params) — invoke via reflection for the same reason
        // create did.
        $updated = $this->invokeUpdate($resource, 7, ['name' => 'Updated']);

        $this->assertSame('PUT', $connector->calls[0]['method']);
        $this->assertSame('api/catalog/7', $connector->calls[0]['path']);

        $this->assertInstanceOf($this->dto('CatalogData'), $updated);
        $this->assertSame('Updated', $updated->name);
    }

    // ─────────────────────────────────────────────────────────────
    // DELETE — interpolates id
    // ─────────────────────────────────────────────────────────────

    public function test_delete_uses_delete_with_id_in_path(): void
    {
        // CatalogController::delete returns an ActionResultResource → the SDK Resource
        // hydrates ActionResultData::fromArray($response['data']), so the canned envelope
        // needs a valid shape (not null). This is the documented action-response pattern.
        $connector = $this->mockConnector([
            'DELETE api/catalog/9' => ['data' => ['success' => true, 'message' => 'Deleted.']],
        ]);

        $resourceClass = $this->resource('CatalogResource');
        $resource = new $resourceClass($connector);
        $result = $resource->delete(9);

        $this->assertSame('DELETE', $connector->calls[0]['method']);
        $this->assertSame('api/catalog/9', $connector->calls[0]['path']);

        $this->assertInstanceOf($this->dto('ActionResultData'), $result);
        $this->assertTrue($result->success);
    }

    // ─────────────────────────────────────────────────────────────
    // Custom action — POST /catalog/{id}/archive
    // ─────────────────────────────────────────────────────────────

    public function test_custom_action_routes_to_subpath(): void
    {
        $connector = $this->mockConnector([
            'POST api/catalog/5/archive' => ['data' => ['success' => true, 'message' => 'Archived.']],
        ]);

        $resourceClass = $this->resource('CatalogResource');
        $resource = new $resourceClass($connector);
        $resource->archive(5);

        $this->assertSame('POST', $connector->calls[0]['method']);
        $this->assertSame('api/catalog/5/archive', $connector->calls[0]['path']);
    }

    // ─────────────────────────────────────────────────────────────
    // Nested DTO hydration in resource responses
    // ─────────────────────────────────────────────────────────────

    public function test_resource_response_hydrates_inner_jsoncolumn_dto(): void
    {
        // When the response includes a JsonColumn field, the Resource's response unwrap
        // → DTO::fromArray chain should produce a typed nested DTO, not a raw array.
        $connector = $this->mockConnector([
            'GET api/catalog/3' => ['data' => [
                'id' => 3,
                'name' => 'WithAttrs',
                'attributes_json' => ['color' => 'green', 'material' => 'wood', 'weight_grams' => 80],
            ]],
        ]);

        $resourceClass = $this->resource('CatalogResource');
        $resource = new $resourceClass($connector);
        $item = $resource->get(3);

        $this->assertInstanceOf($this->dto('CatalogAttributesData'), $item->attributes_json);
        $this->assertSame('green', $item->attributes_json->color);
    }

    // ─────────────────────────────────────────────────────────────
    // Contract #8 — `use` import set
    //
    // The Resource references its primary `{Model}Data` AND every endpoint-specific
    // response DTO (e.g. ActionResultData from an action returning that resource) by
    // SHORT NAME. Each one MUST appear in the `use` block at the top of the file or
    // PHP resolves it in the Resource's own namespace and throws "class not found"
    // at consumer runtime. This was a latent generator bug surfaced 2026-06-02.
    //
    // The runtime tests above already prove the references resolve (the methods
    // wouldn't return typed DTOs otherwise). This test ADDITIONALLY pins the
    // import-set itself by parsing the file as text — so a regression that uses
    // FQCN references everywhere instead of importing would still fail here even
    // if it happened to work at runtime.
    // ─────────────────────────────────────────────────────────────

    public function test_resource_file_imports_every_referenced_data_dto(): void
    {
        $this->assertNotNull(self::$catalogResourceSource, 'CatalogResource source snapshot must be populated during setUp.');

        // Collect the `use Acme\...\Data\X;` imports.
        preg_match_all(
            '/^use\s+'.preg_quote(self::SDK_NAMESPACE, '/').'\\\\Data\\\\(\w+);/m',
            self::$catalogResourceSource,
            $importMatches,
        );
        $importedShortNames = $importMatches[1];

        // The primary DTO + every action-response DTO MUST be in the import list.
        $this->assertContains('CatalogData', $importedShortNames, 'Primary DTO must be imported.');
        $this->assertContains('ActionResultData', $importedShortNames, 'Endpoint-specific response DTO must be imported — without this the SHORT-NAME reference throws at consumer runtime.');
    }

    // ─────────────────────────────────────────────────────────────
    // Reflection helpers — Resource method signatures vary by generated source
    // ─────────────────────────────────────────────────────────────

    /**
     * The generated create() method takes individual parameters per FormRequest field
     * (not an array). We invoke via reflection so the test stays robust against
     * parameter order changes — we map by name from $payload to each ReflectionParameter.
     */
    private function invokeCreate(object $resource, array $payload)
    {
        $rm = new \ReflectionMethod($resource, 'create');
        $args = [];
        foreach ($rm->getParameters() as $param) {
            $args[] = $payload[$param->getName()] ?? ($param->isDefaultValueAvailable() ? $param->getDefaultValue() : null);
        }

        return $rm->invokeArgs($resource, $args);
    }

    private function invokeUpdate(object $resource, $id, array $payload)
    {
        $rm = new \ReflectionMethod($resource, 'update');
        $args = [];
        foreach ($rm->getParameters() as $i => $param) {
            // First param is the path id ($catalog); rest map by name.
            if ($i === 0) {
                $args[] = $id;

                continue;
            }
            $args[] = $payload[$param->getName()] ?? ($param->isDefaultValueAvailable() ? $param->getDefaultValue() : null);
        }

        return $rm->invokeArgs($resource, $args);
    }
}
