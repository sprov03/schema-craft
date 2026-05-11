<?php

namespace SchemaCraft\Tests\Feature;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use SchemaCraft\Http\Middleware\ApiResponseMiddleware;
use SchemaCraft\Tests\TestCase;

/**
 * End-to-end tests for the SDK ↔ API contract.
 *
 * Strategy:
 *   Layer 1 — API response shape: hit routes protected by ApiResponseMiddleware
 *             and assert the exact JSON envelope the API produces.
 *   Layer 2 — SdkConnector behaviour: feed the connector a mock Guzzle client
 *             whose responses are the same envelopes the API produces, and assert
 *             what the connector does with them.
 *
 * This two-layer approach lets us verify both sides independently so we know
 * exactly WHERE any breakage occurs when a test fails.
 */
class SdkEndToEndTest extends TestCase
{
    // ─── Test routes ──────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(ApiResponseMiddleware::class)->prefix('api/test')->group(function () {

            // Successful list — simulates getCollection()
            Route::get('/items', fn () => response()->json([
                'data' => [
                    ['id' => 1, 'name' => 'Alpha'],
                    ['id' => 2, 'name' => 'Beta'],
                ],
            ]));

            // Successful single record — simulates get()
            Route::get('/items/{id}', fn ($id) => response()->json([
                'data' => ['id' => (int) $id, 'name' => 'Alpha'],
            ]));

            // Successful create — simulates create() returning a resource
            Route::post('/items', function () {
                return response()->json(['data' => ['id' => 3, 'name' => 'Gamma']]);
            });

            // 422 validation failure — simulates a FormRequest rejection
            Route::post('/items/validate', function () {
                throw ValidationException::withMessages([
                    'name' => ['The name field is required.'],
                    'email' => ['The email must be a valid email address.'],
                ]);
            });

            // Successful delete — 204 → converted to 200 envelope with null data
            Route::delete('/items/{id}', fn ($id) => response()->json(null, 204));

            // 404 — simulates a ModelNotFoundException
            Route::get('/items/missing/record', fn () => abort(404));

