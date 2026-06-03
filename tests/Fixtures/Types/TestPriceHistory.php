<?php

namespace SchemaCraft\Tests\Fixtures\Types;

use SchemaCraft\Attributes\CollectionOf;
use SchemaCraft\Primitives\CollectionColumn;

/**
 * Typed collection fixture for the SDK golden test.
 *
 * Extends SchemaCraft\Primitives\CollectionColumn — class identity carries the cast, schema
 * declaration, and SDK shape introspection. The class-level #[CollectionOf] points at the
 * item DataSchema (TestPricePoint).
 */
#[CollectionOf(TestPricePoint::class)]
class TestPriceHistory extends CollectionColumn
{
}
