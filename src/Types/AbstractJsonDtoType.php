<?php

namespace SchemaCraft\Types;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use SchemaCraft\Contracts\SchemaCraftColumn;
use SchemaCraft\Generators\GeneratorColumn;
use SchemaCraft\Scanner\ColumnDefinition;

/**
 * Base class for JSON DTO columns.
 *
 * Extend this class and implement fromArray() and toArray() to describe
 * a typed value object that is persisted as a JSON column:
 *
 *   class AddressDto extends AbstractJsonDtoType
 *   {
 *       public function __construct(
 *           public readonly string $street,
 *           public readonly string $city,
 *       ) {}
 *
 *       public static function fromArray(array $data): static
 *       {
 *           return new static($data['street'] ?? '', $data['city'] ?? '');
 *       }
 *
 *       public function toArray(): array
 *       {
 *           return ['street' => $this->street, 'city' => $this->city];
 *       }
 *   }
 *
 * DB storage: JSON string.
 * API representation: the array returned by toArray().
 * API input: the same array shape.
 */
abstract class AbstractJsonDtoType implements CastsAttributes, SchemaCraftColumn
{
    // ─── Abstract ────────────────────────────────────────────────

    abstract public static function fromArray(array $data): static;

    abstract public function toArray(): array;

    // ─── CastsAttributes ─────────────────────────────────────────

    public function get(Model $model, string $key, mixed $value, array $attributes): ?static
    {
        if ($value === null) {
            return null;
        }

        $data = is_string($value) ? json_decode($value, true) : $value;

        return static::fromArray($data ?? []);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof static) {
            return json_encode($value->toArray());
        }

        if (is_array($value)) {
            return json_encode($value);
        }

        return (string) $value;
    }

    // ─── SchemaCraftType ─────────────────────────────────────────

    public static function schemaColumnType(): string
    {
        return 'json';
    }

    public static function schemaColumnModifiers(): array
    {
        return [];
    }

    public static function schemaValidationRules(): array
    {
        return ['array'];
    }

    // ─── CastsDataSchemaProperty ─────────────────────────────────

    public static function fromRaw(mixed $value): static
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return static::fromArray($value ?? []);
    }

    public function toRaw(): array
    {
        return $this->toArray();
    }

    // ─── FormatsApiOutput ────────────────────────────────────────

    public function toApiRepresentation(): array
    {
        return $this->toArray();
    }

    // ─── ParsesApiInput ──────────────────────────────────────────

    public static function fromApiInput(mixed $input): static
    {
        if (! is_array($input)) {
            throw new InvalidArgumentException(
                static::class.' API input must be an object/array. Got: '.gettype($input).'.'
            );
        }

        return static::fromArray($input);
    }

    // ─── GeneratesFakerValue ─────────────────────────────────────

    public static function fakerExpression(ColumnDefinition $column): string
    {
        return '[]';
    }

    // ─── GeneratesSdkType ────────────────────────────────────────

    public static function sdkType(): string
    {
        return 'array';
    }

    // ─── FilamentRenderable ──────────────────────────────────────

    public static function asFilamentField(GeneratorColumn $column): string
    {
        return "Forms\\Components\\KeyValue::make('{$column->name}')";
    }

    public static function asFilamentColumn(GeneratorColumn $column): string
    {
        return "Tables\\Columns\\TextColumn::make('{$column->name}')";
    }

    public static function asFilamentEntry(GeneratorColumn $column): string
    {
        return "Infolists\\Components\\TextEntry::make('{$column->name}')";
    }
}
