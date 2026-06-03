<?php

namespace SchemaCraft\Tests\Fixtures\Resources;

use Illuminate\Database\Eloquent\Collection;
use SchemaCraft\Attributes\CollectionOf;
use SchemaCraft\Attributes\Resources\Computed;
use SchemaCraft\Attributes\Resources\ResourceSchema;
use SchemaCraft\SchemaCraftResource;
use SchemaCraft\Tests\Fixtures\Enums\CatalogTier;
use SchemaCraft\Tests\Fixtures\Enums\PostStatus;
use SchemaCraft\Tests\Fixtures\Schemas\CatalogSchema;
use SchemaCraft\Tests\Fixtures\Types\CatalogAttributes;
use SchemaCraft\Tests\Fixtures\Types\TestBitmask;
use SchemaCraft\Tests\Fixtures\Types\TestPriceHistory;
use SchemaCraft\Tests\Fixtures\Types\TestSpec;

/**
 * Documented response resource for CatalogController. Because at least one
 * controller endpoint resolves this resource, the Catalog DTO is generated via
 * the resource-driven path (SdkDataGenerator::generateFromFields) using exactly
 * the fields declared here — NOT the raw CatalogSchema columns.
 *
 * Property PHP types map to SDK DTO types via SdkDataGenerator::resourcePhpType:
 *   int/string/bool/float/array               -> themselves
 *   PostStatus (string-backed enum)            -> string (backing type)
 *   CatalogTier (int-backed enum)              -> int    (backing type)
 *   TestBitmask    (SchemaCraftColumn) -> TestBitmaskData   (typed {value, flags} object)
 *   TestJsonDto    (SchemaCraftColumn) -> TestSpecData      (typed object, backing DataSchema)
 *   TestPriceHistory (SchemaCraftColumn) -> TestPricePointData[] (typed collection)
 */
#[ResourceSchema(CatalogSchema::class)]
class CatalogResource extends SchemaCraftResource
{
    public int $id;

    public string $name;

    public ?string $subtitle;

    public bool $is_active;

    public float $price;

    public CatalogAttributes $attributes_json; // DataSchema-typed -> SDK emits CatalogAttributesData

    public PostStatus $status;       // string-backed enum -> string

    public CatalogTier $tier;        // int-backed enum -> int

    public TestBitmask $permissions; // Bitmask primitive -> TestBitmaskData

    public TestSpec $spec;           // DataSchema-typed -> SDK emits TestSpecData

    public TestPriceHistory $price_history; // Collection primitive subclass — SDK emits TestPricePointData[]

    public ?CatalogBrandResource $brand;

    #[CollectionOf(CatalogVariantResource::class)]
    public Collection $variants;

    public ?CatalogShipmentResource $shipment;

    // Reviews surface CatalogReview via the Resource path so the dependency walker doesn't
    // fall back to the schema-driven morphMany (which would emit a `mixed` reviewable).
    #[CollectionOf(CatalogReviewResource::class)]
    public Collection $reviews;

    /**
     * Computed field — the method name is snake_cased for the JSON/DTO key
     * (displayLabel -> display_label) and its return type drives the DTO type.
     */
    #[Computed]
    public function displayLabel(): string
    {
        return $this->name;
    }
}
