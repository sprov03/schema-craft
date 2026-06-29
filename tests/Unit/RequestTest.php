<?php

namespace SchemaCraft\Tests\Unit;

use Illuminate\Http\Request as HttpRequest;
use Illuminate\Validation\ValidationException;
use SchemaCraft\Tests\Fixtures\Requests\CollectionShapeRequest;
use SchemaCraft\Tests\Fixtures\Requests\NestedShapeRequest;
use SchemaCraft\Tests\Fixtures\Requests\SearchPostsRequest;
use SchemaCraft\Tests\Fixtures\Requests\ValidationLinkedRequest;
use SchemaCraft\Tests\Fixtures\Types\CatalogAttributes;
use SchemaCraft\Tests\Fixtures\Types\TestPriceHistory;
use SchemaCraft\Tests\Fixtures\Types\TestPricePoint;
use SchemaCraft\Tests\TestCase;

class RequestTest extends TestCase
{
    // ─── Hydration (inherited from DataSchema) ────────────────────────────

    public function test_request_hydrates_typed_properties_from_array(): void
    {
        $req = SearchPostsRequest::fromArray(['search' => 'hello', 'page' => 3, 'status' => 'active']);

        $this->assertSame('hello', $req->search);
        $this->assertSame(3, $req->page);
        $this->assertSame('active', $req->status);
    }

    // ─── Self-standing validation from own primitives ─────────────────────

    public function test_rules_derive_from_own_primitives_and_own_rules_attribute(): void
    {
        $rules = (new SearchPostsRequest)->rules();

        $this->assertArrayHasKey('search', $rules);
        $this->assertContains('nullable', $rules['search']);
        $this->assertContains('max:50', $rules['search']);          // from #[Length(50)]
        $this->assertContains('in:active,inactive', $rules['status']); // own #[Rules]
    }

    // ─── fromRequest: validate then hydrate ───────────────────────────────

    public function test_from_request_validates_then_hydrates(): void
    {
        $http = HttpRequest::create('/', 'POST', ['search' => 'x', 'page' => 2, 'status' => 'active']);

        $req = SearchPostsRequest::fromRequest($http);

        $this->assertSame('x', $req->search);
        $this->assertSame(2, $req->page);
        $this->assertSame('active', $req->status);
    }

    public function test_from_request_throws_on_invalid_input(): void
    {
        $this->expectException(ValidationException::class);

        $http = HttpRequest::create('/', 'POST', ['status' => 'not-a-valid-option', 'page' => 1]);
        SearchPostsRequest::fromRequest($http);
    }

    // ─── Intersection-only schema enrichment ──────────────────────────────

    public function test_linked_schema_enriches_only_shared_columns_with_custom_rules(): void
    {
        $rules = (new ValidationLinkedRequest)->rules();

        // title overlaps the schema → schema's #[Rules('min:3')] is appended
        $this->assertContains('min:3', $rules['title']);

        // nullability is ALWAYS the request's: request declares ?string → nullable, never required
        $this->assertContains('nullable', $rules['title']);
        $this->assertNotContains('required', $rules['title']);

        // request-only property keeps its own rules
        $this->assertArrayHasKey('note', $rules);

        // schema-only columns are NOT pulled in — the request is the authority on shape
        $this->assertArrayNotHasKey('slug', $rules);
        $this->assertArrayNotHasKey('author', $rules);
    }

    // ─── Nested shape (DataSchema) properties ─────────────────────────────

    public function test_nested_shape_property_validates_as_array_with_dotted_rules(): void
    {
        $rules = (new NestedShapeRequest)->rules();

        // The nested object is validated as an array — NOT mis-typed as a scalar string.
        $this->assertContains('array', $rules['attributes']);
        $this->assertNotContains('string', $rules['attributes']);

        // Inner fields get their own dot-notation rules (reused from DataSchema::validationRules).
        $this->assertArrayHasKey('attributes.color', $rules);
        $this->assertArrayHasKey('attributes.weight_grams', $rules);
        $this->assertContains('integer', $rules['attributes.weight_grams']);
    }

    public function test_from_request_accepts_and_hydrates_valid_nested_payload(): void
    {
        $http = HttpRequest::create('/', 'POST', [
            'label' => 'x',
            'attributes' => ['color' => 'red', 'material' => 'steel', 'weight_grams' => 9],
        ]);

        $req = NestedShapeRequest::fromRequest($http);

        $this->assertSame('x', $req->label);
        $this->assertInstanceOf(CatalogAttributes::class, $req->attributes);
        $this->assertSame('red', $req->attributes->color);
        $this->assertSame(9, $req->attributes->weight_grams);
    }

    public function test_from_request_rejects_invalid_nested_field(): void
    {
        $this->expectException(ValidationException::class);

        $http = HttpRequest::create('/', 'POST', [
            'label' => 'x',
            'attributes' => ['weight_grams' => 'not-an-int'],
        ]);
        NestedShapeRequest::fromRequest($http);
    }

    // ─── Collection-of-shapes hydration (Step 1: CollectionColumn as CastsDataSchemaProperty) ──

    public function test_collection_column_property_hydrates_to_instance_and_round_trips(): void
    {
        // Before CollectionColumn declared CastsDataSchemaProperty, fromArray() assigned the
        // raw array to the typed property and threw a TypeError. It must now hydrate items.
        $req = CollectionShapeRequest::fromArray([
            'label' => 'history',
            'price_history' => [
                ['changed_at' => '2026-01-01', 'amount' => 9.5],
                ['changed_at' => '2026-02-01', 'amount' => 12.0],
            ],
        ]);

        $this->assertInstanceOf(TestPriceHistory::class, $req->price_history);
        $this->assertCount(2, $req->price_history);
        $this->assertInstanceOf(TestPricePoint::class, $req->price_history->first());
        $this->assertSame(9.5, $req->price_history->first()->amount);

        // toArray() round-trips back through the item shapes' toRaw().
        $this->assertSame('2026-02-01', $req->toArray()['price_history'][1]['changed_at']);
    }

    // ─── Collection-of-shapes validation cascade (Step 3) ─────────────────

    public function test_collection_property_cascades_dotted_item_rules(): void
    {
        $rules = (new CollectionShapeRequest)->rules();

        // Container validates as an array (not mis-typed as a scalar string).
        $this->assertContains('array', $rules['price_history']);
        $this->assertNotContains('string', $rules['price_history']);

        // Item fields cascade under the `.*` wildcard from TestPricePoint::validationRules().
        $this->assertArrayHasKey('price_history.*.changed_at', $rules);
        $this->assertArrayHasKey('price_history.*.amount', $rules);
        $this->assertContains('numeric', $rules['price_history.*.amount']);
    }

    public function test_from_request_accepts_valid_collection_and_rejects_invalid_item(): void
    {
        $valid = HttpRequest::create('/', 'POST', [
            'label' => 'h',
            'price_history' => [['changed_at' => '2026-01-01', 'amount' => 5.0]],
        ]);
        $req = CollectionShapeRequest::fromRequest($valid);
        $this->assertCount(1, $req->price_history);

        $this->expectException(ValidationException::class);
        $invalid = HttpRequest::create('/', 'POST', [
            'label' => 'h',
            'price_history' => [['changed_at' => '2026-01-01', 'amount' => 'not-a-number']],
        ]);
        CollectionShapeRequest::fromRequest($invalid);
    }
}
