<?php

namespace SchemaCraft\Tests\Feature;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Filesystem\Filesystem;
use Orchestra\Testbench\TestCase;
use SchemaCraft\SchemaCraftServiceProvider;

/**
 * Runtime validation of the generated SdkConnector chained through Resources.
 *
 * ─────────────────────────────────────────────────────────────────
 * WHY THIS TEST EXISTS — DO NOT WEAKEN OR DELETE WITHOUT READING
 * ─────────────────────────────────────────────────────────────────
 *
 * The SdkConnector is the HTTP transport that every SDK request flows through.
 * Its public surface IS the wire contract — change a method signature, change
 * the URL composition, change how it interprets a 422, and every consumer's
 * integration breaks. The class is fully generated, so a regression in
 * SdkConnectorGenerator silently propagates to every SDK we ship.
 *
 * SdkEndToEndTest::Layer 2 already exercises the SdkConnector against Guzzle
 * MockHandler for the bare get/post/422/5xx cases. This test deliberately
 * OVERLAPS those — there is no harm in independent coverage of a load-bearing
 * contract — and adds what that one doesn't: the Connector chained through a
 * generated Resource, asserting the WHOLE consumer-visible path stays intact:
 *
 *     Client → SdkConnector → real HTTP wire → SdkConnector → Resource → typed DTO
 *
 * If a refactor breaks any link in that chain, this test fires.
 *
 * ─────────────────────────────────────────────────────────────────
 * CONTRACTS PINNED (a refactor that breaks any of these breaks consumers)
 * ─────────────────────────────────────────────────────────────────
 *
 *  1. URL composition: rtrim(baseUrl) + '/' + ltrim(path).
 *     — adding or removing slashes breaks consumers passing
 *       'https://api.example.com' or 'https://api.example.com/'.
 *
 *  2. Authorization header: exactly `Bearer {token}`.
 *     — consumers' server-side auth expects this exact prefix.
 *
 *  3. Accept: application/json. Content-Type: application/json on body methods.
 *     — server route negotiation depends on these.
 *
 *  4. Body methods (POST/PUT/PATCH) JSON-encode the array param into the body.
 *     — consumers pass associative arrays through Resource methods; if the
 *       Connector silently switched to form-encoding, every server-side
 *       FormRequest validation would fail.
 *
 *  5. Response envelope unwrap: SDK Resources read `$response['data']` and
 *     hand it to `{Dto}::fromArray()`. The envelope shape `['data' => ...]` is
 *     the contract between the server's ApiResponseMiddleware and the SDK.
 *     — flattening the envelope on either side would break the entire pipeline.
 *
 *  6. Error mapping:
 *     - HTTP 422 → SdkValidationException (with errors bag)
 *     - any other 4xx/5xx → SdkRequestException
 *     — consumers catch these types specifically; renaming/reshaping breaks
 *       every try/catch in every consumer codebase.
 *
 *  7. Exception status access:
 *     - $e->getStatusCode() returns the HTTP status
 *     - $e->getErrors() returns the validation errors bag
 *     - $e->getMessage() returns the server-provided message
 *     — these are the documented public accessors. \Throwable::getCode()
 *       returns 0 (by design — status is on the typed accessor).
 *
 *  8. SdkValidationException extends SdkRequestException.
 *     — consumers can catch (SdkRequestException) and handle both, or catch
 *       (SdkValidationException) first to handle 422 specifically. Breaking
 *       this hierarchy breaks every documented usage example.
 *
 * ─────────────────────────────────────────────────────────────────
 * Implementation note
 * ─────────────────────────────────────────────────────────────────
 *
 * Done with MockHandler instead of a real HTTP server because the goal is the
 * transport contract, not network plumbing. A real server would slow tests,
 * add port flakiness, and validate Guzzle (not us).
 */
class SdkRoundTripTest extends TestCase
{
    private const SDK_NAMESPACE = 'Acme\\RoundTripSdk';

    private const TEST_NAMESPACE = 'SchemaCraft\\Tests\\Runtime\\RoundTrip';

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
    // API routes — without defineRoutes the generator emits zero schemas and the
    // command fails. Routes themselves don't get hit at runtime (Guzzle is mocked).
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
        if (class_exists(self::TEST_NAMESPACE.'\\SdkConnector')) {
            return;
        }

        $outputPath = base_path('packages/round-trip-sdk');
        $this->createdDirs[] = $outputPath;

        $exitCode = \Illuminate\Support\Facades\Artisan::call('schema:generate-sdk', [
            '--schema-path' => [dirname(__DIR__).'/Fixtures/Schemas'],
            '--path' => 'packages/round-trip-sdk',
            '--name' => 'acme/round-trip-sdk',
            '--namespace' => self::SDK_NAMESPACE,
            '--client' => 'TestClient',
        ]);
        if ($exitCode !== 0) {
            $output = \Illuminate\Support\Facades\Artisan::output();
            $this->fail("schema:generate-sdk failed (exit {$exitCode}):\n{$output}");
        }

