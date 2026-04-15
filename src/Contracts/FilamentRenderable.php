<?php

namespace SchemaCraft\Contracts;

use SchemaCraft\Generators\GeneratorColumn;

/**
 * Opt-in interface for custom cast classes that render their own Filament output.
 *
 * Implement on the same class used as a property type (e.g. CollectionColumn,
 * TinyBitmaskColumn, a backed enum) — the generator will delegate Filament
 * rendering to these static methods instead of its built-in decision tree.
 *
 * Each method returns a single Filament component expression without leading
 * whitespace or trailing commas. The calling partial is responsible for
 * indentation and list separators. Example return values:
 *
 *     "Tables\\Columns\\TextColumn::make('tags')->badge()->separator(',')"
 *     "Infolists\\Components\\TextEntry::make('tags')->badge()"
 *     "Forms\\Components\\TagsInput::make('tags')"
 *
 * Types that DON'T need this interface:
 * - Plain scalar columns (string, int, bool, date, enum) — built-in mapper handles them.
 * - Custom types that are content with the default `->badge()` rendering.
 *
 * Types that DO benefit from this interface:
 * - Collection/JSON DTOs that need a KeyValueEntry or custom repeater.
 * - Bitmask columns that should render as a multi-select CheckboxList.
 * - Any domain-specific rendering that shouldn't leak into the generic mapper.
 */
interface FilamentRenderable
{
    /**
     * Render this column as a Filament table column expression.
     */
    public static function asFilamentColumn(GeneratorColumn $column): string;

    /**
     * Render this column as a Filament infolist entry expression.
     */
    public static function asFilamentEntry(GeneratorColumn $column): string;

    /**
     * Render this column as a Filament form field expression.
     */
    public static function asFilamentField(GeneratorColumn $column): string;
}
