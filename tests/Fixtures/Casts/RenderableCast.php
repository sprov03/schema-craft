<?php

namespace SchemaCraft\Tests\Fixtures\Casts;

use InvalidArgumentException;
use SchemaCraft\Contracts\SchemaCraftColumn;
use SchemaCraft\Generators\GeneratorColumn;
use SchemaCraft\Scanner\ColumnDefinition;

/**
 * Fixture implementing SchemaCraftColumn so tests can verify that
 * GeneratorColumn dispatches to the custom static renderers.
 */
class RenderableCast implements SchemaCraftColumn
{
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

    // ─── Castable ────────────────────────────────────────────────
    // No-op cast handler — this fixture is only exercised for generator dispatch tests
    // (Filament rendering), not as a runtime cast. Anonymous CastsAttributes satisfies
    // the interface requirement that SchemaCraftColumn now composes.

    public static function castUsing(array $arguments): \Illuminate\Contracts\Database\Eloquent\CastsAttributes
    {
        return new class implements \Illuminate\Contracts\Database\Eloquent\CastsAttributes
        {
            public function get(\Illuminate\Database\Eloquent\Model $model, string $key, mixed $value, array $attributes): mixed
            {
                return $value;
            }

            public function set(\Illuminate\Database\Eloquent\Model $model, string $key, mixed $value, array $attributes): mixed
            {
                return $value;
            }
        };
    }

    // ─── CastsDataSchemaProperty (legacy methods, no longer in SchemaCraftColumn) ──

    public static function fromRaw(mixed $value): static
    {
        return new static;
    }

    public function toRaw(): mixed
    {
        return null;
    }

    // ─── FormatsApiOutput ────────────────────────────────────────

    public function toApiRepresentation(): mixed
    {
        return null;
    }

    // ─── ParsesApiInput ──────────────────────────────────────────

    public static function fromApiInput(mixed $input): static
    {
        if (! is_array($input)) {
            throw new InvalidArgumentException('Expected array.');
        }

        return new static;
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

    public static function asFilamentColumn(GeneratorColumn $column): string
    {
        return "CustomColumn::make('{$column->name}')->customised()";
    }

    public static function asFilamentEntry(GeneratorColumn $column): string
    {
        return "CustomEntry::make('{$column->name}')->customised()";
    }

    public static function asFilamentField(GeneratorColumn $column): string
    {
        return "CustomField::make('{$column->name}')->customised()";
    }
}