        $generatedDataPrefix = self::SDK_NAMESPACE.'\\Data\\';
        $testDataPrefix = self::TEST_NAMESPACE.'\\Data\\';

        // 1. Data DTOs.
        foreach ($this->files->files($outputPath.'/src/Data') as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $stripped = preg_replace('/^<\?php\s+namespace\s+\S+;\s+/s', '', $file->getContents());
            eval('namespace '.self::TEST_NAMESPACE.'\\Data; '.$stripped);
        }

        // 2. SDK exceptions + SdkConnector live directly under src/ (not src/Exceptions/).
        // Order matters: SdkRequestException must load before SdkValidationException (which extends it),
        // and both before SdkConnector (which references them).
        foreach (['SdkRequestException', 'SdkValidationException', 'SdkConnector'] as $shortName) {
            $contents = file_get_contents($outputPath.'/src/'.$shortName.'.php');
            $stripped = preg_replace('/^<\?php\s+namespace\s+\S+;\s+/s', '', $contents);
            eval('namespace '.self::TEST_NAMESPACE.'; '.$stripped);
        }

        // 4. Resources — Data DTO imports remapped to the test namespace.
        foreach ($this->files->files($outputPath.'/src/Resources') as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $stripped = preg_replace('/^<\?php\s+namespace\s+\S+;\s+/s', '', $file->getContents());
            $stripped = str_replace($generatedDataPrefix, $testDataPrefix, $stripped);
            eval('namespace '.self::TEST_NAMESPACE.'\\Resources; '.$stripped);
        }
    }

    /**
     * Build a Guzzle client whose handler returns the queued canned responses and
     * records every outgoing request. The history container lets us assert exact
     * URL/method/headers/body the SDK actually emitted.
     *
     * @param  Response[]  $cannedResponses  PSR-7 responses queued in send order
     * @param  array  &$history  populated with request/response records
     */
    private function makeHttpClient(array $cannedResponses, array &$history): Client
    {
        $mock = new MockHandler($cannedResponses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        return new Client(['handler' => $stack]);
    }

    private function envelope(mixed $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode(['data' => $data]));
    }

    private function errorResponse(int $status, string $message, array $errors = []): Response
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode(['message' => $message, 'errors' => $errors]),
        );
    }

    private function newConnector(Client $httpClient, string $baseUrl = 'https://api.example.test', string $token = 'test-token-abc'): object
    {
        $connectorClass = self::TEST_NAMESPACE.'\\SdkConnector';

        return new $connectorClass($baseUrl, $token, $httpClient);
    }

    private function dto(string $shortName): string
    {
        return self::TEST_NAMESPACE.'\\Data\\'.$shortName;
    }

    private function resource(string $shortName): string
    {
        return self::TEST_NAMESPACE.'\\Resources\\'.$shortName;
    }

    // ─────────────────────────────────────────────────────────────
    // Transport contract — URL composition, headers, body
    // ─────────────────────────────────────────────────────────────

    public function test_connector_composes_base_url_with_path_and_emits_bearer_header(): void
    {
        $history = [];
        $client = $this->makeHttpClient([
            $this->envelope(['id' => 1, 'name' => 'Widget']),
        ], $history);
        $connector = $this->newConnector($client);

        $resourceClass = $this->resource('CatalogResource');
        $resource = new $resourceClass($connector);
        $resource->get(1);

        $this->assertCount(1, $history);
        $request = $history[0]['request'];

        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('https://api.example.test/api/catalog/1', (string) $request->getUri());
        $this->assertSame('Bearer test-token-abc', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
    }

    public function test_create_sends_json_body_with_content_type(): void
    {
        $history = [];
        $client = $this->makeHttpClient([
            $this->envelope(['id' => 42, 'name' => 'Sent']),
        ], $history);
        $connector = $this->newConnector($client);

        $resourceClass = $this->resource('CatalogResource');
        $resource = new $resourceClass($connector);

        $rm = new \ReflectionMethod($resource, 'create');
        $args = [];
        foreach ($rm->getParameters() as $param) {
            $args[] = ['name' => 'Sent', 'price' => 1.23][$param->getName()] ?? null;
        }
        $rm->invokeArgs($resource, $args);

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));

        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('Sent', $body['name']);
    }

    // ─────────────────────────────────────────────────────────────
    // Envelope parse — response['data'] → typed DTO
    // ─────────────────────────────────────────────────────────────

    public function test_envelope_data_unwraps_into_typed_dto(): void
    {
        $history = [];
        $client = $this->makeHttpClient([
            $this->envelope([
                'id' => 7,
                'name' => 'Roundtrip',
                'attributes_json' => ['color' => 'blue', 'material' => 'steel', 'weight_grams' => 200],
            ]),
        ], $history);
        $connector = $this->newConnector($client);

        $resourceClass = $this->resource('CatalogResource');
        $resource = new $resourceClass($connector);
        $item = $resource->get(7);

        $this->assertInstanceOf($this->dto('CatalogData'), $item);
        $this->assertSame(7, $item->id);
        $this->assertInstanceOf($this->dto('CatalogAttributesData'), $item->attributes_json);
        $this->assertSame('blue', $item->attributes_json->color);
    }

    public function test_collection_envelope_unwraps_into_typed_dto_array(): void
    {
        $history = [];
        $client = $this->makeHttpClient([
            $this->envelope([
                ['id' => 1, 'name' => 'A'],
                ['id' => 2, 'name' => 'B'],
            ]),
        ], $history);
        $connector = $this->newConnector($client);

        $resourceClass = $this->resource('CatalogResource');
        $resource = new $resourceClass($connector);
        $items = $resource->getCollection();

        $this->assertCount(2, $items);
        foreach ($items as $item) {
            $this->assertInstanceOf($this->dto('CatalogData'), $item);
        }
        $this->assertSame('A', $items[0]->name);
    }

    // ─────────────────────────────────────────────────────────────
    // Error-status → typed exception mapping (the contract that lets
    // SDK consumers catch validation errors specifically)
    // ─────────────────────────────────────────────────────────────

    public function test_422_response_raises_validation_exception_with_errors_bag(): void
    {
        $history = [];
        $client = $this->makeHttpClient([
            $this->errorResponse(422, 'The given data was invalid.', [
                'name' => ['The name field is required.'],
                'price' => ['The price must be at least 0.'],
            ]),
        ], $history);
        $connector = $this->newConnector($client);

        $resourceClass = $this->resource('CatalogResource');
        $resource = new $resourceClass($connector);

        $validationExceptionClass = self::TEST_NAMESPACE.'\\SdkValidationException';

        try {
            $rm = new \ReflectionMethod($resource, 'create');
            $args = array_fill(0, $rm->getNumberOfParameters(), null);
            $rm->invokeArgs($resource, $args);
            $this->fail('Expected SdkValidationException to be raised on 422.');
        } catch (\Throwable $e) {
            $this->assertInstanceOf($validationExceptionClass, $e);
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame(
                ['The name field is required.'],
                $e->getErrors()['name'] ?? null,
                'Validation errors bag should round-trip through the connector.',
            );
        }
    }

    public function test_5xx_response_raises_request_exception(): void
    {
        $history = [];
        $client = $this->makeHttpClient([
            $this->errorResponse(500, 'Server exploded.'),
        ], $history);
        $connector = $this->newConnector($client);

        $resourceClass = $this->resource('CatalogResource');
        $resource = new $resourceClass($connector);

        $requestExceptionClass = self::TEST_NAMESPACE.'\\SdkRequestException';

        try {
            $resource->get(1);
            $this->fail('Expected SdkRequestException to be raised on 500.');
        } catch (\Throwable $e) {
            $this->assertInstanceOf($requestExceptionClass, $e);
            $this->assertSame(500, $e->getStatusCode());
            $this->assertSame('Server exploded.', $e->getMessage());
        }
    }

    public function test_4xx_response_raises_request_exception(): void
    {
        $history = [];
        $client = $this->makeHttpClient([
            $this->errorResponse(404, 'Not found.'),
        ], $history);
        $connector = $this->newConnector($client);

        $resourceClass = $this->resource('CatalogResource');
        $resource = new $resourceClass($connector);

        $requestExceptionClass = self::TEST_NAMESPACE.'\\SdkRequestException';

        try {
            $resource->get(999);
            $this->fail('Expected SdkRequestException to be raised on 404.');
        } catch (\Throwable $e) {
            $this->assertInstanceOf($requestExceptionClass, $e);
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Custom action — POST sub-path through the real transport
    // ─────────────────────────────────────────────────────────────

    public function test_custom_action_emits_post_to_subpath_and_returns_typed_action_result(): void
    {
        $history = [];
        $client = $this->makeHttpClient([
            $this->envelope(['success' => true, 'message' => 'Archived.']),
        ], $history);
        $connector = $this->newConnector($client);

        $resourceClass = $this->resource('CatalogResource');
        $resource = new $resourceClass($connector);
        $result = $resource->archive(11);

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.example.test/api/catalog/11/archive', (string) $request->getUri());

        $this->assertInstanceOf($this->dto('ActionResultData'), $result);
        $this->assertTrue($result->success);
    }
}
