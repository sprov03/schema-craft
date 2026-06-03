<?php

namespace SchemaCraft\Primitives;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use SchemaCraft\Contracts\SchemaCraftColumn;
use SchemaCraft\DataSchema;
use SchemaCraft\Generators\GeneratorColumn;
use SchemaCraft\Scanner\ColumnDefinition;

/**
 * Typed JSON column primitive — extend this to declare a JSON column whose shape is
 * a DataSchema.
 *
 *   class CatalogAttributes extends JsonColumn {
 *       public ?string $color;
 *       public ?string $material;
 *       public ?int $weight_grams;
 *   }
 *
 *   class CatalogSchema extends Schema {
 *       public CatalogAttributes $attributes_json;
 *   }
 *
 * Class identity carries the role: the import line `use SchemaCraft\Primitives\JsonColumn`
 * signals "this represents a DB JSON column." Pure shape-only classes that aren't intended
 * as columns extend `SchemaCraft\DataSchema` directly (no JSON-column surface).
 *
 * Inherits all shape behavior from DataSchema (typed properties, fromArray/toArray, nested
 * validation rules walker, jsonSerialize). Adds the column surface:
 *
 *   - Castable + CastsAttributes (Eloquent cast pipeline): the cast handler delegates
 *     to DataSchema::resolveFromColumnValue() so the "non-nullable property + null DB
 *     value → hydrate with defaults" behavior lives in one place.
 *   - SchemaCraftColumn (generator dispatch): schemaColumnType='json', faker, Filament
 *     renderers, sdkType.
 *
 * A JsonColumn subclass IS a DataSchema, so it can also be used wherever a bare DataSchema
 * can be — inside other DataSchemas, as Action payloads, as Resource properties. The column
 * surface is dormant in those uses (Laravel only invokes the cast methods when the class
 * is wired as a $casts entry).
 */
abstract class JsonColumn extends DataSchema implements SchemaCraftColumn
{
    // ─── Castable / CastsAttributes ─────────────────────────────────
    // Laravel's $casts entry can name a JsonColumn FQCN directly; Eloquent calls
    // castUsing() to obtain the cast handler. The handler delegates read-side work
    // to DataSchema::resolveFromColumnValue() (the nullability-aware resolver) so all
    // smart-resolve logic lives in one place.

    public static function castUsing(array $arguments): CastsAttributes
    {
        $jsonColumnClass = static::class;

        return new class($jsonColumnClass) implements CastsAttributes
        {
            public function __construct(private readonly string $jsonColumnClass) {}

            public function get(Model $model, string $key, mixed $value, array $attributes): ?DataSchema
            {
                return ($this->jsonColumnClass)::resolveFromColumnValue($model, $key, $value);
            }

            public function set(Model $model, string $key, mixed $value, array $attributes): ?string
            {
                if ($value === null) {
                    return null;
                }

                if ($value instanceof DataSchema) {
                    return json_encode($value->toArray());
                }

                if (is_array($value)) {
                    return json_encode($value);
                }

                return (string) $value;
            }
        };
    }

    // ─── SchemaCraftType ─────────────────────────────────────────
    // Every JsonColumn stores as a JSON column. Schema-level attributes (#[Length],
    // #[Decimal], etc.) don't apply to JSON columns; modifiers stay flat.

    public static function schemaColumnType(): string
    {
        return 'json';
    }

    public static function schemaColumnModifiers(): array
    {
        return [];
    }

    /**
     * Returns nested dot-notation rules walked from the DataSchema's typed properties
     * (DataSchema::validationRules), so a JsonColumn column's validation rules include
     * each nested field. The column-level rule itself is 'array' — added by the framework's
     * ValidationRuleMapper when it sees a JsonColumn-typed column.
     */
    public static function schemaValidationRules(): array
    {
        return static::validationRules();
    }

    // ─── FormatsApiOutput / ParsesApiInput ───────────────────────

    public function toApiRepresentation(): array
    {
        return $this->toArray();
    }

    public static function fromApiInput(mixed $input): static
    {
        if (! is_array($input)) {
            throw new \InvalidArgumentException(static::class.' API input must be an array.');
        }

        return static::fromArray($input);
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
    // Generic defaults — projects can override per-JsonColumn if a specific shape
    // benefits from a different Filament rendering.

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
