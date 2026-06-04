<?php

namespace SchemaCraft\Tests\Fixtures\Schemas;

use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\Fillable;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Schema;
use SchemaCraft\Tests\Fixtures\Enums\PostStatus;
use SchemaCraft\Tests\Fixtures\Types\CatalogAttributes;
use SchemaCraft\Tests\Fixtures\Types\TestBitmask;
use SchemaCraft\Tests\Fixtures\Types\TestPriceHistory;

/**
 * Single-table fixture for the full-stack persistence test.
 *
 * Intentionally small: no relationships, just one of each new column primitive
 * (JsonColumn / BitmaskColumn / CollectionColumn) plus scalars and an enum.
 * The test confirms that EVERY cast primitive round-trips through Eloquent's
 * cast pipeline and an actual DB without value drift.
 */
class PersistedItemSchema extends Schema
{
    #[Primary]
    #[AutoIncrement]
    public int $id;

    #[Fillable]
    public string $name;

    #[Fillable]
    public bool $is_active = true;

    #[Fillable]
    public ?float $price;

    #[Fillable]
    public PostStatus $status = PostStatus::Draft;

    // JsonColumn cast (new primitive after the refactor).
    // Named `_json` to dodge Eloquent's internal `$attributes` bag, which collides with
    // typed-property access on the model.
    #[Fillable]
    public CatalogAttributes $attributes_json;

    // BitmaskColumn cast (was Bitmask before the rename).
    #[Fillable]
    public TestBitmask $permissions;

    // CollectionColumn cast (was Collection before the rename).
    #[Fillable]
    public TestPriceHistory $price_history;
}
