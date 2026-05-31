<?php

namespace SchemaCraft\Tests\Fixtures\Api;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Route;
use SchemaCraft\Attributes\Api\ApiResponse;
use SchemaCraft\Tests\Fixtures\Api\Requests\CreateCatalogRequest;
use SchemaCraft\Tests\Fixtures\Api\Requests\UpdateCatalogRequest;
use SchemaCraft\Tests\Fixtures\Resources\ActionResultResource;
use SchemaCraft\Tests\Fixtures\Resources\CatalogManualResource;
use SchemaCraft\Tests\Fixtures\Resources\CatalogResource;

/**
 * Comprehensive controller fixture for the SDK golden test.
 *
 * RuntimeRouteScanner maps this controller to CatalogSchema by name
 * (CatalogController -> CatalogSchema), and each method below exercises a
 * distinct SDK endpoint/response scenario. Method bodies are intentionally
 * empty — the SDK generator only reads route + return-type + attribute metadata.
 *
 * Route order matters: getCollection is registered first, and the CLI takes the
 * FIRST endpoint whose resource resolves as the source for the Catalog DTO. So
 * CatalogData is driven by CatalogResource via getCollection.
 */
class CatalogController
{
    public static function apiRoutes(): void
    {
        // Documented collection response (#[ApiResponse collection]) — drives CatalogData.
        Route::get('catalog', [self::class, 'getCollection']);

        // Documented single response via a typed JsonResource return hint.
        Route::get('catalog/{catalog}', [self::class, 'get']);

        // Create with body (FormRequest), documented single response.
        Route::post('catalog', [self::class, 'create']);

        // Update with body (FormRequest), documented single response.
        Route::put('catalog/{catalog}', [self::class, 'update']);

        // DELETE returning a structured ActionResultData (documented via #[ApiResponse]).
        Route::delete('catalog/{catalog}', [self::class, 'delete']);

        // Custom action returning a structured ActionResultData (documented).
        Route::post('catalog/{catalog}/archive', [self::class, 'archive']);

        // Undocumented custom action (no return type, no #[ApiResponse]).
        Route::get('catalog/{catalog}/report', [self::class, 'report']);

        // Manual JsonResource (opaque toArray) — documented-but-not-introspectable.
        Route::get('catalog/{catalog}/manual', [self::class, 'manual']);
    }

    /**
     * List all catalog entries.
     */
    #[ApiResponse(CatalogResource::class, collection: true)]
    public function getCollection(): AnonymousResourceCollection {}

    /**
     * Fetch a single catalog entry.
     */
    public function get(): CatalogResource {}

    /**
     * Create a catalog entry.
     */
    #[ApiResponse(CatalogResource::class)]
    public function create(CreateCatalogRequest $request): CatalogResource {}

    /**
     * Update a catalog entry.
     */
    #[ApiResponse(CatalogResource::class)]
    public function update(UpdateCatalogRequest $request): CatalogResource {}

    /**
     * Delete a catalog entry — returns a structured result (no longer void).
     */
    #[ApiResponse(ActionResultResource::class)]
    public function delete() {}

    /**
     * Archive a catalog entry — custom action returning a structured result.
     */
    #[ApiResponse(ActionResultResource::class)]
    public function archive() {}

    /**
     * Generate an ad-hoc report (undocumented response) — left bare on purpose so the
     * golden test still exercises the filter+warn path (report is excluded from the SDK).
     */
    public function report() {}

    /**
     * Manual resource response — opaque to the scanner.
     */
    public function manual(): CatalogManualResource {}
}
