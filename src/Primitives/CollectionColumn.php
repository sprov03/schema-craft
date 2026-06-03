<?php

namespace SchemaCraft\Primitives;

use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as IlluminateCollection;
use ReflectionClass;
use SchemaCraft\Attributes\CollectionOf;
use SchemaCraft\Contracts\SchemaCraftColumn;
use SchemaCraft\DataSchema;
use SchemaCraft\Generators\GeneratorColumn;
use SchemaCraft\Scanner\ColumnDefinition;

/**
 * First-class collection primitive — schema-craft's symmetric answer to Bitmask and DataSchema.
 *
 * Sits alongside Bitmask in the schema-craft vocabulary. Three column-type primitives now
 * compose every JSON-shaped column the framework supports:
 *
 *   - DataSchema  — single typed object: class IS the shape, class IS the cast
 *   - Bitmask     — closed enumeration of bit flags: class IS the type
 *   - Collection  — typed list of DataSchema items: class IS the container; #[CollectionOf]
 *                   on the class declares the item DataSchema
 *
 * Users declare a concrete collection by extending this class and tagging it with
 * #[CollectionOf(...)] at class scope, naming the DataSchema that defines the item shape:
 *
 *   #[CollectionOf(WebhookAttemptShape::class)]
 *   class WebhookAttempts extends CollectionColumn
 *   {
 *       // optional domain methods, e.g. ->failedOnes(), ->byStatus(), etc.
 *   }
 *
 * Used uniformly across the three call sites:
 *   - Schemas:    public WebhookAttempts $attempts;
 *   - Resources:  public WebhookAttempts $attempts;
 *   - Actions:    public WebhookAttempts $attempts;
 *
 * Why extend Illuminate\Support\Collection: the runtime API (push, filter, map, etc.) is what
 * users expect from any "collection" object. Inheritance gives that for free without proxying
 * a hundred methods.
 *
 * Why Castable (not CastsAttributes directly): Illuminate\Support\Collection defines
 * `get($key, $default = null)`. CastsAttributes defines `get(Model, string, mixed, array)`.
 * The signatures are incompatible and PHP rejects both implementations on one class. Castable's
 * `castUsing()` is Laravel's canonical workaround — it returns a separate object implementing
 * CastsAttributes, sidestepping the signature conflict. (Bitmask and DataSchema don't have
 * this problem because they extend nothing; Collection forces the indirection.)
 *
 * Why class-level #[CollectionOf] (not the older `itemClass()` method or a constant): the
 * attribute is already schema-craft's vocabulary for "this is a typed collection of X" at the
 * property level. Using the same attribute at class scope keeps one declaration mechanism for
 * the same concept across both surfaces — no parallel "item type" API to learn.
 *
 * DB storage: JSON array.
 * API representation: array of item arrays.
 * API input: same array shape.
 *
 * @template TItem of DataSchema
 *
 * @extends IlluminateCollection<int, TItem>
 */
abstract class CollectionColumn extends IlluminateCollection implements Castable, SchemaCraftColumn
{
    // ─── Item-type introspection ─────────────────────────────────

    /**
     * Resolve the item DataSchema class from the class-level #[CollectionOf] attribute.
     * Throws if a subclass forgot to declare its item type — silent fallback would smuggle an
     * untyped collection past the cast pipeline and the SDK generator.
     *
     * @return class-string<DataSchema>
     */
    final public static function itemClass(): string
    {
        $attrs = (new ReflectionClass(static::class))->getAttributes(CollectionOf::class);

        if (empty($attrs)) {
            throw new \RuntimeException(
                'Collection primitive subclass ['.static::class.'] is missing its #[CollectionOf(ItemSchema::class)] '
                .'class attribute. Declare the item DataSchema at class scope, e.g. '
                .'#[CollectionOf(MyItemShape::class)] class MyCollection extends SchemaCraft\\Primitives\\Collection.'
            );
        }

        return $attrs[0]->newInstance()->resource;
    }

    // ─── Castable ────────────────────────────────────────────────

    /**
     * Returns the per-property cast handler. A separate anonymous class so we can implement
     * CastsAttributes without colliding with the inherited Collection::get($key, $default)
     * signature. Captures the concrete subclass via closure so the anonymous handler can
     * read its #[CollectionOf] and hydrate items into the right DataSchema type.
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        $collectionClass = static::class;

        return new class($collectionClass) implements CastsAttributes
        {
            public function __construct(private readonly string $collectionClass) {}

            public function get(Model $model, string $key, mixed $value, array $attributes): mixed
            {
                if ($value === null) {
                    return new ($this->collectionClass)([]);
                }

                $decoded = is_string($value) ? json_decode($value, true) : $value;
                $itemClass = ($this->collectionClass)::itemClass();

                $items = array_map(
                    fn (array $item) => $itemClass::fromArray($item),
                    is_array($decoded) ? $decoded : [],
                );

                return new ($this->collectionClass)($items);
            }

            public function set(Model $model, string $key, mixed $value, array $attributes): ?string
            {
                if ($value === null) {
                    return null;
                }

                if ($value instanceof IlluminateCollection) {
                    return json_encode(
                        $value->map(fn ($item) => $item instanceof DataSchema ? $item->toArray() : $item)
                            ->values()
                            ->all()
                    );
                }

                if (is_array($value)) {
                    return json_encode($value);
                }

                return (string) $value;
            }
        };
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
    // Called when a parent DataSchema has a property typed as this collection class —
    // hydrates a fresh instance from the raw JSON value via the same item-type path the
    // Castable handler uses.

    public static function fromRaw(mixed $value): static
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        $itemClass = static::itemClass();

        $items = array_map(
            fn (array $item) => $itemClass::fromArray($item),
            is_array($decoded) ? $decoded : [],
        );

        return new static($items);
    }

    public function toRaw(): array
    {
        return $this->toArray();
    }

    // ─── FormatsApiOutput / ParsesApiInput ───────────────────────

    public function toApiRepresentation(): array
    {
        return $this->toArray();
    }

    public static function fromApiInput(mixed $input): static
    {
        if (! is_array($input)) {
            throw new \InvalidArgumentException(
                static::class.' API input must be an array of items.'
            );
        }

        $itemClass = static::itemClass();

        return new static(
            array_map(fn (array $item) => $itemClass::fromArray($item), $input)
        );
    }

    // ─── GeneratesFakerValue / GeneratesSdkType ──────────────────

    public static function fakerExpression(ColumnDefinition $column): string
    {
        return '[]';
    }

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
     * Serialize each item via DataSchema::toArray() rather than casting raw Collection items —
     * keeps wire shape matched to what the SDK reflects.
     */
    public function toArray(): array
    {
        return $this->map(fn ($item) => $item instanceof DataSchema ? $item->toArray() : $item)
            ->values()
            ->all();
    }
}
