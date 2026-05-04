<?php

namespace SchemaCraft\Tests\Feature;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use SchemaCraft\Exceptions\DataSchemaHydrationException;
use SchemaCraft\Http\Middleware\ApiResponseMiddleware;
use SchemaCraft\Tests\TestCase;

class ApiResponseMiddlewareTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['env'] = 'local';
        $app['config']->set('app.debug', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Register test routes with the middleware applied
        Route::middleware(ApiResponseMiddleware::class)->group(function () {

            Route::get('/test/success-json', fn () => new JsonResponse(['id' => 1, 'name' => 'Test']));

            Route::get('/test/success-resource-wrapped', fn () => new JsonResponse(['data' => ['id' => 1]]));

            Route::get('/test/success-null', fn () => null);

            Route::get('/test/success-delete', fn () => new JsonResponse(null, 204));

            Route::get('/test/error-validation', function () {
                throw ValidationException::withMessages(['name' => ['The name field is required.']]);
            });

            Route::get('/test/error-not-found', function () {
                throw new ModelNotFoundException;
            });

            Route::get('/test/error-hydration', function () {
                throw DataSchemaHydrationException::missingRequiredField('App\Schemas\PostSchema', 'title');
            });

            Route::get('/test/error-server', function () {
                throw new \RuntimeException('Something went boom.');
            });

            Route::get('/test/passthrough-html', fn () => response('<html>hi</html>', 200, ['Content-Type' => 'text/html']));
        });
    }

    // ─── Success responses ────────────────────────────────────────

    public function test_wraps_json_response_in_success_envelope(): void
    {
        $response = $this->getJson('/test/success-json');

        $response->assertOk();
        $response->assertJson([
            'status' => 'success',
            'status_code' => 200,
            'message' => 'Success.',
            'data' => ['id' => 1, 'name' => 'Test'],
        ]);
    }

    public function test_unwraps_laravel_resource_data_key_before_wrapping(): void
    {
        // JsonResource serialises as {"data": {...}} — the middleware must strip
        // that outer key so we don't end up with {"data": {"data": {...}}}.
        $response = $this->getJson('/test/success-resource-wrapped');

        $response->assertOk();
        $response->assertJson([
            'status' => 'success',
            'data' => ['id' => 1],
        ]);
        $this->assertArrayNotHasKey('data', $response->json('data'));
    }

    public function test_wraps_null_closure_return_as_success_with_null_data(): void
    {
        $response = $this->getJson('/test/success-null');

        $response->assertOk();
        $response->assertJson([
            'status' => 'success',
            'data' => null,
        ]);
    }

    public function test_converts_204_delete_to_200_success_envelope(): void
    {
        $response = $this->getJson('/test/success-delete');

        $response->assertOk();
        $response->assertJson([
            'status' => 'success',
            'status_code' => 200,
            'data' => null,
        ]);
    }

    // ─── Error responses ──────────────────────────────────────────

    public function test_formats_validation_exception_as_422_envelope(): void
    {
        $response = $this->getJson('/test/error-validation');

        $response->assertUnprocessable();
        $response->assertJson([
            'status' => 'error',
            'status_code' => 422,
            'message' => 'The given data was invalid.',
            'errors' => ['name' => ['The name field is required.']],
            'data' => null,
        ]);
    }

    public function test_formats_model_not_found_as_404_envelope(): void
    {
        $response = $this->getJson('/test/error-not-found');

        $response->assertNotFound();
        $response->assertJson([
            'status' => 'error',
            'status_code' => 404,
            'message' => 'Record not found.',
            'data' => null,
        ]);
    }

    public function test_formats_hydration_exception_as_422_envelope(): void
    {
        $response = $this->getJson('/test/error-hydration');

        $response->assertUnprocessable();
        $response->assertJson([
            'status' => 'error',
            'status_code' => 422,
            'data' => null,
        ]);
    }

    public function test_formats_unexpected_exception_as_500_envelope(): void
    {
        $response = $this->getJson('/test/error-server');

        $response->assertStatus(500);
        $response->assertJson([
            'status' => 'error',
            'status_code' => 500,
            'message' => 'An unexpected error occurred.',
            'data' => null,
        ]);
        // Debug block must not appear when APP_DEBUG is false
        $this->assertArrayNotHasKey('debug', $response->json());
    }

    public function test_500_includes_debug_info_when_debug_enabled(): void
    {
        config(['app.debug' => true]);

        $response = $this->getJson('/test/error-server');

        $response->assertStatus(500);
        $this->assertArrayHasKey('debug', $response->json());
        $this->assertSame('RuntimeException', $response->json('debug.exception'));
        $this->assertSame('Something went boom.', $response->json('message'));
    }

    // ─── Pass-through ─────────────────────────────────────────────

    public function test_passes_non_json_content_through_unchanged(): void
    {
        $response = $this->get('/test/passthrough-html');

        $response->assertOk();
        $this->assertStringContainsString('<html>hi</html>', $response->getContent());
    }
}
