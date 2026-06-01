<?php

namespace SchemaCraft\Tests\Unit;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SchemaCraft\Generator\FakerMethodMapper;
use SchemaCraft\Generators\GeneratorColumn;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Tests\Fixtures\Types\TestBitmask;
use SchemaCraft\Primitives\Bitmask;
use SchemaCraft\Types\AbstractCollectionType;
use SchemaCraft\Types\AbstractJsonDtoType;

/**
 * Concrete TestBitmask with known flags for testing.
 */
class SampleBitmask extends \SchemaCraft\Primitives\LargeBitmask
{
    const LOAN = 1;
    const PURCHASE = 2;
    const REFINANCE = 4;
}

/**
 * Object shape backing SampleJsonDto.
 */
class SampleSpec extends \SchemaCraft\DataSchema
{
    public ?string $key = null;
}

/**
 * Concrete TestJsonDto for testing.
 *
 * Declares dtoClass() to satisfy the DataSchema-backed contract, but keeps its
 * free-form array round-trip (the legacy behaviour these tests assert) by
 * overriding fromArray()/toArray() — the base treats those as defaults, not final.
 */
class SampleJsonDto extends AbstractJsonDtoType
{
    private array $data;

    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    protected static function dtoClass(): string
    {
        return SampleSpec::class;
    }

    public static function fromArray(array $data): static
    {
        return new static($data);
    }

    public function toArray(): array
    {
        return $this->data;
    }
}

/**
 * Item shape for SampleCollection tests.
 */
class SampleItem extends \SchemaCraft\DataSchema
{
    public int $id;
}

/**
 * Concrete TestCollectionType for testing.
 */
class SampleCollection extends AbstractCollectionType
{
    protected static function itemClass(): string
    {
        return SampleItem::class;
    }
}

/**
 * A custom Eloquent cast that implements CastsAttributes but NOT SchemaCraftColumn.
 * Used to verify that the enforcement throws rather than silently falling back.
 */
class NonConformingCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value;
    }
}

class SchemaCraftColumnTest extends TestCase
{
    // ─── Bitmask primitive: API representation ────────────────────

    public function test_bitmask_to_api_representation_includes_value_and_flags(): void
    {
        $mask = new SampleBitmask(3); // LOAN | PURCHASE

        $result = $mask->toApiRepresentation();

        $this->assertSame(3, $result['value']);
        $this->assertTrue($result['flags']['LOAN']);
        $this->assertTrue($result['flags']['PURCHASE']);
        $this->assertFalse($result['flags']['REFINANCE']);
    }

    public function test_bitmask_to_api_representation_zero_value_all_false(): void
    {
        $mask = new SampleBitmask(0);
        $result = $mask->toApiRepresentation();

        $this->assertSame(0, $result['value']);
        $this->assertFalse($result['flags']['LOAN']);
        $this->assertFalse($result['flags']['PURCHASE']);
        $this->assertFalse($result['flags']['REFINANCE']);
    }

    public function test_bitmask_from_api_input_compiles_flags_to_integer(): void
    {
        $mask = SampleBitmask::fromApiInput(['LOAN' => true, 'PURCHASE' => true, 'REFINANCE' => false]);

        $this->assertSame(3, $mask->getValue());
    }

    public function test_bitmask_from_api_input_all_flags_true(): void
    {
        $mask = SampleBitmask::fromApiInput(['LOAN' => true, 'PURCHASE' => true, 'REFINANCE' => true]);

        $this->assertSame(7, $mask->getValue());
    }

    public function test_bitmask_from_api_input_no_flags_set(): void
    {
        $mask = SampleBitmask::fromApiInput(['LOAN' => false, 'PURCHASE' => false, 'REFINANCE' => false]);

        $this->assertSame(0, $mask->getValue());
    }

    public function test_bitmask_from_api_input_rejects_non_array(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/array of flag names/');

        SampleBitmask::fromApiInput(6);
    }

    public function test_bitmask_from_api_input_rejects_unknown_flag(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown flag/');

        SampleBitmask::fromApiInput(['UNKNOWN_FLAG' => true]);
    }

