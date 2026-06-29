<?php

namespace SchemaCraft\Tests\Fixtures\Api;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Route;
use SchemaCraft\Attributes\Api\ApiResponse;
use SchemaCraft\Tests\Fixtures\Models\PersistedItem;
use SchemaCraft\Tests\Fixtures\Requests\SearchPersistedItemsRequest;
use SchemaCraft\Tests\Fixtures\Resources\PersistedItemResource;

/**
 * Real CRUD controller for the full-stack persistence test.
 *
 * Unlike CatalogController (which has empty method bodies and exists only as a
 * generator-metadata fixture), this controller actually persists to the DB so
 * SDK → HTTP → DB round-trips can be exercised end-to-end.
 *
 * RuntimeRouteScanner maps PersistedItemController → PersistedItemSchema by name.
 */
class PersistedItemController
{
    public static function apiRoutes(): void
    {
        Route::get('persisted-items', [self::class, 'getCollection']);
        Route::post('persisted-items/search', [self::class, 'search']);
        Route::get('persisted-items/{id}', [self::class, 'get']);
        Route::post('persisted-items', [self::class, 'create']);
        Route::put('persisted-items/{id}', [self::class, 'update']);
        Route::delete('persisted-items/{id}', [self::class, 'delete']);
    }

    #[ApiResponse(PersistedItemResource::class, collection: true)]
    public function getCollection(): AnonymousResourceCollection
    {
        return PersistedItemResource::collection(PersistedItem::all());
    }

    /**
     * Custom query endpoint backed by a schema-craft Request.
     *
     * $filters is INJECTED already validated + hydrated — the container builds it from
     * the incoming request, validates against the Request's own rules(), and hydrates
     * the typed properties (exactly how a FormRequest auto-validates on resolution).
     * So the filters read with autocomplete; no manual fromRequest() call. Each optional
     * filter maps to a conditional where() (the when/guard idiom): a REQUIRED request
     * property would instead be an unconditional where().
     */
    #[ApiResponse(PersistedItemResource::class, collection: true)]
    public function search(SearchPersistedItemsRequest $filters): AnonymousResourceCollection
    {
        $items = PersistedItem::query()
            ->when($filters->name !== null, fn ($q) => $q->where('name', 'like', '%'.$filters->name.'%'))
            ->when($filters->is_active !== null, fn ($q) => $q->where('is_active', $filters->is_active))
            ->when($filters->max_price !== null, fn ($q) => $q->where('price', '<=', $filters->max_price))
            ->get();

        return PersistedItemResource::collection($items);
    }

    public function get(int $id): PersistedItemResource
    {
        return new PersistedItemResource(PersistedItem::findOrFail($id));
    }

    public function create(\Illuminate\Http\Request $request): PersistedItemResource
    {
        $item = new PersistedItem;
        $item->fill($request->all());
        $item->save();

        return new PersistedItemResource($item->fresh());
    }

    public function update(int $id, \Illuminate\Http\Request $request): PersistedItemResource
    {
        $item = PersistedItem::findOrFail($id);
        $item->fill($request->all());
        $item->save();

        return new PersistedItemResource($item->fresh());
    }

    #[ApiResponse(\SchemaCraft\Tests\Fixtures\Resources\ActionResultResource::class)]
    public function delete(int $id): \SchemaCraft\Tests\Fixtures\Resources\ActionResultResource
    {
        PersistedItem::findOrFail($id)->delete();

        return new \SchemaCraft\Tests\Fixtures\Resources\ActionResultResource(new \SchemaCraft\Tests\Fixtures\Schemas\ActionResultSchema);
    }
}
