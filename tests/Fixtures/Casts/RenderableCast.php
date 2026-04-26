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

    // ─── CastsDataSchemaProperty ─────────────────────────────────

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
