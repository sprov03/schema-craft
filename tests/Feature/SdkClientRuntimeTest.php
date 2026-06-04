<?php

namespace SchemaCraft\Tests\Feature;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Filesystem\Filesystem;
use Orchestra\Testbench\TestCase;
use SchemaCraft\SchemaCraftServiceProvider;

/**
 * Runtime validation of the generated top-level SDK Client class.
 *
 * ─────────────────────────────────────────────────────────────────
 * WHY THIS TEST EXISTS — DO NOT WEAKEN OR DELETE WITHOUT READING
 * ─────────────────────────────────────────────────────────────────
 *
 * The Client class is the ENTRY POINT every SDK consumer uses. A consumer's
 * integration code looks like:
 *
 *     $client = new \Acme\CatalogSdk\CatalogClient($baseUrl, $token);
 *     $catalog = $client->catalogs()->get(42);
 *
 * Every other SDK class (SdkConnector, Resources, Data DTOs) is reachable only
 * through this entry point. If the Client's interface drifts, every consumer
 * codebase breaks on upgrade — silently if it's a return-type drift, loudly if
 * it's a method-name drift.
 *
 * Other SDK tests cover their layer well (SdkDtoRuntimeTest pins fromArray,
 * SdkResourceRuntimeTest pins Resource method call shapes, SdkRoundTripTest
 * pins the wire contract). None of them exercise the Client class itself —
 * they all construct Resources directly from a connector, which is NOT the
 * pattern any real consumer uses.
 *
 * ─────────────────────────────────────────────────────────────────
 * CONTRACTS PINNED (a refactor that breaks any of these breaks consumers)
 * ─────────────────────────────────────────────────────────────────
 *
 *  1. Constructor signature: `new {ClientName}(string $baseUrl, string $token)`
 *     — two positional args, no options array. A future "add a third optional
 *       param" is safe; a "swap to options array" or "rename baseUrl" is not.
 *
 *  2. Resource accessor naming: `Str::camel(Str::pluralStudly($modelName))`
 *     — for model `Catalog` this is `catalogs()`. This slug is documented in
 *       generated README examples; changing it silently breaks every consumer.
 *
 *  3. Resource accessor return type: each accessor returns an instance of the
 *     corresponding `{Model}Resource`.
 *     — consumers chain `$client->catalogs()->get($id)`; if accessors started
 *       returning something else (a builder, an array, null), the chain breaks.
 *
 *  4. Connector wiring: the Client constructs ONE `SdkConnector` internally
 *     using the baseUrl + token, and passes the SAME instance to every Resource.
 *     — guarantees a single auth context across resources; breaking this would
 *       force consumers to re-auth per resource or hold stale token state.
 *
 *  5. The Client is the ONLY required public symbol from the SDK package.
 *     Consumers do not (and should not) reach for `SdkConnector` directly.
 *     — preserves our ability to refactor SdkConnector freely.
 *
 * ─────────────────────────────────────────────────────────────────
 * Implementation note
 * ─────────────────────────────────────────────────────────────────
 *
 * The generated Client's constructor hardcodes a real Guzzle client:
 *     $this->connector = new SdkConnector($baseUrl, $token);
 *
 * That's by design — consumers shouldn't have to manage Guzzle. But it means
 * this test cannot inject a mock through the public constructor. We use
 * Reflection to replace the SdkConnector's `$httpClient` with a Guzzle
 * MockHandler-backed client AFTER constructing the Client, so we can record
 * exactly what URL/headers/body the Client emitted without real network.
 *
 * If the Client constructor ever stops calling `new SdkConnector($baseUrl, $token)`
 * (e.g., factory pattern, lazy resolution), the reflection step below will
 * need updating — and the test failure on that step IS the signal that the
 * Client's internal wiring contract changed.
 */
class SdkClientRuntimeTest extends TestCase
{
    private const SDK_NAMESPACE = 'Acme\\ClientRuntimeSdk';

    private const TEST_NAMESPACE = 'SchemaCraft\\Tests\\Runtime\\ClientSdk';

    private const CLIENT_CLASS_NAME = 'CatalogClient';

    private Filesystem $files;

    private array $createdDirs = [];

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

    // The SDK generator only emits Resources for schemas whose controllers register
    // API routes. Without defineRoutes the generator emits zero schemas and the
    // Client is generated without any resource accessors — defeating the whole test.
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
        if (class_exists(self::TEST_NAMESPACE.'\\'.self::CLIENT_CLASS_NAME)) {
            return;
        }

        $outputPath = base_path('packages/client-runtime-sdk');
        $this->createdDirs[] = $outputPath;