            // 500 — simulates an unexpected server error
            Route::get('/items/broken/server', function () {
                throw new \RuntimeException('Database connection failed.');
            });
        });
    }

    // ─── Layer 1: API response shape ──────────────────────────────

    public function test_api_list_returns_success_envelope_with_data_array(): void
    {
        $response = $this->getJson('/api/test/items');

        $response->assertOk();
        $response->assertJsonStructure([
            'status',
            'status_code',
            'message',
            'data' => [
                '*' => ['id', 'name'],
            ],
        ]);
        $response->assertJson([
            'status' => 'success',
            'status_code' => 200,
            'message' => 'Success.',
        ]);
        $this->assertIsArray($response->json('data'));
        $this->assertCount(2, $response->json('data'));
    }

    public function test_api_single_record_returns_success_envelope_with_object(): void
    {
        $response = $this->getJson('/api/test/items/1');

        $response->assertOk();
        $response->assertJson([
            'status' => 'success',
            'data' => ['id' => 1, 'name' => 'Alpha'],
        ]);
    }

    public function test_api_create_returns_success_envelope_with_created_record(): void
    {
        $response = $this->postJson('/api/test/items', ['name' => 'Gamma']);

        $response->assertOk();
        $response->assertJson([
            'status' => 'success',
            'data' => ['id' => 3, 'name' => 'Gamma'],
        ]);
    }

    public function test_api_delete_returns_success_envelope_with_null_data(): void
    {
        $response = $this->deleteJson('/api/test/items/1');

        $response->assertOk();
        $response->assertJson([
            'status' => 'success',
            'status_code' => 200,
            'data' => null,
        ]);
    }

    /**
     * This is the critical test for the 422 contract.
     *
     * The API MUST return Laravel's standard validation error shape wrapped
     * in the envelope — field-level errors under 'errors', generic message at top level.
     * The SDK is a pass-through and must expose exactly this shape to consumers.
     */
    public function test_api_422_returns_standard_validation_error_envelope(): void
    {
        $response = $this->postJson('/api/test/items/validate', []);

        $response->assertUnprocessable();
        $response->assertJsonStructure([
            'status',
            'status_code',
            'message',
            'errors' => [],
            'data',
        ]);
        $response->assertJson([
            'status' => 'error',
            'status_code' => 422,
            'message' => 'The given data was invalid.',
            'data' => null,
        ]);

        // Field-level errors must be present and in standard Laravel shape
        $errors = $response->json('errors');
        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayHasKey('email', $errors);
        $this->assertIsArray($errors['name']);
        $this->assertStringContainsString('required', $errors['name'][0]);
    }

    public function test_api_422_errors_field_is_array_of_arrays(): void
    {
        $response = $this->postJson('/api/test/items/validate', []);

        $response->assertUnprocessable();

        // Each field's errors must be an array of strings — not a flat string
        foreach ($response->json('errors') as $field => $messages) {
            $this->assertIsArray($messages, "Errors for field '{$field}' must be an array");
            foreach ($messages as $message) {
                $this->assertIsString($message);
            }
        }
    }

    public function test_api_404_returns_error_envelope(): void
    {
        $response = $this->getJson('/api/test/items/missing/record');

        $response->assertNotFound();
        $response->assertJson([
            'status' => 'error',
            'status_code' => 404,
            'data' => null,
        ]);
        $this->assertNotEmpty($response->json('message'));
    }

    public function test_api_500_returns_generic_error_envelope_without_leaking_details(): void
    {
        config(['app.debug' => false]);

        $response = $this->getJson('/api/test/items/broken/server');

        $response->assertStatus(500);
        $response->assertJson([
            'status' => 'error',
            'status_code' => 500,
            'message' => 'An unexpected error occurred.',
            'data' => null,
        ]);
        $this->assertArrayNotHasKey('debug', $response->json());
    }

    // ─── Layer 2: SdkConnector behaviour with mocked HTTP ─────────

    /**
     * Build a real SdkConnector pointed at a Guzzle mock handler.
     * The connector file is generated — we eval() it here so we test the
     * actual generated code, not a hand-written stub.
     */
    private function makeConnector(array $responses): mixed
    {
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $guzzle = new Client(['handler' => $handlerStack]);

        // Load generated classes directly from the generators so we exercise the
        // actual generated code rather than hand-written stubs.
        // Exceptions must be loaded before the connector (connector references them by name).
        $generator = new \SchemaCraft\Generator\Sdk\SdkGenerator;

        if (! class_exists('TestSdk\SdkRequestException')) {
            $code = $generator->generateRequestException('TestSdk');
            $stripped = preg_replace('/^<\?php\s+namespace\s+\S+;\s+/s', '', $code);
            eval('namespace TestSdk; '.$stripped);
        }

        if (! class_exists('TestSdk\SdkValidationException')) {
            $code = $generator->generateValidationException('TestSdk');
            $stripped = preg_replace('/^<\?php\s+namespace\s+\S+;\s+/s', '', $code);
            eval('namespace TestSdk; '.$stripped);
        }

        if (! class_exists('TestSdk\SdkConnector')) {
            $connectorCode = (new \SchemaCraft\Generator\Sdk\SdkConnectorGenerator)->generate('TestSdk');
            $stripped = preg_replace('/^<\?php\s+namespace\s+\S+;\s+/s', '', $connectorCode);
            eval('namespace TestSdk; '.$stripped);
        }

        return new \TestSdk\SdkConnector('https://api.example.com', 'test-token', $guzzle);
    }

    public function test_connector_get_returns_parsed_json(): void
    {
        $body = json_encode([
            'status' => 'success',
            'status_code' => 200,
            'message' => 'Success.',
            'data' => [
                ['id' => 1, 'name' => 'Alpha'],
                ['id' => 2, 'name' => 'Beta'],
            ],
        ]);

        $connector = $this->makeConnector([
            new Response(200, ['Content-Type' => 'application/json'], $body),
        ]);

        $result = $connector->get('/items');

        $this->assertIsArray($result);
        $this->assertSame('success', $result['status']);
        $this->assertCount(2, $result['data']);
    }

    public function test_connector_post_sends_json_body_and_returns_response(): void
    {
        $body = json_encode([
            'status' => 'success',
            'status_code' => 200,
            'message' => 'Success.',
            'data' => ['id' => 3, 'name' => 'Gamma'],
        ]);

        $connector = $this->makeConnector([
            new Response(200, ['Content-Type' => 'application/json'], $body),
        ]);

        $result = $connector->post('/items', ['name' => 'Gamma']);

        $this->assertIsArray($result);
        $this->assertSame('success', $result['status']);
        $this->assertSame(3, $result['data']['id']);
    }

    public function test_connector_422_throws_sdk_validation_exception(): void
    {
        $body = json_encode([
            'status' => 'error',
            'status_code' => 422,
            'message' => 'The given data was invalid.',
            'errors' => ['name' => ['The name field is required.']],
            'data' => null,
        ]);

        $connector = $this->makeConnector([
            new Response(422, ['Content-Type' => 'application/json'], $body),
        ]);

        try {
            $connector->post('/items', []);
            $this->fail('Expected SdkValidationException was not thrown.');
        } catch (\TestSdk\SdkValidationException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('The given data was invalid.', $e->getMessage());
            $this->assertSame(['name' => ['The name field is required.']], $e->getErrors());
        }
    }

    public function test_connector_non_422_error_throws_sdk_request_exception(): void
    {
        $body = json_encode([
            'status' => 'error',
            'status_code' => 404,
            'message' => 'Record not found.',
            'errors' => [],
            'data' => null,
        ]);

        $connector = $this->makeConnector([
            new Response(404, ['Content-Type' => 'application/json'], $body),
        ]);

        try {
            $connector->get('/items/99999');
            $this->fail('Expected SdkRequestException was not thrown.');
        } catch (\TestSdk\SdkValidationException $e) {
            $this->fail('Should not throw SdkValidationException for a 404.');
        } catch (\TestSdk\SdkRequestException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('Record not found.', $e->getMessage());
        }
    }

    public function test_connector_500_throws_sdk_request_exception(): void
    {
        $body = json_encode([
            'status' => 'error',
            'status_code' => 500,
            'message' => 'An unexpected error occurred.',
            'errors' => [],
            'data' => null,
        ]);

        $connector = $this->makeConnector([
            new Response(500, ['Content-Type' => 'application/json'], $body),
        ]);

        try {
            $connector->get('/items');
            $this->fail('Expected SdkRequestException was not thrown.');
        } catch (\TestSdk\SdkRequestException $e) {
            $this->assertSame(500, $e->getStatusCode());
            $this->assertSame('An unexpected error occurred.', $e->getMessage());
        }
    }

    public function test_sdk_validation_exception_is_catchable_as_sdk_request_exception(): void
    {
        $body = json_encode([
            'status' => 'error',
            'status_code' => 422,
            'message' => 'The given data was invalid.',
            'errors' => ['email' => ['Invalid email.']],
            'data' => null,
        ]);

        $connector = $this->makeConnector([
            new Response(422, ['Content-Type' => 'application/json'], $body),
        ]);

        // Catching the parent class must catch the child
        try {
            $connector->post('/items', []);
            $this->fail('Expected exception was not thrown.');
        } catch (\TestSdk\SdkRequestException $e) {
            $this->assertInstanceOf(\TestSdk\SdkValidationException::class, $e);
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_connector_delete_fires_request(): void
    {
        $connector = $this->makeConnector([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'status' => 'success',
                'status_code' => 200,
                'message' => 'Success.',
                'data' => null,
            ])),
        ]);

        // delete() returns void — just assert it doesn't throw
        $connector->delete('/items/1');

        $this->assertTrue(true);
    }

    // ─── Layer 3: response envelope ↔ SDK data contract ───────────

    /**
     * Verify that the SDK's data-unwrapping pattern ($response['data']) matches
     * what the API actually puts in 'data'.
     *
     * The SdkResourceGenerator always reads $response['data'] for the payload.
     * If the middleware changes that key name, the SDK breaks silently.
     */
    public function test_api_success_data_is_under_data_key_not_root(): void
    {
        $response = $this->getJson('/api/test/items/1');

        $body = $response->json();

        // Top-level keys the SDK expects
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('status', $body);

        // The actual record must be inside 'data', not at root
        $this->assertArrayNotHasKey('id', $body, 'Record fields must not appear at root level');
        $this->assertArrayHasKey('id', $body['data'], 'Record fields must be inside data key');
    }

    public function test_api_list_data_is_flat_array_not_nested_under_data_again(): void
    {
        $response = $this->getJson('/api/test/items');

        $data = $response->json('data');

        // Must be a plain array of items, not {'data': [...]}
        $this->assertIsArray($data);
        $this->assertArrayNotHasKey('data', $data, 'data key must not be double-wrapped');
        $this->assertArrayHasKey(0, $data);
    }
}
