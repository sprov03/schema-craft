<?php

namespace SchemaCraft\Tests\Fixtures\Types;

use SchemaCraft\DataSchema;

/**
 * Typed item shape for a `price_history` JSON-array column. The DataSchema IS the item
 * shape declaration; the column property is typed as Collection + #[CollectionOf(TestPricePoint::class)],
 * and the schema scanner wires the Collection primitive (SchemaCraft\Primitives\Collection) automatically.
 */
class TestPricePoint extends DataSchema
{
    public string $changed_at;

    public float $amount;
}