        $exitCode = \Illuminate\Support\Facades\Artisan::call('schema:generate-sdk', [
            '--schema-path' => [dirname(__DIR__).'/Fixtures/Schemas'],
            '--path' => 'packages/client-runtime-sdk',
            '--name' => 'acme/client-runtime-sdk',
            '--namespace' => self::SDK_NAMESPACE,
            '--client' => self::CLIENT_CLASS_NAME,
        ]);
        if ($exitCode !== 0) {
            $this->fail("schema:generate-sdk failed (exit {$exitCode}): ".\Illuminate\Support\Facades\Artisan::output());
        }

        $generatedDataPrefix = self::SDK_NAMESPACE.'\\Data\\';
        $testDataPrefix = self::TEST_NAMESPACE.'\\Data\\';
        $generatedResourcePrefix = self::SDK_NAMESPACE.'\\Resources\\';
        $testResourcePrefix = self::TEST_NAMESPACE.'\\Resources\\';

        // Eval order matters: Data DTOs → Exceptions → SdkConnector → Resources → Client.
        // Each later layer references the earlier ones by FQCN; PHP eval fails if a
        // referenced class isn't already declared.

        foreach ($this->files->files($outputPath.'/src/Data') as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $stripped = preg_replace('/^<\?php\s+namespace\s+\S+;\s+/s', '', $file->getContents());
            eval('namespace '.self::TEST_NAMESPACE.'\\Data; '.$stripped);
        }

        foreach (['SdkRequestException', 'SdkValidationException', 'SdkConnector'] as $shortName) {
            $contents = file_get_contents($outputPath.'/src/'.$shortName.'.php');
            $stripped = preg_replace('/^<\?php\s+namespace\s+\S+;\s+/s', '', $contents);
            eval('namespace '.self::TEST_NAMESPACE.'; '.$stripped);
        }

        foreach ($this->files->files($outputPath.'/src/Resources') as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $stripped = preg_replace('/^<\?php\s+namespace\s+\S+;\s+/s', '', $file->getContents());
            $stripped = str_replace($generatedDataPrefix, $testDataPrefix, $stripped);
            eval('namespace '.self::TEST_NAMESPACE.'\\Resources; '.$stripped);
        }

