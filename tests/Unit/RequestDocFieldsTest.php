<?php

namespace SchemaCraft\Tests\Unit;

use Illuminate\Support\Collection;
use SchemaCraft\Generator\Sdk\EndpointEnricher;
use SchemaCraft\Tests\Fixtures\Requests\CollectionShapeRequest;
use SchemaCraft\Tests\Fixtures\Requests\NestedShapeRequest;
use SchemaCraft\Tests\Fixtures\Requests\SearchPostsRequest;
use SchemaCraft\Tests\Fixtures\Types\TestPricePoint;
use SchemaCraft\Tests\TestCase;

/**
 * The request "walker" — documents a Request's own structure + validation rules at
 * every level, with no schema involved (the mirror of buildResponseFieldsFromResource).
 */
class RequestDocFieldsTest extends TestCase
{
    public function test_returns_null_for_non_request_class(): void
    {
        $this->assertNull((new EndpointEnricher)->buildRequestFieldsFromRequest(\stdClass::class));
    }

    public function test_documents_flat_request_fields_with_rules(): void
    {
        $fields = (new EndpointEnricher)->buildRequestFieldsFromRequest(SearchPostsRequest::class)['fields'];
        $byName = (new Collection($fields))->keyBy('name');

        $this->assertContains('max:50', $byName['search']['rules']);   // from #[Length(50)]
        $this->assertTrue($byName['search']['nullable']);
        $this->assertContains('in:active,inactive', $byName['status']['rules']); // own #[Rules]
    }

    public function test_documents_nested_shape_structure_and_rules_at_each_level(): void
    {
        $fields = (new EndpointEnricher)->buildRequestFieldsFromRequest(NestedShapeRequest::class)['fields'];
        $byName = (new Collection($fields))->keyBy('name');

        // top level
        $this->assertSame('string', $byName['label']['type']);
        $this->assertContains('required', $byName['label']['rules']);

        // the nested shape is documented as a structure, not a scalar
        $attributes = $byName['attributes'];
        $this->assertContains('array', $attributes['rules']);
        $this->assertArrayHasKey('children', $attributes);

        // inner fields carry their own rules — structure + rules at each level
        $children = (new Collection($attributes['children']))->keyBy('name');
        $this->assertArrayHasKey('color', $children);
        $this->assertContains('integer', $children['weight_grams']['rules']);
    }

    // ─── Step 2: collection metadata on descriptors ───────────────────────

    public function test_descriptor_exposes_collection_column_and_item_class(): void
    {
        $byName = (new Collection(CollectionShapeRequest::fieldDescriptors()))->keyBy('name');

        $this->assertTrue($byName['price_history']['isCollectionColumn']);
        $this->assertSame(TestPricePoint::class, $byName['price_history']['collectionItemClass']);

        // a non-collection property reports false / null, not the collection metadata.
        $this->assertFalse($byName['label']['isCollectionColumn']);
        $this->assertNull($byName['label']['collectionItemClass']);
    }

    // ─── Step 5: SDK request walker documents collection item children ────

    public function test_documents_collection_property_with_item_children(): void
    {
        $fields = (new EndpointEnricher)->buildRequestFieldsFromRequest(CollectionShapeRequest::class)['fields'];
        $byName = (new Collection($fields))->keyBy('name');

        $priceHistory = $byName['price_history'];
        $this->assertTrue($priceHistory['isCollection']);
        $this->assertArrayHasKey('children', $priceHistory);

        // item fields are documented as children with their cascaded `.*` rules.
        $children = (new Collection($priceHistory['children']))->keyBy('name');
        $this->assertArrayHasKey('changed_at', $children);
        $this->assertContains('numeric', $children['amount']['rules']);
    }
}
