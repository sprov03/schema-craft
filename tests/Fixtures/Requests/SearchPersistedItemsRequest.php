<?php

namespace SchemaCraft\Tests\Fixtures\Requests;

use SchemaCraft\Request;
use SchemaCraft\Tests\Fixtures\Schemas\PersistedItemSchema;

/**
 * Custom query input for the live demo endpoint (POST persisted-items/search).
 *
 * Schema-linked (so the linked-schema path is exercised at runtime), but every
 * filter is optional — it's a search. Typed + validated + hydrated by the Request
 * base, so the controller reads $filters->name with autocomplete instead of poking
 * a loose request array.
 */
class SearchPersistedItemsRequest extends Request
{
    protected static string $schema = PersistedItemSchema::class;

    public ?string $name;

    public ?bool $is_active;

    public ?float $max_price;
}
