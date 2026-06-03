<?php

namespace SchemaCraft\Primitives;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonSerializable;
use ReflectionClass;
use SchemaCraft\Contracts\SchemaCraftColumn;
use SchemaCraft\Generator\Sdk\SdkShape;
use SchemaCraft\Generator\Sdk\SdkShapeField;
use SchemaCraft\Generators\GeneratorColumn;
use SchemaCraft\Scanner\ColumnDefinition;

/**
 * First-class bitmask primitive — PHP has no native bitmask type, schema-craft fills the gap.
 *
 * Sits alongside PHP's native enum in the schema-craft vocabulary: both are "closed enumeration"
 * primitives — a known set of named values the type itself documents. This base class is abstract
 * on schemaColumnType() / schemaColumnModifiers() / schemaValidationRules() — users extend a
 * size-tier subclass (TinyBitmask / MediumBitmask / LargeBitmask) that pins those for the
 * appropriate DB column size, then declare flags as PHP constants:
 *
 *   class MissionBitmask extends LargeBitmask
 *   {
 *       const LOAN      = 1;
 *       const PURCHASE  = 2;
 *       const REFINANCE = 4;
 *
 *       protected static function flagMetadata(): array
 *       {
 *           return [
 *               'LOAN'      => ['label' => 'Loan'],
 *               'PURCHASE'  => ['label' => 'Purchase', 'description' => 'Owner-occupied only'],
 *               'REFINANCE' => ['label' => 'Refinance'],
 *           ];
 *       }
 *   }
 *
 * Why constants instead of an array-returning method: `MissionBitmask::LOAN` is IDE-autocompleted,
 * refactor-safe, statically analyzable, and reads as a typed integer in every PHP tool. An array
 * method approach can't be referenced that way — you'd write `'LOAN'` (a string) or `1` (a bare
 * int), both worse for IDE support and refactoring.
 *
 * flagMetadata() is optional and supplies per-flag labels / descriptions for the API docs and
 * SDK. Constants without entries get a humanized label ("LOAN" → "Loan") and no description.
 *
 * DB storage: raw integer (size depends on tier subclass).
 * API representation: { value: 6, flags: { LOAN: true, PURCHASE: true, REFINANCE: false } }
 * API input: flags object only — { LOAN: true, PURCHASE: false }
 */
abstract class Bitmask implements CastsAttributes, JsonSerializable, SchemaCraftColumn
{
    public function __construct(protected int $value = 0) {}

    // ─── Construction helpers ────────────────────────────────────

    /**
     * Build from an integer or an array of integer flags (OR'd together). Each input flag is
     * validated to be a subset of declared bits — passing a bare int that lights up undefined
     * bits throws, surfacing programming errors at construction rather than silently storing
     * bogus state.
     */
    public static function from(int|array $value): static
    {
        if (is_array($value)) {
            $combined = array_reduce($value, fn ($a, $b) => $a | $b, 0);
            $bitmask = new static($combined);
            foreach ($value as $flag) {
                $bitmask->validateFlag($flag);
            }

            return $bitmask;
        }

        return new static($value);
    }

    // ─── CastsAttributes ─────────────────────────────────────────

    public function get($model, string $key, $value, array $attributes): ?static
    {
        if ($value === null) {
            return null;
        }

        return new static((int) $value);
    }

    public function set($model, string $key, $value, array $attributes): mixed
    {
        if ($value instanceof static) {
            return $value->getValue();
        }

        if ($value === null || is_int($value)) {
            return $value;
        }

        throw new InvalidArgumentException(
            'Value must be int, null, or '.static::class.' instance.'
        );
    }

    // ─── JsonSerializable ────────────────────────────────────────

    public function jsonSerialize(): mixed
    {
        return $this->toApiRepresentation();
    }

    public function __toString(): string
    {
        return decbin($this->value);
    }

    // ─── SchemaCraftType ─────────────────────────────────────────
    // Abstract — size-tier subclasses pin these to their DB column type.

    abstract public static function schemaColumnType(): string;

    abstract public static function schemaColumnModifiers(): array;

    abstract public static function schemaValidationRules(): array;

    // ─── CastsDataSchemaProperty ─────────────────────────────────

    public static function fromRaw(mixed $value): static
    {
        return new static((int) $value);
    }

    public function toRaw(): mixed
    {
        return $this->value;
    }

