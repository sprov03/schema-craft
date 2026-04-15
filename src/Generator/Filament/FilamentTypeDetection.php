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
     * True if the column's cast type is a class-name cast (e.g. a backed enum
     * or a custom DTO) rather than a built-in Laravel cast string.
     */
    protected function isEnumCast(ColumnDefinition $column): bool
    {
        if ($column->castType === null) {
            return false;
        }

        $builtInCasts = [
            'string', 'integer', 'int', 'float', 'double', 'boolean', 'bool',
            'array', 'json', 'object', 'datetime', 'date', 'timestamp',
            'collection', 'encrypted',
        ];

        return ! in_array($column->castType, $builtInCasts, true)
            && ! str_starts_with($column->castType, 'decimal:');
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
