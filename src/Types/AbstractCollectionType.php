<?php

namespace SchemaCraft\Types;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use SchemaCraft\Contracts\SchemaCraftColumn;
use SchemaCraft\Generators\GeneratorColumn;
use SchemaCraft\Scanner\ColumnDefinition;

/**
 * Base class for typed collection columns — JSON arrays of structured items.
 *
 * Extend this class and implement itemFromArray() and itemToArray() to describe
 * each item in the collection. The column stores a JSON array in the DB:
 *
 *   class TagCollection extends AbstractCollectionType
 *   {
 *       protected static function itemFromArray(array $item): array
 *       {
 *           return ['name' => $item['name'] ?? '', 'color' => $item['color'] ?? '#000'];
 *       }
 *
 *       protected static function itemToArray(mixed $item): array
 *       {
 *           return is_array($item) ? $item : (array) $item;
 *       }
 *   }
 *
 * DB storage: JSON array string.
 * API representation: array of item arrays.
 * API input: the same array shape.
 *
 * The internal representation is a Laravel Collection of item arrays.
 * If you need strongly typed item objects, override itemFromArray() to
 * return an object and itemToArray() to serialize it back.
 */
abstract class AbstractCollectionType implements CastsAttributes, SchemaCraftColumn
{
    /** @var Collection<int, mixed> */
    protected Collection $items;

    public function __construct(Collection|array $items = [])
    {
        $this->items = $items instanceof Collection ? $items : collect($items);
    }

    // ─── Abstract ────────────────────────────────────────────────

    /** Hydrate a single item from its raw array representation. */
    abstract protected static function itemFromArray(array $item): mixed;

    /** Dehydrate a single item back to its raw array representation. */
    abstract protected static function itemToArray(mixed $item): array;

    // ─── CastsAttributes ─────────────────────────────────────────

    public function get(Model $model, string $key, mixed $value, array $attributes): static
    {
        $raw = $value === null ? [] : (is_string($value) ? json_decode($value, true) : $value);
        $items = collect(array_map(fn (array $item) => static::itemFromArray($item), $raw ?? []));

        return new static($items);
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

        $items = collect(array_map(fn (array $item) => static::itemFromArray($item), $value ?? []));

        return new static($items);
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
                static::class.' API input must be an array of items. Got: '.gettype($input).'.'
            );
        }

        $items = collect(array_map(fn (array $item) => static::itemFromArray($item), $input));

        return new static($items);
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
        return "Forms\\Components\\Repeater::make('{$column->name}')->schema([])";
    }

    public static function asFilamentColumn(GeneratorColumn $column): string
    {
        return "Tables\\Columns\\TextColumn::make('{$column->name}')";
    }

    public static function asFilamentEntry(GeneratorColumn $column): string
    {
        return "Infolists\\Components\\RepeatableEntry::make('{$column->name}')->schema([])";
    }

    // ─── Public helpers ──────────────────────────────────────────

    /** @return Collection<int, mixed> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function count(): int
    {
        return $this->items->count();
    }

    public function toArray(): array
    {
        return $this->items->map(fn (mixed $item) => static::itemToArray($item))->values()->all();
    }
}
