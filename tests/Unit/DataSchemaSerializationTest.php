<?php

namespace Tests\Unit;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use PHPUnit\Framework\TestCase;
use SchemaCraft\Contracts\CastsDataSchemaProperty;
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

enum StatusTestEnum: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Pending = 'pending';
}

enum PriorityTestEnum: int
{
    case Low = 1;
    case Medium = 2;
    case High = 3;
}

class TestBitmask implements CastsDataSchemaProperty
{
    public function __construct(
        public readonly int $value = 0,
    ) {}

    public static function fromRaw(mixed $value): static
    {
        return new static((int) $value);
    }

    public function toRaw(): mixed
    {
        return $this->value;
    }

    public function hasFlag(int $flag): bool
    {
        return ($this->value & $flag) === $flag;
    }
}

class DateTimeDataSchema extends DataSchema
{
    public ?CarbonInterface $createdAt = null;

    public ?CarbonInterface $updatedAt = null;
}

class EnumDataSchema extends DataSchema
{
    public ?StatusTestEnum $status = null;

    public ?PriorityTestEnum $priority = null;
}

class CastsPropertyDataSchema extends DataSchema
{
    public ?TestBitmask $flags = null;
}

class MixedTypesDataSchema extends DataSchema
{
    public string $name = '';

    public ?CarbonInterface $scheduledAt = null;

    public ?StatusTestEnum $status = null;

    public ?TestBitmask $permissions = null;

    public int $count = 0;
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

    // ── DateTimeInterface (Carbon) ─────────────────

    public function test_from_array_with_datetime_string_hydrates_to_carbon(): void
    {
        $dto = DateTimeDataSchema::fromArray([
            'createdAt' => '2026-03-31T12:00:00Z',
            'updatedAt' => '2026-01-15 08:30:00',
        ]);

        $this->assertInstanceOf(CarbonInterface::class, $dto->createdAt);
        $this->assertInstanceOf(CarbonInterface::class, $dto->updatedAt);
        $this->assertSame('2026-03-31', $dto->createdAt->format('Y-m-d'));
        $this->assertSame('2026-01-15', $dto->updatedAt->format('Y-m-d'));
    }

    public function test_from_array_with_nullable_datetime_as_null(): void
    {
        $dto = DateTimeDataSchema::fromArray([
            'createdAt' => null,
            'updatedAt' => null,
        ]);

        $this->assertNull($dto->createdAt);
        $this->assertNull($dto->updatedAt);
    }

    public function test_from_array_with_datetime_missing_uses_default(): void
    {
        $dto = DateTimeDataSchema::fromArray([]);

        $this->assertNull($dto->createdAt);
        $this->assertNull($dto->updatedAt);
    }

    public function test_to_array_with_datetime_serializes_to_iso8601(): void
    {
        $dto = DateTimeDataSchema::fromArray([
            'createdAt' => '2026-03-31T12:00:00+00:00',
        ]);

        $result = $dto->toArray();

        $this->assertIsString($result['createdAt']);
        $this->assertStringContainsString('2026-03-31', $result['createdAt']);
        $this->assertNull($result['updatedAt']);
    }

    public function test_datetime_round_trip(): void
    {
        $original = [
            'createdAt' => '2026-03-31T12:00:00+00:00',
            'updatedAt' => null,
        ];

        $dto = DateTimeDataSchema::fromArray($original);
        $result = $dto->toArray();

        // Re-hydrate from the serialized output to verify it round-trips
        $dto2 = DateTimeDataSchema::fromArray($result);
        $this->assertSame($dto->createdAt->toIso8601String(), $dto2->createdAt->toIso8601String());
        $this->assertNull($dto2->updatedAt);
    }

    // ── BackedEnum ─────────────────────────────────

    public function test_from_array_with_string_backed_enum(): void
    {
        $dto = EnumDataSchema::fromArray([
            'status' => 'active',
            'priority' => 2,
        ]);

        $this->assertSame(StatusTestEnum::Active, $dto->status);
        $this->assertSame(PriorityTestEnum::Medium, $dto->priority);
    }

