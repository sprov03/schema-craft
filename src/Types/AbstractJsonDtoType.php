<?php

namespace SchemaCraft\Types;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use SchemaCraft\Contracts\SchemaCraftColumn;
use SchemaCraft\DataSchema;
use SchemaCraft\Generator\Sdk\SdkShape;
use SchemaCraft\Generators\GeneratorColumn;
use SchemaCraft\Scanner\ColumnDefinition;

/**
 * Base class for JSON DTO columns.
 *
 * Define the object shape in a co-located DataSchema and return it from
 * dtoClass(). The base handles hydration/serialization automatically:
 *
 *   class AddressShape extends DataSchema
 *   {
 *       public string $street;
 *       public string $city;
 *   }
 *
 *   class AddressDto extends AbstractJsonDtoType
 *   {
 *       protected static function dtoClass(): string
 *       {
 *           return AddressShape::class;
 *       }
 *   }
 *
 * DB storage: JSON string.
 * API representation: the array returned by toArray().
 * API input: the same array shape.
 *
 * The object shape is declared by a co-located DataSchema (returned from
 * dtoClass()). The base routes fromArray()/toArray() through that DataSchema so
 * the SDK generator can REFLECT the shape and emit a typed nested DTO instead of
 * a bare `array` — while runtime serialization behaviour is unchanged (it's the
 * same DataSchema hydrate/dehydrate the DTO would otherwise hand-roll).
 */
abstract class AbstractJsonDtoType implements CastsAttributes, SchemaCraftColumn
{
    // ─── Abstract ────────────────────────────────────────────────

    /**
     * Return the DataSchema subclass that defines this DTO's object shape.
     *
     * This is the reflectable source the SDK generator uses to type the nested
     * DTO. Subclasses that need custom hydration may still override fromArray()/
     * toArray(), but the default implementations delegate to this DataSchema.
     *
     * @return class-string<DataSchema>
     */
    abstract protected static function dtoClass(): string;

    /**
     * The hydrated DataSchema backing this value object.
     *
     * Populated by fromArray(). Holding the DataSchema (rather than re-deriving it)
     * means runtime serialization is exactly the DataSchema's hydrate/dehydrate —
     * the same contract the SDK reflects, so wire shape and SDK type can't drift.
     */
    protected ?DataSchema $dto = null;

    // ─── Construction / serialization (DataSchema-backed) ─────────

    /**
     * Build the value object by hydrating the backing DataSchema.
     *
     * Subclasses with bespoke construction may override; the default routes
     * through dtoClass() so the runtime shape matches what the SDK reflects.
     */
    public static function fromArray(array $data): static
    {
        $instance = new static;
        $instance->dto = (static::dtoClass())::fromArray($data);

        return $instance;
    }

    /**
     * Serialize via the backing DataSchema so output matches the reflected shape.
     */
    public function toArray(): array
    {
        return $this->dto?->toArray() ?? [];
    }

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

    /**
     * The DTO serializes as a single object whose shape is the backing
     * DataSchema, so the SDK shape is object(dtoClass()) — the generator
     * reflects that DataSchema to emit a typed nested {Basename}Data.
     */
    public static function sdkShape(): SdkShape
    {
        return SdkShape::object(static::dtoClass());
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
