<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Attributes\CollectionOf;
use SchemaCraft\DataSchema;
use SchemaCraft\Scanner\TypedPropertyReflector;
use SchemaCraft\Tests\Fixtures\Types\TestPriceHistory;
use SchemaCraft\Tests\Fixtures\Types\TestPricePoint;

/**
 * The shared typed-property parser: one detection for DataSchema (requests/actions) and
 * SchemaCraftResource (responses), parameterized by the shape base class. Covers scalar /
 * nested-shape / both collection mechanisms / enum / datetime, plus the inherit-from-parents
 * policy (and that framework ancestor props like JsonResource::$resource never leak in).
 */
class TypedPropertyReflectorTest extends TestCase
{
    private function byName(array $descriptors): array
    {
        $out = [];
        foreach ($descriptors as $d) {
            $out[$d['name']] = $d;
        }

        return $out;
    }

    public function test_classifies_scalar_nested_shape_and_collection_column(): void
    {
        $d = $this->byName(TypedPropertyReflector::scan(ReflectorChildShape::class, DataSchema::class));

        // scalar
        $this->assertSame('string', $d['title']['typeName']);
        $this->assertFalse($d['title']['isNestedShape']);
        $this->assertFalse($d['title']['isCollection']);

        // nested shape (a DataSchema-typed property)
        $this->assertTrue($d['point']['isNestedShape']);
        $this->assertSame(TestPricePoint::class, $d['point']['nestedShapeClass']);

        // collection via a CollectionColumn-typed property → item from ::itemClass()
        $this->assertTrue($d['history']['isCollection']);
        $this->assertSame(TestPricePoint::class, $d['history']['collectionItemClass']);
    }

    public function test_collection_via_property_level_attribute(): void
    {
        $d = $this->byName(TypedPropertyReflector::scan(ReflectorAttrCollectionShape::class, DataSchema::class));

        $this->assertTrue($d['items']['isCollection']);
        $this->assertSame(TestPricePoint::class, $d['items']['collectionItemClass']);
    }

    public function test_inherits_parent_properties_but_not_base_or_framework_props(): void
    {
        $d = $this->byName(TypedPropertyReflector::scan(ReflectorChildShape::class, DataSchema::class));

        // declared on the parent shape — inherited
        $this->assertArrayHasKey('inherited_id', $d);
        $this->assertSame('int', $d['inherited_id']['typeName']);

        // declared on the concrete class
        $this->assertArrayHasKey('title', $d);
    }
}

abstract class ReflectorBaseShape extends DataSchema
{
    public int $inherited_id;
}

class ReflectorChildShape extends ReflectorBaseShape
{
    public string $title;

    public TestPricePoint $point;

    public TestPriceHistory $history;
}

class ReflectorAttrCollectionShape extends DataSchema
{
    #[CollectionOf(TestPricePoint::class)]
    public array $items;
}