    // ─── FormatsApiOutput ────────────────────────────────────────

    /** @return array{value: int, flags: array<string, bool>} */
    public function toApiRepresentation(): array
    {
        $flags = [];
        foreach (static::getBitFlags() as $name => $bit) {
            $flags[$name] = ($this->value & $bit) === $bit;
        }

        return ['value' => $this->value, 'flags' => $flags];
    }

    // ─── ParsesApiInput ──────────────────────────────────────────

    public static function fromApiInput(mixed $input): static
    {
        if (! is_array($input)) {
            throw new InvalidArgumentException(
                static::class.' API input must be an array of flag names to boolean values.'
            );
        }

        $defined = static::getBitFlags();
        $combined = 0;

        foreach ($input as $flagName => $active) {
            if (! isset($defined[$flagName])) {
                throw new InvalidArgumentException("Unknown flag [{$flagName}] for ".static::class.'.');
            }

            if ($active) {
                $combined |= $defined[$flagName];
            }
        }

        return new static($combined);
    }

    // ─── GeneratesFakerValue ─────────────────────────────────────

    public static function fakerExpression(ColumnDefinition $column): string
    {
        $max = array_sum(static::getBitFlags());

        return "\$faker->numberBetween(0, {$max})";
    }

    // ─── GeneratesSdkType ────────────────────────────────────────

    public static function sdkType(): string
    {
        return 'array';
    }
    // Wire shape is intrinsic to the primitive — {value: int, flags: {<FLAG>: bool, ...}}.
    // The framework constructs it via SdkShape::forType() by reading getBitFlagsPublic().
    // Implementers don't touch SdkShape; they just declare flag constants.

    // ─── FilamentRenderable ──────────────────────────────────────

