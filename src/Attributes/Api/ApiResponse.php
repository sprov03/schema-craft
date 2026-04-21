<?php

namespace SchemaCraft\Attributes\Api;

use Attribute;

/**
 * Declares the response resource for a controller method, enabling full
 * response field documentation in the SchemaCraft API docs panel.
 *
 * Apply to any controller method that returns a SchemaCraftResource.
 * The resource class is reflected at runtime to extract typed properties,
 * relationships, and computed fields — the same way action-based endpoints
 * document their response shape.
 *
 * Example:
 *   #[ApiResponse(AsteriskDidResource::class, collection: true)]
 *   public function getAvailableDids(Request $request): AnonymousResourceCollection
 *
 *   #[ApiResponse(AsteriskDidResource::class)]
 *   public function show(Request $request, AsteriskDid $did): AsteriskDidResource
 */
#[Attribute(Attribute::TARGET_METHOD)]
class ApiResponse
{
    public function __construct(
        /** The SchemaCraftResource class returned by this method. */
        public string $resourceClass,
        /** True when the method returns a collection (ResourceClass[]). */
        public bool $collection = false,
    ) {}
}
