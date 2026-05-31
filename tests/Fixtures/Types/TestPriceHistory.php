<?php

namespace SchemaCraft\Tests\Fixtures\Types;

use SchemaCraft\DataSchema;
use SchemaCraft\Types\AbstractCollectionType;

/**
 * Collection-type fixture for the SDK golden test.
 *
 * Modelled on the AbstractCollectionType docblock: a JSON-array column whose
 * items are typed DataSchema instances. The SDK reflects its item DataSchema
 * (TestPricePoint) via sdkShape() and emits a typed TestPricePointData[] — the
 * collection-of-typed-DTO branch of the column-type matrix (distinct from the
 * bitmask synthesized-object branch and the JSON-DTO backing-DataSchema branch).
 */
class TestPriceHistory extends AbstractCollectionType
{
    protected static function itemClass(): string
    {
        return TestPricePoint::class;
    }
}

/**
 * The item shape stored in each TestPriceHistory entry.
 */
class TestPricePoint extends DataSchema
{
    public string $changed_at;

    public float $amount;
}
