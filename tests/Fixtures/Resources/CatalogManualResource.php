<?php

namespace SchemaCraft\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A plain JsonResource (NOT a SchemaCraftResource) with a hand-written toArray().
 * The scanner flags this as a manual resource (responseManualResource): its shape
 * is opaque, so it contributes NO resourceFields. An endpoint documented with this
 * resource therefore still emits an SDK method, but no response DTO is derived from it.
 */
class CatalogManualResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'computed_total' => 42,
        ];
    }
}
