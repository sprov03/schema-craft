<?php

namespace SchemaCraft\Tests\Fixtures\Resources;

use SchemaCraft\Attributes\Resources\ResourceSchema;
use SchemaCraft\SchemaCraftResource;
use SchemaCraft\Tests\Fixtures\Enums\PostStatus;
use SchemaCraft\Tests\Fixtures\Schemas\PersistedItemSchema;
use SchemaCraft\Tests\Fixtures\Types\CatalogAttributes;
use SchemaCraft\Tests\Fixtures\Types\TestBitmask;
use SchemaCraft\Tests\Fixtures\Types\TestPriceHistory;

#[ResourceSchema(PersistedItemSchema::class)]
class PersistedItemResource extends SchemaCraftResource
{
    public int $id;

    public string $name;

    public bool $is_active;

    public ?float $price;

    public PostStatus $status;

    public CatalogAttributes $attributes_json;

    public TestBitmask $permissions;

    public TestPriceHistory $price_history;
}
