<?php

namespace SchemaCraft\Generator\Filament;

use SchemaCraft\Scanner\ColumnDefinition;

/**
 * Shared type-detection helpers for the Filament mappers.
 *
 * Kept intentionally tiny — only the classification predicates that would
 * otherwise be duplicated between `FilamentColumnMapper` and
 * `FilamentEntryMapper`.
 */
trait FilamentTypeDetection
{
    /**
     * True if the column's cast type is a backed PHP enum (string or int backed).
     *
     * Uses is_subclass_of(\BackedEnum::class) for precision — this matches only
     * actual PHP backed enums, not custom DTO casts or other class-name casts.
     * This mirrors the check in GeneratorColumn::isEnum() and keeps the two in sync.
     *
     * The previous negative approach ("anything not in a built-in list") was too
     * broad: it would also trigger for SchemaCraftColumn custom types, which are
     * already handled upstream by GeneratorColumn::getSchemaCraftColumnClass()
     * before the mappers are ever called. Using is_subclass_of is precise and
     * requires no maintenance as new cast types are added.
     */
    protected function isEnumCast(ColumnDefinition $column): bool
    {
        return $column->castType !== null
            && class_exists($column->castType)
            && is_subclass_of($column->castType, \BackedEnum::class);
    }

    /**
     * True if the column is any numeric integer/decimal/float type.
     */
    protected function isNumericType(string $columnType): bool
    {
        return in_array($columnType, [
            'integer', 'bigInteger', 'smallInteger', 'tinyInteger',
            'unsignedBigInteger', 'unsignedInteger', 'unsignedSmallInteger', 'unsignedTinyInteger',
            'decimal', 'float', 'double', 'year',
        ], true);
    }
}