    public static function asFilamentField(GeneratorColumn $column): string
    {
        $name = $column->name;
        $class = static::class;

        return "Forms\\Components\\CheckboxList::make('{$name}')"
            ."->options(array_combine(array_keys(\\{$class}::getBitFlagsPublic()), array_keys(\\{$class}::getBitFlagsPublic())))";
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

    // ─── Flag introspection ──────────────────────────────────────

    /**
     * Optional per-flag metadata. Override in concrete bitmasks to supply API-docs / SDK labels
     * and descriptions. Constants without entries get a humanized label and no description.
     *
     * @return array<string, array{label?: string, description?: string}>
     */
    protected static function flagMetadata(): array
    {
        return [];
    }

    /**
     * Normalized definition list — { name, value, label, description? } per declared flag.
     * Single source consumed by the SDK options projection and API docs renderer. Built by
     * merging getBitFlags() (the source of truth for which flags exist) with flagMetadata()
     * (optional human-readable additions).
     *
     * @return array<int, array{name: string, value: int, label: string, description: ?string}>
     */
    final public static function definitions(): array
    {
        $metadata = static::flagMetadata();
        $out = [];

        foreach (static::getBitFlags() as $name => $value) {
            $meta = $metadata[$name] ?? [];
            $out[] = [
                'name' => $name,
                'value' => $value,
                'label' => $meta['label'] ?? self::humanizeName($name),
                'description' => $meta['description'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Single-bit positive integer constants only. Excludes NONE (0), ALL bundles (multi-bit),
     * and non-integer constants — exactly the set of "real" flags the bitmask exposes. The
     * filter `($value & ($value - 1)) === 0` keeps only powers of two.
     *
     * @return array<string, int>
     */
    protected static function getBitFlags(): array
    {
        $flags = [];

        foreach ((new ReflectionClass(static::class))->getConstants() as $name => $value) {
            if (is_int($value) && $value > 0 && ($value & ($value - 1)) === 0) {
                $flags[$name] = $value;
            }
        }

        return $flags;
    }

    /**
     * Public surface for codegen sites (Filament, etc.) that need to enumerate flags without
     * subclassing. Delegates to the protected getBitFlags() so the filter rules stay in one
     * place.
     *
     * @return array<string, int>
     */
    public static function getBitFlagsPublic(): array
    {
        return static::getBitFlags();
    }

    /** All declared constants — includes bundles (ALL, NONE, etc.). Used by validateFlag. */
    protected function getFlags(): array
    {
        return (new ReflectionClass(static::class))->getConstants();
    }

    private static function humanizeName(string $name): string
    {
        return Str::headline(strtolower($name));
    }

    // ─── Flag operations ─────────────────────────────────────────

    /**
     * Reject flag values whose bits aren't a subset of the union of declared flags. Surfaces
     * programming errors (passing the wrong constant from a sibling bitmask, passing a raw
     * int, etc.) at call time rather than letting them silently corrupt state.
     */
    protected function validateFlag(int $flag): void
    {
        $allFlags = array_values($this->getFlags());
        $allCombined = array_reduce($allFlags, fn ($a, $b) => $a | (is_int($b) ? $b : 0), 0);

        if (($flag & ~$allCombined) !== 0) {
            throw new InvalidArgumentException(
                'Invalid flag value(s) for '.static::class.' — contains undefined bits.'
            );
        }
    }

    /** Check whether all bits in $flag are set on this instance. */
    public function hasFlag(int|self $flag): bool
    {
        $flagValue = $flag instanceof self ? $flag->getValue() : $flag;
        $this->validateFlag($flagValue);

        return ($this->value & $flagValue) === $flagValue;
    }

    /** Set the bits in $flag. Mutating — returns $this. */
    public function setFlag(int|self $flag): self
    {
        $flagValue = $flag instanceof self ? $flag->getValue() : $flag;
        $this->validateFlag($flagValue);
        $this->value |= $flagValue;

        return $this;
    }

    /** Clear the bits in $flag. Mutating — returns $this. */
    public function unsetFlag(int|self $flag): self
    {
        $flagValue = $flag instanceof self ? $flag->getValue() : $flag;
        $this->validateFlag($flagValue);
        $this->value &= ~$flagValue;

        return $this;
    }

    /** Zero out all flags. Mutating — returns $this. */
    public function clear(): self
    {
        $this->value = 0;

        return $this;
    }

    public function getValue(): int
    {
        return $this->value;
    }

    /**
     * Active flag names, in declaration order. Only single-bit flags (via getBitFlags) are
     * considered — bundle constants like ALL aren't reported as "active" even when fully set.
     *
     * @return array<int, string>
     */
    public function getActiveFlags(): array
    {
        $active = [];

        foreach (static::getBitFlags() as $name => $value) {
            if ($this->hasFlag($value)) {
                $active[] = $name;
            }
        }

        return $active;
    }

    // ─── Query helpers ───────────────────────────────────────────

    /** WHERE bitmask & flag = flag — all the bits in $flag are set. */
    public static function whereHasFlag(Builder $query, string $column, int|self $flag): Builder
    {
        $value = $flag instanceof self ? $flag->getValue() : $flag;

        return $query->whereRaw("{$column} & ? = ?", [$value, $value]);
    }

    /** WHERE bitmask & flags > 0 — at least one of the requested bits is set. */
    public static function whereHasAnyFlags(Builder $query, string $column, int|array|self $flags): Builder
    {
        $combined = static::resolveFlagsToInt($flags);

        return $query->whereRaw("{$column} & ? > 0", [$combined]);
    }

    /** WHERE bitmask & flags = flags — every requested bit is set. */
    public static function whereHasAllFlags(Builder $query, string $column, int|array|self $flags): Builder
    {
        $combined = static::resolveFlagsToInt($flags);

        return $query->whereRaw("{$column} & ? = ?", [$combined, $combined]);
    }

    /** WHERE bitmask & flag = 0 — the bit isn't set. */
    public static function whereDoesNotHaveFlag(Builder $query, string $column, int|self $flag): Builder
    {
        $value = $flag instanceof self ? $flag->getValue() : $flag;

        return $query->whereRaw("{$column} & ? = 0", [$value]);
    }

    /** WHERE bitmask = exact value — the column's value is precisely $value (no other bits set). */
    public static function whereIsExactly(Builder $query, string $column, int|self $value): Builder
    {
        $intValue = $value instanceof self ? $value->getValue() : $value;

        return $query->where($column, $intValue);
    }

    protected static function resolveFlagsToInt(int|array|self $flags): int
    {
        if ($flags instanceof self) {
            return $flags->getValue();
        }

        if (is_int($flags)) {
            return $flags;
        }

        return array_reduce($flags, function ($acc, $f) {
            if ($f instanceof self) {
                return $acc | $f->getValue();
            }
            if (is_int($f)) {
                return $acc | $f;
            }
            throw new InvalidArgumentException('Array elements must be int or '.static::class);
        }, 0);
    }
}
