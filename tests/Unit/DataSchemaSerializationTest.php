<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\DataSchema;
use SchemaCraft\Exceptions\DataSchemaHydrationException;

// ── Test Fixtures ────────────────────────────────────

class AddressDataSchema extends DataSchema
{
    public string $street = '';

    public ?string $line2 = null;

    public string $city = '';

    public string $state = '';

    public int $zip = 0;
}

class ContactInfoDataSchema extends DataSchema
{
    public string $name;

    public ?string $email = null;

    public AddressDataSchema $address;

    public ?AddressDataSchema $mailingAddress = null;
}

class SimpleDefaultsDataSchema extends DataSchema
{
    public ?bool $active = false;

    public ?string $label = 'default label';

    public int $count = 0;

    public float $rate = 0.0;
}

class NonNullableNoDefaultDataSchema extends DataSchema
{
    public string $requiredField;

    public int $requiredInt;
}

// ── Tests ────────────────────────────────────────────

class DataSchemaSerializationTest extends TestCase
{
    // ── fromArray ────────────────────────────────────

    public function test_from_array_with_null_returns_defaults(): void
    {
        $dto = SimpleDefaultsDataSchema::fromArray(null);

        $this->assertInstanceOf(SimpleDefaultsDataSchema::class, $dto);
        $this->assertFalse($dto->active);
        $this->assertSame('default label', $dto->label);
        $this->assertSame(0, $dto->count);
        $this->assertSame(0.0, $dto->rate);
    }

    public function test_from_array_with_empty_array_returns_defaults(): void
    {
        $dto = SimpleDefaultsDataSchema::fromArray([]);

        $this->assertFalse($dto->active);
        $this->assertSame('default label', $dto->label);
        $this->assertSame(0, $dto->count);
    }

    public function test_from_array_with_full_data(): void
    {
        $dto = SimpleDefaultsDataSchema::fromArray([
            'active' => true,
            'label' => 'custom',
            'count' => 42,
            'rate' => 3.14,
        ]);

        $this->assertTrue($dto->active);
        $this->assertSame('custom', $dto->label);
        $this->assertSame(42, $dto->count);
        $this->assertSame(3.14, $dto->rate);
    }

    public function test_from_array_with_partial_data_uses_defaults(): void
    {
        $dto = SimpleDefaultsDataSchema::fromArray([
            'active' => true,
        ]);

        $this->assertTrue($dto->active);
        $this->assertSame('default label', $dto->label);
        $this->assertSame(0, $dto->count);
        $this->assertSame(0.0, $dto->rate);
    }

    public function test_from_array_casts_types(): void
    {
        $dto = SimpleDefaultsDataSchema::fromArray([
            'active' => 1,         // int → bool
            'label' => 123,        // int → string
            'count' => '42',       // string → int
            'rate' => '3.14',      // string → float
        ]);

        $this->assertTrue($dto->active);
        $this->assertSame('123', $dto->label);
        $this->assertSame(42, $dto->count);
        $this->assertSame(3.14, $dto->rate);
    }

    public function test_from_array_nullable_property_accepts_null(): void
    {
        $dto = SimpleDefaultsDataSchema::fromArray([
            'active' => null,
            'label' => null,
        ]);

        $this->assertNull($dto->active);
        $this->assertNull($dto->label);
    }

    public function test_from_array_non_nullable_no_default_throws_exception(): void
    {
        $this->expectException(DataSchemaHydrationException::class);
        $this->expectExceptionMessage('requiredField');

        NonNullableNoDefaultDataSchema::fromArray([]);
    }

    public function test_from_array_null_with_non_nullable_no_default_throws_exception(): void
    {
        $this->expectException(DataSchemaHydrationException::class);

        NonNullableNoDefaultDataSchema::fromArray(null);
    }

    public function test_from_array_non_nullable_no_default_with_data(): void
    {
        $dto = NonNullableNoDefaultDataSchema::fromArray([
            'requiredField' => 'hello',
            'requiredInt' => 5,
        ]);

        $this->assertSame('hello', $dto->requiredField);
        $this->assertSame(5, $dto->requiredInt);
    }

    // ── Nested DataSchema ───────────────────────────

    public function test_from_array_with_nested_data_schema(): void
    {
        $dto = ContactInfoDataSchema::fromArray([
            'name' => 'John',
            'email' => 'john@example.com',
            'address' => [
                'street' => '123 Main St',
                'city' => 'Springfield',
                'state' => 'IL',
                'zip' => 62701,
            ],
        ]);

        $this->assertSame('John', $dto->name);
        $this->assertSame('john@example.com', $dto->email);
        $this->assertInstanceOf(AddressDataSchema::class, $dto->address);
        $this->assertSame('123 Main St', $dto->address->street);
        $this->assertSame('Springfield', $dto->address->city);
        $this->assertSame('IL', $dto->address->state);
        $this->assertSame(62701, $dto->address->zip);
        $this->assertNull($dto->address->line2);
    }