    public function test_bitmask_json_serialize_matches_api_representation(): void
    {
        $mask = new SampleBitmask(5); // LOAN | REFINANCE

        $this->assertSame($mask->toApiRepresentation(), $mask->jsonSerialize());
    }

    public function test_bitmask_round_trip_through_api(): void
    {
        $original = new SampleBitmask(5); // LOAN | REFINANCE
        $apiOutput = $original->toApiRepresentation();
        $reconstructed = SampleBitmask::fromApiInput($apiOutput['flags']);

        $this->assertSame($original->getValue(), $reconstructed->getValue());
    }

    // ─── Bitmask primitive: helpers ──────────────────────────────

    public function test_bitmask_has_flag(): void
    {
        $mask = new SampleBitmask(3); // LOAN | PURCHASE

        $this->assertTrue($mask->hasFlag(SampleBitmask::LOAN));
        $this->assertTrue($mask->hasFlag(SampleBitmask::PURCHASE));
        $this->assertFalse($mask->hasFlag(SampleBitmask::REFINANCE));
    }

    public function test_bitmask_set_flag(): void
    {
        $mask = (new SampleBitmask(0))->setFlag(SampleBitmask::LOAN);

        $this->assertSame(1, $mask->getValue());
    }

    public function test_bitmask_unset_flag(): void
    {
        $mask = (new SampleBitmask(3))->unsetFlag(SampleBitmask::LOAN);

        $this->assertSame(2, $mask->getValue());
        $this->assertFalse($mask->hasFlag(SampleBitmask::LOAN));
        $this->assertTrue($mask->hasFlag(SampleBitmask::PURCHASE));
    }

    public function test_bitmask_from_raw(): void
    {
        $mask = SampleBitmask::fromRaw(6);

        $this->assertSame(6, $mask->getValue());
        $this->assertFalse($mask->hasFlag(SampleBitmask::LOAN));
        $this->assertTrue($mask->hasFlag(SampleBitmask::PURCHASE));
        $this->assertTrue($mask->hasFlag(SampleBitmask::REFINANCE));
    }

    public function test_bitmask_to_raw(): void
    {
        $mask = new SampleBitmask(5);

        $this->assertSame(5, $mask->toRaw());
    }

    public function test_bitmask_schema_column_type(): void
    {
        $this->assertSame('integer', SampleBitmask::schemaColumnType());
    }

    public function test_bitmask_sdk_type(): void
    {
        $this->assertSame('array', SampleBitmask::sdkType());
    }

    public function test_bitmask_faker_expression(): void
    {
        $column = new ColumnDefinition(name: 'permissions', columnType: 'integer');
        $expr = SampleBitmask::fakerExpression($column);

        $this->assertStringContainsString('numberBetween', $expr);
        $this->assertStringContainsString('0', $expr);
        $this->assertStringContainsString('7', $expr); // sum of all flags
    }

    // ─── AbstractJsonDtoType ─────────────────────────────────────

    public function test_json_dto_from_api_input_returns_instance(): void
    {
        $dto = SampleJsonDto::fromApiInput(['key' => 'value']);

        $this->assertInstanceOf(SampleJsonDto::class, $dto);
        $this->assertSame(['key' => 'value'], $dto->toArray());
    }

    public function test_json_dto_from_api_input_rejects_non_array(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SampleJsonDto::fromApiInput('not-an-array');
    }

    public function test_json_dto_to_api_representation_matches_to_array(): void
    {
        $dto = SampleJsonDto::fromArray(['a' => 1, 'b' => 2]);

        $this->assertSame($dto->toArray(), $dto->toApiRepresentation());
    }

    public function test_json_dto_schema_column_type_is_json(): void
    {
        $this->assertSame('json', SampleJsonDto::schemaColumnType());
    }

    public function test_json_dto_sdk_type_is_array(): void
    {
        $this->assertSame('array', SampleJsonDto::sdkType());
    }

    // ─── AbstractCollectionType ───────────────────────────────────

