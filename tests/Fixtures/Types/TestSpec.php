<?php

namespace SchemaCraft\Tests\Fixtures\Types;

use SchemaCraft\DataSchema;

/**
 * Typed object shape for a `spec` JSON column. The DataSchema IS the column's shape
 * declaration — no wrapper class. The schema scanner detects properties typed as a
 * DataSchema subclass and wires the DataSchema-as-column-type pattern automatically.
 */
class TestSpec extends DataSchema
{
    public string $sku;

    public int $weight;

    public ?string $material;
}
