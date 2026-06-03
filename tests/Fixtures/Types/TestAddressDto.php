<?php

namespace SchemaCraft\Tests\Fixtures\Types;

use SchemaCraft\Primitives\JsonColumn;

/**
 * Typed JSON column for an address column. Extends the JsonColumn primitive so the
 * class identity signals "this is a DB JSON column" at every import site.
 */
class TestAddressDto extends JsonColumn
{
    public string $street;

    public ?string $line2;

    public string $city;

    public string $state;

    public int $zip;
}
