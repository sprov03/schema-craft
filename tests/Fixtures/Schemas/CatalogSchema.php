<?php

namespace SchemaCraft\Tests\Fixtures\Schemas;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\Date;
use SchemaCraft\Attributes\Decimal;
use SchemaCraft\Attributes\Fillable;
use SchemaCraft\Attributes\Hidden;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Attributes\Relations\BelongsTo;
use SchemaCraft\Attributes\Relations\BelongsToMany;
use SchemaCraft\Attributes\Relations\HasManyThrough;
use SchemaCraft\Attributes\Relations\HasMany;
use SchemaCraft\Attributes\Relations\HasOne;
use SchemaCraft\Attributes\Relations\MorphMany;
use SchemaCraft\Attributes\Relations\MorphOne;
use SchemaCraft\Attributes\Relations\MorphTo;
use SchemaCraft\Attributes\Text;
use SchemaCraft\Schema;
use SchemaCraft\Tests\Fixtures\Enums\CatalogTier;
use SchemaCraft\Tests\Fixtures\Enums\PostStatus;
use SchemaCraft\Tests\Fixtures\Models\CatalogBrand;
use SchemaCraft\Tests\Fixtures\Models\CatalogReview;
use SchemaCraft\Tests\Fixtures\Models\CatalogShipment;
use SchemaCraft\Tests\Fixtures\Models\CatalogSupplier;
use SchemaCraft\Tests\Fixtures\Models\CatalogVariant;
use SchemaCraft\Tests\Fixtures\Types\CatalogAttributes;
use SchemaCraft\Tests\Fixtures\Types\TestBitmask;
use SchemaCraft\Tests\Fixtures\Types\TestPriceHistory;
use SchemaCraft\Tests\Fixtures\Types\TestSpec;
use SchemaCraft\Traits\SoftDeletesSchema;
use SchemaCraft\Traits\TimestampsSchema;

/**
 * Primary schema for the SDK golden test. The DTO for Catalog itself is generated
 * from CatalogResource (the resource-driven generateFromFields path), so the columns
 * below primarily drive the create/update body-param scenarios and the dependency
 * relationship tree — the resource decides the response DTO shape.
 *
 * Covers the full schema-side column-type and relationship matrix in one place.
 */
class CatalogSchema extends Schema
{
    use SoftDeletesSchema;
    use TimestampsSchema;

    // ─── Column types ────────────────────────────────────────────
    #[Primary]
    #[AutoIncrement]
    public int $id;                       // int

    #[Fillable]
    public string $name;                  // string

    #[Fillable]
    public ?string $subtitle;             // nullable string

    #[Fillable]
    #[Text]
    public ?string $description;          // text

    #[Fillable]
    public bool $is_active = true;        // bool

    #[Fillable]
    #[Decimal(10, 2)]
    public float $price;                  // decimal/float

    #[Fillable]
    public CatalogAttributes $attributes_json; // DataSchema-typed property -> DataSchema-as-column-type pattern, SDK emits CatalogAttributesData

    #[Fillable]
    #[Date]
    public ?CarbonInterface $released_on; // date -> string

    public ?CarbonInterface $reviewed_at; // datetime -> string

    #[Fillable]
    public PostStatus $status = PostStatus::Draft; // string-backed enum

    #[Fillable]
    public CatalogTier $tier = CatalogTier::Standard; // int-backed enum

    #[Fillable]
    public TestBitmask $permissions;      // bitmask type -> typed TestBitmaskData

    #[Fillable]
    public TestSpec $spec;                // DataSchema-typed property -> SDK emits TestSpecData

    #[Fillable]
    public TestPriceHistory $price_history;  // Collection primitive subclass — SDK emits TestPricePointData[]

    #[Hidden]
    public array $internal_notes = [];    // #[Hidden] -> excluded everywhere

    // ─── Relationships (schema side) ─────────────────────────────
    #[Fillable]
    #[BelongsTo(CatalogBrand::class)]
    public CatalogBrand $brand;           // belongsTo -> FK int column catalog_brand_id

    /** @var Collection<int, CatalogVariant> */
    #[HasMany(CatalogVariant::class)]
    public Collection $variants;          // hasMany -> dependency DTO

    #[HasOne(CatalogShipment::class)]
    public CatalogShipment $shipment;     // hasOne -> dependency DTO

    /** @var Collection<int, CatalogSupplier> */
    #[BelongsToMany(CatalogSupplier::class)]
    public Collection $suppliers;         // belongsToMany -> dependency DTO

    /** @var Collection<int, CatalogReview> */
    #[MorphMany(CatalogReview::class, 'reviewable')]
    public Collection $reviews;           // morphMany -> dependency DTO

    #[MorphOne(CatalogReview::class, 'reviewable')]
    public CatalogReview $featuredReview; // morphOne -> dependency DTO (reuses CatalogReview)

    #[MorphTo('owner')]
    public Model $owner;                  // morphTo -> mixed, no dependency

    /** @var Collection<int, CatalogSupplier> */
    #[HasManyThrough(CatalogSupplier::class, CatalogBrand::class)]
    public Collection $brandSuppliers;    // hasManyThrough -> NOT walked as dependency
}
