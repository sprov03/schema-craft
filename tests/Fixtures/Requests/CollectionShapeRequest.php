<?php

namespace SchemaCraft\Tests\Fixtures\Requests;

use SchemaCraft\Request;
use SchemaCraft\Tests\Fixtures\Types\TestPriceHistory;

/**
 * Request with a typed collection-of-shapes property (TestPriceHistory is a CollectionColumn
 * of TestPricePoint). Exercises the collection cascade: the property hydrates to a real
 * CollectionColumn instance of item shapes, and validation emits `.*`-indexed item rules.
 */
class CollectionShapeRequest extends Request
{
    public string $label;

    public TestPriceHistory $price_history;
}