        // The Client imports Resources by FQCN — remap that prefix the same way we
        // remap Data imports in Resources.
        $clientContents = file_get_contents($outputPath.'/src/'.self::CLIENT_CLASS_NAME.'.php');
        $stripped = preg_replace('/^<\?php\s+namespace\s+\S+;\s+/s', '', $clientContents);
        $stripped = str_replace($generatedResourcePrefix, $testResourcePrefix, $stripped);
        eval('namespace '.self::TEST_NAMESPACE.'; '.$stripped);
    }

    /**
     * After constructing the Client (which builds a real SdkConnector with a real Guzzle
     * Client), swap the Guzzle client out via reflection so we can intercept HTTP.
     *
     * This is the chain: Client → SdkConnector → $httpClient (Guzzle\Client).
     * We replace the Guzzle client without touching the Client → Connector wiring,
     * so the test still validates that wiring honors its contract.
     */
    private function injectMockGuzzleIntoClient(object $client, array $cannedResponses, array &$history): void
    {
        $mock = new MockHandler($cannedResponses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $guzzle = new GuzzleClient(['handler' => $stack]);

        $clientReflection = new \ReflectionObject($client);
        $connectorProp = $clientReflection->getProperty('connector');
        $connectorProp->setAccessible(true);
        $connector = $connectorProp->getValue($client);

        $connectorReflection = new \ReflectionObject($connector);
        $httpClientProp = $connectorReflection->getProperty('httpClient');
        $httpClientProp->setAccessible(true);
        $httpClientProp->setValue($connector, $guzzle);
    }

    private function clientClass(): string
    {
        return self::TEST_NAMESPACE.'\\'.self::CLIENT_CLASS_NAME;
    }

    private function resourceClass(string $shortName): string
    {
        return self::TEST_NAMESPACE.'\\Resources\\'.$shortName;
    }

    private function dtoClass(string $shortName): string
    {
        return self::TEST_NAMESPACE.'\\Data\\'.$shortName;
    }

    // ─────────────────────────────────────────────────────────────
    // Contract 1 — Constructor signature
    // ─────────────────────────────────────────────────────────────

    public function test_client_constructor_accepts_base_url_and_token_positionally(): void
    {
        // The signature MUST stay `($baseUrl, $token)` in that order.
        // Adding an optional 3rd param later is safe; changing the first two breaks consumers.
        $clientClass = $this->clientClass();
        $client = new $clientClass('https://api.example.test', 'abc-token-123');

        $this->assertInstanceOf($clientClass, $client);

        $rc = new \ReflectionClass($clientClass);
        $ctor = $rc->getConstructor();
        $params = $ctor->getParameters();

        $this->assertGreaterThanOrEqual(2, count($params), 'Client constructor must accept at least baseUrl + token.');
        $this->assertSame('baseUrl', $params[0]->getName(), 'First param must be named baseUrl — documented in generated README.');
        $this->assertSame('token', $params[1]->getName(), 'Second param must be named token — documented in generated README.');
        $this->assertFalse($params[0]->isOptional(), 'baseUrl must remain required.');
        $this->assertFalse($params[1]->isOptional(), 'token must remain required.');
    }

    // ─────────────────────────────────────────────────────────────
    // Contract 2 — Resource accessor naming
    // ─────────────────────────────────────────────────────────────

    public function test_client_exposes_resource_accessor_named_via_camel_plural_studly(): void
    {
        // Catalog → Str::camel(Str::pluralStudly('Catalog')) → 'catalogs'.
        // If this method name drifts (singular, snake_case, no plural), consumers break.
        $clientClass = $this->clientClass();
        $client = new $clientClass('https://api.example.test', 'token');

        $this->assertTrue(method_exists($client, 'catalogs'), "Client must expose catalogs() accessor — Str::camel(Str::pluralStudly('Catalog')).");
    }

    // ─────────────────────────────────────────────────────────────
    // Contract 3 — Resource accessor return type
    // ─────────────────────────────────────────────────────────────

    public function test_client_resource_accessor_returns_typed_resource_instance(): void
    {
        $clientClass = $this->clientClass();
        $client = new $clientClass('https://api.example.test', 'token');

        $resource = $client->catalogs();

        $this->assertInstanceOf(
            $this->resourceClass('CatalogResource'),
            $resource,
            'Client accessor must return the typed Resource — consumers chain $client->catalogs()->get($id).',
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Contract 4 — Connector wiring (Client builds connector with baseUrl + token,
    // every Resource accessor shares the same connector instance)
    // ─────────────────────────────────────────────────────────────

    public function test_client_passes_base_url_and_token_through_to_real_http_calls(): void
    {
        // Constructs Client normally (it builds its own SdkConnector + Guzzle client),
        // then reflection-swaps the Guzzle layer. If the Client's constructor stopped
        // calling `new SdkConnector($baseUrl, $token)`, this test would fail on the
        // reflection step, alerting us that internal wiring changed.
        $clientClass = $this->clientClass();
        $client = new $clientClass('https://api.example.test', 'real-bearer-xyz');

        $history = [];
        $this->injectMockGuzzleIntoClient($client, [
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => ['id' => 1, 'name' => 'A']])),
        ], $history);

        $client->catalogs()->get(1);

        $request = $history[0]['request'];
        $this->assertSame('https://api.example.test/api/catalog/1', (string) $request->getUri(), 'Client must propagate baseUrl into Connector.');
        $this->assertSame('Bearer real-bearer-xyz', $request->getHeaderLine('Authorization'), 'Client must propagate token into Connector.');
    }

    public function test_all_resource_accessors_share_a_single_connector_instance(): void
    {
        // Every $client->{accessor}() must hand out a Resource backed by the SAME
        // SdkConnector. A regression that gave each accessor a fresh connector would
        // re-authenticate per call and lose any future stateful auth handling.
        $clientClass = $this->clientClass();
        $client = new $clientClass('https://api.example.test', 'token');

        $rc = new \ReflectionClass($client);
        $connectorProp = $rc->getProperty('connector');
        $connectorProp->setAccessible(true);
        $clientConnector = $connectorProp->getValue($client);

        $catalogResource = $client->catalogs();
        $resourceConnectorProp = (new \ReflectionObject($catalogResource))->getProperty('connector');
        $resourceConnectorProp->setAccessible(true);
        $resourceConnector = $resourceConnectorProp->getValue($catalogResource);

        $this->assertSame($clientConnector, $resourceConnector, 'Resource must reuse the Client-owned SdkConnector instance.');
    }

    // ─────────────────────────────────────────────────────────────
    // Contract 5 — Full end-to-end through the consumer's public API
    // (the integration test that mirrors documented usage in the generated README)
    // ─────────────────────────────────────────────────────────────

    public function test_end_to_end_client_call_returns_typed_dto(): void
    {
        // This is the canonical consumer usage shape — if it ever stops working,
        // the SDK is broken even when every other layer-specific test stays green.
        $clientClass = $this->clientClass();
        $client = new $clientClass('https://api.example.test', 'token');

        $history = [];
        $this->injectMockGuzzleIntoClient($client, [
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'data' => [
                    'id' => 7,
                    'name' => 'EndToEnd',
                    'attributes_json' => ['color' => 'red', 'material' => 'plastic', 'weight_grams' => 50],
                ],
            ])),
        ], $history);

        $catalog = $client->catalogs()->get(7);

        $this->assertInstanceOf($this->dtoClass('CatalogData'), $catalog);
        $this->assertSame(7, $catalog->id);
        $this->assertSame('EndToEnd', $catalog->name);
        $this->assertInstanceOf($this->dtoClass('CatalogAttributesData'), $catalog->attributes_json);
        $this->assertSame('red', $catalog->attributes_json->color);
    }
}
