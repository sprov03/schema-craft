<?php

namespace SchemaCraft\Types;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use SchemaCraft\Contracts\SchemaCraftColumn;
use SchemaCraft\DataSchema;
use SchemaCraft\Generators\GeneratorColumn;
use SchemaCraft\Scanner\ColumnDefinition;

/**
 * Base class for typed JSON array columns.
 *
 * Extend this class and define your item shape in a co-located DataSchema class.
 * Return that class from itemClass() — the base handles all serialization automatically.
 *
 * Define both classes in one file:
 *
 *   class WebhookAttemptItem extends DataSchema
 *   {
 *       public string $event;
 *       public int $status_code;
 *       public ?string $response_body;
 *   }
 *
 *   class WebhookAttemptCollection extends AbstractCollectionType
 *   {
 *       protected static function itemClass(): string
 *       {
 *           return WebhookAttemptItem::class;
 *       }
 *   }
 *
 * Usage in a schema:
 *
 *   public WebhookAttemptCollection $attempts;
 *
 * The column stores a JSON array in the DB. Items are hydrated as typed DataSchema
 * instances — work with the collection directly without thinking about serialization:
 *
 *   $model->attempts->push(WebhookAttemptItem::fromArray([...]));
 *   $model->attempts->filter(fn($a) => $a->success);
 *   $model->attempts->first()->status_code;
 *
 * DB storage: JSON array string.
 * API representation: array of item arrays.
 * API input: same array shape.
 *
 * @template TItem of DataSchema
 *
 * @extends Collection<int, TItem>
 */
abstract class AbstractCollectionType extends Collection implements CastsAttributes, SchemaCraftColumn
{
    // ─── Abstract ────────────────────────────────────────────────

    /** Return the DataSchema subclass that defines the item shape. */
    abstract protected static function itemClass(): string;

    // ─── CastsAttributes ─────────────────────────────────────────

    /**
     * Unified get() — serves both Collection::get() and CastsAttributes::get().
     *
     * Eloquent always passes a Model as the first argument. Collection usage
     * always passes a scalar key or null. We detect context by checking the
     * first argument type and delegate accordingly.
     */
    public function get($keyOrModel = null, $defaultOrKey = null, mixed $value = null, array $attributes = []): mixed
    {
        if ($keyOrModel instanceof Model) {
            return $this->castGet($keyOrModel, $defaultOrKey, $value, $attributes);
        }

        return parent::get($keyOrModel, $defaultOrKey);
    }

    private function castGet(Model $model, string $key, mixed $value, array $attributes): static
    {
        $raw = $value === null ? [] : (is_string($value) ? json_decode($value, true) : $value);

        return new static(
            array_map(fn (array $item) => static::itemClass()::fromArray($item), $raw ?? [])
        );
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
        $decoded = is_string($value) ? json_decode($value, true) : ($value ?? []);

        return new static(
            array_map(fn (array $item) => static::itemClass()::fromArray($item), $decoded ?? [])
        );
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

        return new static(
            array_map(fn (array $item) => static::itemClass()::fromArray($item), $input)
        );
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

    // ─── Collection overrides ────────────────────────────────────

    /**
     * Serialize each item via DataSchema::toArray() rather than casting the
     * raw Collection items directly.
     */
    public function toArray(): array
    {
        return $this->map(fn (DataSchema $item) => $item->toArray())->values()->all();
    }
}