    public function test_from_array_with_nullable_enum_as_null(): void
    {
        $dto = EnumDataSchema::fromArray([
            'status' => null,
            'priority' => null,
        ]);

        $this->assertNull($dto->status);
        $this->assertNull($dto->priority);
    }

    public function test_from_array_with_enum_missing_uses_default(): void
    {
        $dto = EnumDataSchema::fromArray([]);

        $this->assertNull($dto->status);
        $this->assertNull($dto->priority);
    }

    public function test_to_array_with_enums_serializes_to_value(): void
    {
        $dto = EnumDataSchema::fromArray([
            'status' => 'pending',
            'priority' => 3,
        ]);

        $result = $dto->toArray();

        $this->assertSame('pending', $result['status']);
        $this->assertSame(3, $result['priority']);
    }

    public function test_enum_round_trip(): void
    {
        $original = [
            'status' => 'inactive',
            'priority' => 1,
        ];

        $dto = EnumDataSchema::fromArray($original);
        $result = $dto->toArray();

        $this->assertSame($original, $result);
    }

    // ── CastsDataSchemaProperty ────────────────────

    public function test_from_array_with_casts_property(): void
    {
        $dto = CastsPropertyDataSchema::fromArray([
            'flags' => 5,
        ]);

        $this->assertInstanceOf(TestBitmask::class, $dto->flags);
        $this->assertSame(5, $dto->flags->value);
        $this->assertTrue($dto->flags->hasFlag(1));
        $this->assertTrue($dto->flags->hasFlag(4));
        $this->assertFalse($dto->flags->hasFlag(2));
    }

    public function test_from_array_with_nullable_casts_property_as_null(): void
    {
        $dto = CastsPropertyDataSchema::fromArray([
            'flags' => null,
        ]);

        $this->assertNull($dto->flags);
    }

    public function test_to_array_with_casts_property_serializes_to_raw(): void
    {
        $dto = CastsPropertyDataSchema::fromArray([
            'flags' => 7,
        ]);

        $result = $dto->toArray();

        $this->assertSame(7, $result['flags']);
    }

    public function test_casts_property_round_trip(): void
    {
        $original = ['flags' => 5];

        $dto = CastsPropertyDataSchema::fromArray($original);
        $result = $dto->toArray();

        $this->assertSame($original, $result);
    }

    // ── Mixed types ────────────────────────────────

    public function test_from_array_with_mixed_types(): void
    {
        $dto = MixedTypesDataSchema::fromArray([
            'name' => 'Test Event',
            'scheduledAt' => '2026-06-15T09:00:00+00:00',
            'status' => 'active',
            'permissions' => 3,
            'count' => 42,
        ]);

        $this->assertSame('Test Event', $dto->name);
        $this->assertInstanceOf(CarbonInterface::class, $dto->scheduledAt);
        $this->assertSame(StatusTestEnum::Active, $dto->status);
        $this->assertInstanceOf(TestBitmask::class, $dto->permissions);
        $this->assertSame(3, $dto->permissions->value);
        $this->assertSame(42, $dto->count);
    }

    public function test_to_array_with_mixed_types_serializes_all(): void
    {
        $dto = MixedTypesDataSchema::fromArray([
            'name' => 'Test Event',
            'scheduledAt' => '2026-06-15T09:00:00+00:00',
            'status' => 'active',
            'permissions' => 3,
            'count' => 42,
        ]);

        $result = $dto->toArray();

        $this->assertSame('Test Event', $result['name']);
        $this->assertIsString($result['scheduledAt']);
        $this->assertStringContainsString('2026-06-15', $result['scheduledAt']);
        $this->assertSame('active', $result['status']);
        $this->assertSame(3, $result['permissions']);
        $this->assertSame(42, $result['count']);
    }

    public function test_mixed_types_with_all_null(): void
    {
        $dto = MixedTypesDataSchema::fromArray([]);

        $this->assertSame('', $dto->name);
        $this->assertNull($dto->scheduledAt);
        $this->assertNull($dto->status);
        $this->assertNull($dto->permissions);
        $this->assertSame(0, $dto->count);
    }
}
