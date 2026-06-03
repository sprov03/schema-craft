<?php

namespace SchemaCraft\Tests\Fixtures\Types;

use SchemaCraft\Primitives\JsonColumn;

/**
 * Typed JSON column for a `spec` column. Extends the JsonColumn primitive so the class
 * identity carries the "this is a DB JSON column" role.
 */
class TestSpec extends JsonColumn
{
    public string $sku;

    public int $weight;

    public ?string $material;
}
