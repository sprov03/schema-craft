<?php

namespace SchemaCraft\Tests\Fixtures\Types;

use SchemaCraft\DataSchema;

/**
 * Typed object shape for an address column. A bare DataSchema — the schema scanner
 * detects DataSchema-typed properties and wires the DataSchema-as-column-type pattern automatically.
 * No SchemaCraftColumn / CastsAttributes boilerplate on the shape class itself.
 */
class TestAddressDto extends DataSchema
{
    public string $street;

    public ?string $line2;

    public string $city;

    public string $state;

    public int $zip;
}
