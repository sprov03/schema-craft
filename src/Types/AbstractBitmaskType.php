<?php

namespace SchemaCraft\Types;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonSerializable;
use SchemaCraft\Contracts\SchemaCraftColumn;
use SchemaCraft\Generators\GeneratorColumn;
use SchemaCraft\Scanner\ColumnDefinition;

/**
 * Base class for integer bitmask columns.
 *
 * Extend this class and implement flags() to declare each bit:
 *
 *   class LoanTypeMask extends AbstractBitmaskType
 *   {
 *       protected static function flags(): array
 *       {
 *           return ['LOAN' => 1, 'PURCHASE' => 2, 'REFINANCE' => 4];
 *       }
 *   }
 *
 * DB storage: raw integer.
 * API representation: { value: 6, flags: { LOAN: true, PURCHASE: true, REFINANCE: false } }
 * API input: flags object only — { LOAN: true, PURCHASE: false }
 *
 * Filament form: CheckboxList with one checkbox per flag.
 * Filament table column: comma-separated active flag names.
 * Filament infolist entry: comma-separated active flag names.
 */
abstract class AbstractBitmaskType implements CastsAttributes, JsonSerializable, SchemaCraftColumn
{
    public function __construct(protected readonly int $value = 0) {}

    // ─── Abstract ────────────────────────────────────────────────

    /**
     * Declare the flag names and their bit values.
     * Keys are the string flag names; values are the integer bit positions.
     *
     * @return array<string, int>
     */
    abstract protected static function flags(): array;

    // ─── CastsAttributes ─────────────────────────────────────────

    public function get(Model $model, string $key, mixed $value, array $attributes): static
    {
        return new static((int) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): int
    {
        if ($value instanceof static) {
            return $value->value;
        }

        return (int) $value;
    }

    // ─── JsonSerializable ────────────────────────────────────────

    public function jsonSerialize(): mixed
    {
        return $this->toApiRepresentation();
    }

    // ─── SchemaCraftType ─────────────────────────────────────────

    public static function schemaColumnType(): string
    {
        return 'integer';
    }

    public static function schemaColumnModifiers(): array
    {
        return [];
    }

    /**
     * Accepts a flags object: { LOAN: true, PURCHASE: false }.
     * The required/array rule ensures the client sends the correct shape.
     */
    public static function schemaValidationRules(): array
    {
        return ['required', 'array'];
    }

    // ─── CastsDataSchemaProperty ─────────────────────────────────

    public static function fromRaw(mixed $value): static
    {
        return new static((int) $value);
    }

    public function toRaw(): int
    {
        return $this->value;
    }

    // ─── FormatsApiOutput ────────────────────────────────────────

    /**
     * Returns both the raw integer and a human-readable flags breakdown.
     * The client always works with flags; value is informational.
     *
     * @return array{value: int, flags: array<string, bool>}
     */
    public function toApiRepresentation(): array
    {
        return [
            'value' => $this->value,
            'flags' => $this->toFlagsArray(),
        ];
    }

    // ─── ParsesApiInput ──────────────────────────────────────────

    /**
     * Accepts a flags object: { LOAN: true, PURCHASE: false }.
     * Compiles it back to the integer bitmask for DB storage.
     */
    public static function fromApiInput(mixed $input): static
    {
        if (! is_array($input)) {
            throw new InvalidArgumentException(
                'Bitmask input must be a flags object, e.g. {"LOAN": true, "PURCHASE": false}. Got: '.gettype($input).'.'
            );
        }

        $flags = static::flags();
        $value = 0;

        foreach ($input as $flag => $active) {
            if (! isset($flags[$flag])) {
                throw new InvalidArgumentException(
                    "Unknown bitmask flag [{$flag}] for ".static::class.'. Valid flags: '.implode(', ', array_keys($flags)).'.'
                );
            }

            if ($active) {
                $value |= $flags[$flag];
            }
        }

        return new static($value);
    }

    // ─── GeneratesFakerValue ─────────────────────────────────────

    public static function fakerExpression(ColumnDefinition $column): string
    {
        $max = array_sum(static::flags());

        return "\$faker->numberBetween(0, {$max})";
    }

    // ─── GeneratesSdkType ────────────────────────────────────────

    /**
     * SDK DTOs receive the full API shape (value + flags object), so array.
     */
    public static function sdkType(): string
    {
        return 'array';
    }

    // ─── FilamentRenderable ──────────────────────────────────────

    public static function asFilamentField(GeneratorColumn $column): string
    {
        $name = $column->name;
        $class = static::class;

        return "Forms\\Components\\CheckboxList::make('{$name}')"
            ."->options(array_keys(\\{$class}::flags()))";
    }

    public static function asFilamentColumn(GeneratorColumn $column): string
    {
        $name = $column->name;

        return "Tables\\Columns\\TextColumn::make('{$name}')"
            ."->formatStateUsing(fn (\$state) => is_array(\$state['flags'] ?? null)"
            ." ? implode(', ', array_keys(array_filter(\$state['flags'])))"
            .' : (string) $state)';
    }

    public static function asFilamentEntry(GeneratorColumn $column): string
    {
        $name = $column->name;

        return "Infolists\\Components\\TextEntry::make('{$name}')"
            ."->formatStateUsing(fn (\$state) => collect(\$state['flags'] ?? [])"
            ."->filter()->keys()->join(', '))";
    }

    // ─── Public helpers ──────────────────────────────────────────

    public function getValue(): int
    {
        return $this->value;
    }

    public function hasFlag(string $flag): bool
    {
        $flags = static::flags();

        return isset($flags[$flag]) && (bool) ($this->value & $flags[$flag]);
    }

    public function withFlag(string $flag): static
    {
        $flags = static::flags();

        if (! isset($flags[$flag])) {
            throw new InvalidArgumentException("Unknown flag [{$flag}] for ".static::class.'.');
        }

        return new static($this->value | $flags[$flag]);
    }

    public function withoutFlag(string $flag): static
    {
        $flags = static::flags();

        if (! isset($flags[$flag])) {
            throw new InvalidArgumentException("Unknown flag [{$flag}] for ".static::class.'.');
        }

        return new static($this->value & ~$flags[$flag]);
    }

    // ─── Private ─────────────────────────────────────────────────

    /** @return array<string, bool> */
    protected function toFlagsArray(): array
    {
        $result = [];

        foreach (static::flags() as $name => $bit) {
            $result[$name] = (bool) ($this->value & $bit);
        }

        return $result;
    }
}