    public function test_collection_from_api_input(): void
    {
        $col = SampleCollection::fromApiInput([['id' => 1], ['id' => 2]]);

        $this->assertSame(2, $col->count());
    }

    public function test_collection_from_api_input_rejects_non_array(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SampleCollection::fromApiInput('bad');
    }

    public function test_collection_to_api_representation(): void
    {
        $col = SampleCollection::fromApiInput([['id' => 1]]);

        $this->assertSame([['id' => 1]], $col->toApiRepresentation());
    }

    public function test_collection_items_are_typed_instances(): void
    {
        $col = SampleCollection::fromApiInput([['id' => 1], ['id' => 2]]);

        $this->assertInstanceOf(SampleItem::class, $col->first());
        $this->assertSame(1, $col->first()->id);
    }

    public function test_collection_supports_push_and_collection_api(): void
    {
        $col = SampleCollection::fromApiInput([['id' => 1]]);
        $col->push(SampleItem::fromArray(['id' => 2]));

        $this->assertSame(2, $col->count());
        $this->assertSame(2, $col->last()->id);
    }

    public function test_collection_from_raw_hydrates_typed_items(): void
    {
        $col = SampleCollection::fromRaw(json_encode([['id' => 5]]));

        $this->assertInstanceOf(SampleItem::class, $col->first());
        $this->assertSame(5, $col->first()->id);
    }

    public function test_collection_sdk_type_is_array(): void
    {
        $this->assertSame('array', SampleCollection::sdkType());
    }

    // ─── Enforcement: GeneratorColumn ────────────────────────────

    public function test_generator_column_throws_for_non_conforming_cast_class(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(
            name: 'field',
            columnType: 'json',
            castType: NonConformingCast::class,
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must implement SchemaCraftColumn/');

        $col->asFilamentField();
    }

    public function test_generator_column_throws_on_filament_column_for_non_conforming_cast(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(
            name: 'field',
            columnType: 'json',
            castType: NonConformingCast::class,
        ));

        $this->expectException(RuntimeException::class);

        $col->asFilamentColumn();
    }

    public function test_generator_column_throws_on_filament_entry_for_non_conforming_cast(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(
            name: 'field',
            columnType: 'json',
            castType: NonConformingCast::class,
        ));

        $this->expectException(RuntimeException::class);

        $col->asFilamentEntry();
    }

    public function test_generator_column_throws_on_faker_for_non_conforming_cast(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(
            name: 'field',
            columnType: 'json',
            castType: NonConformingCast::class,
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must implement SchemaCraftColumn/');

        $col->fakerValue();
    }

    public function test_generator_column_delegates_faker_to_schema_craft_column(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(
            name: 'permissions',
            columnType: 'integer',
            castType: SampleBitmask::class,
        ));

        $expr = $col->fakerValue();

        $this->assertStringContainsString('numberBetween', $expr);
    }

    // ─── Enforcement: FakerMethodMapper ──────────────────────────

    public function test_faker_mapper_throws_for_non_conforming_cast_class(): void
    {
        $mapper = new FakerMethodMapper;
        $column = new ColumnDefinition(
            name: 'field',
            columnType: 'json',
            castType: NonConformingCast::class,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must implement SchemaCraftColumn/');

        $mapper->map($column);
    }

    public function test_faker_mapper_delegates_to_schema_craft_column(): void
    {
        $mapper = new FakerMethodMapper;
        $column = new ColumnDefinition(
            name: 'permissions',
            columnType: 'integer',
            castType: SampleBitmask::class,
        );

        $expr = $mapper->map($column);

        $this->assertStringContainsString('numberBetween', $expr);
    }

    // ─── TestBitmask fixture still satisfies scanner expectations ─

    public function test_test_bitmask_fixture_schema_column_type_is_medium_integer(): void
    {
        $this->assertSame('mediumInteger', TestBitmask::schemaColumnType());
    }

    public function test_test_bitmask_fixture_schema_column_modifiers_unsigned(): void
    {
        $this->assertSame(['unsigned' => true], TestBitmask::schemaColumnModifiers());
    }
}