    public function test_from_array_with_nullable_nested_data_schema_as_null(): void
    {
        $dto = ContactInfoDataSchema::fromArray([
            'name' => 'Jane',
            'address' => ['street' => '456 Oak', 'city' => 'Austin', 'state' => 'TX', 'zip' => 73301],
            'mailingAddress' => null,
        ]);

        $this->assertNull($dto->mailingAddress);
    }

    public function test_from_array_with_nullable_nested_data_schema_as_object(): void
    {
        $dto = ContactInfoDataSchema::fromArray([
            'name' => 'Jane',
            'address' => ['street' => '456 Oak', 'city' => 'Austin', 'state' => 'TX', 'zip' => 73301],
            'mailingAddress' => ['street' => '789 Elm', 'city' => 'Dallas', 'state' => 'TX', 'zip' => 75201],
        ]);

        $this->assertInstanceOf(AddressDataSchema::class, $dto->mailingAddress);
        $this->assertSame('789 Elm', $dto->mailingAddress->street);
    }

    public function test_from_array_nested_missing_data_uses_defaults(): void
    {
        $dto = ContactInfoDataSchema::fromArray([
            'name' => 'Bob',
        ]);

        // Non-nullable nested DataSchema — recursively created with defaults
        $this->assertInstanceOf(AddressDataSchema::class, $dto->address);
        $this->assertSame('', $dto->address->street);
        $this->assertSame('', $dto->address->city);
        // Nullable nested DataSchema — null
        $this->assertNull($dto->mailingAddress);
    }

    // ── toArray ─────────────────────────────────────

    public function test_to_array_with_simple_data(): void
    {
        $dto = SimpleDefaultsDataSchema::fromArray([
            'active' => true,
            'label' => 'test',
            'count' => 10,
            'rate' => 1.5,
        ]);

        $this->assertSame([
            'active' => true,
            'label' => 'test',
            'count' => 10,
            'rate' => 1.5,
        ], $dto->toArray());
    }

    public function test_to_array_with_defaults(): void
    {
        $dto = SimpleDefaultsDataSchema::fromArray(null);

        $this->assertSame([
            'active' => false,
            'label' => 'default label',
            'count' => 0,
            'rate' => 0.0,
        ], $dto->toArray());
    }

    public function test_to_array_with_nested_data_schema(): void
    {
        $dto = ContactInfoDataSchema::fromArray([
            'name' => 'John',
            'email' => null,
            'address' => ['street' => '123 Main', 'city' => 'Springfield', 'state' => 'IL', 'zip' => 62701],
            'mailingAddress' => null,
        ]);

        $result = $dto->toArray();

        $this->assertSame('John', $result['name']);
        $this->assertNull($result['email']);
        $this->assertIsArray($result['address']);
        $this->assertSame('123 Main', $result['address']['street']);
        $this->assertNull($result['mailingAddress']);
    }

    // ── Round-trip ──────────────────────────────────

    public function test_round_trip_from_array_to_array_preserves_data(): void
    {
        $original = [
            'active' => true,
            'label' => 'round-trip',
            'count' => 99,
            'rate' => 2.718,
        ];

        $dto = SimpleDefaultsDataSchema::fromArray($original);
        $result = $dto->toArray();

        $this->assertSame($original, $result);
    }

    public function test_round_trip_nested_from_array_to_array(): void
    {
        $original = [
            'name' => 'Alice',
            'email' => 'alice@test.com',
            'address' => [
                'street' => '100 Pine St',
                'line2' => 'Apt 4',
                'city' => 'Portland',
                'state' => 'OR',
                'zip' => 97201,
            ],
            'mailingAddress' => [
                'street' => '200 Oak Ave',
                'line2' => null,
                'city' => 'Seattle',
                'state' => 'WA',
                'zip' => 98101,
            ],
        ];

        $dto = ContactInfoDataSchema::fromArray($original);
        $result = $dto->toArray();

        $this->assertSame($original, $result);
    }

    // ── toJson / JsonSerializable ───────────────────

    public function test_to_json_returns_json_string(): void
    {
        $dto = SimpleDefaultsDataSchema::fromArray(['active' => true, 'label' => 'json', 'count' => 1, 'rate' => 0.5]);
        $json = $dto->toJson();

        $this->assertJson($json);
        $decoded = json_decode($json, true);
        $this->assertSame('json', $decoded['label']);
    }

    public function test_json_serializable_works_with_json_encode(): void
    {
        $dto = SimpleDefaultsDataSchema::fromArray(['active' => false, 'label' => 'serialize', 'count' => 0, 'rate' => 0.0]);
        $json = json_encode($dto);

        $this->assertJson($json);
        $decoded = json_decode($json, true);
        $this->assertSame('serialize', $decoded['label']);
    }
}
