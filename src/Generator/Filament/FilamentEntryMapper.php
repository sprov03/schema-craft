<?php

namespace SchemaCraft\Generator\Filament;

use SchemaCraft\Scanner\ColumnDefinition;

/**
 * Maps a ColumnDefinition to a Filament infolist entry code string.
 *
 * Infolists in Filament v5 are configured via `Filament\Schemas\Schema`
 * (`->components([...])`) and use `Filament\Infolists\Components\*` components
 * (TextEntry, IconEntry, ImageEntry, ...) which share Filament's table
 * vocabulary but NOT its interactivity — `->searchable()`, `->sortable()`,
 * and `->toggleable()` have no meaning on entries and are dropped here.
 *
 * Differences from `FilamentColumnMapper`:
 * - Uses the `Infolists\Components\*` namespace instead of `Tables\Columns\*`.
 * - Drops `->searchable()` / `->sortable()` / `->toggleable()`.
 * - Adds `->placeholder('—')` to nullable entries so empty values are obvious.
 * - Long-text columns get `->columnSpanFull()` (two-column grid by default).
 */
class FilamentEntryMapper
{
    use FilamentTypeDetection;

    /**
     * Map a column to its Filament infolist entry code.
     */
    public function map(ColumnDefinition $column, string $indent = '                '): string
    {
        return $indent.$this->resolveEntryType($column);
    }

    private function resolveEntryType(ColumnDefinition $column): string
    {
        if ($this->isEnumCast($column)) {
            return $this->buildEnumEntry($column);
        }

        return match ($column->columnType) {
            'boolean' => $this->buildBooleanEntry($column),
            'date' => $this->buildDateEntry($column),
            'timestamp', 'dateTime', 'dateTimeTz' => $this->buildDateTimeEntry($column),
            'time' => $this->buildTimeEntry($column),
            'text', 'mediumText', 'longText' => $this->buildLongTextEntry($column),
            'json' => $this->buildJsonEntry($column),
            'uuid', 'ulid' => $this->buildCopyableEntry($column),
            default => $this->isNumericType($column->columnType)
                ? $this->buildNumericEntry($column)
                : $this->buildStringEntry($column),
        };
    }

    private function buildStringEntry(ColumnDefinition $column): string
    {
        $entry = "Infolists\\Components\\TextEntry::make('{$column->name}')";
        $entry .= $this->placeholderSuffix($column);

        return $entry;
    }

    private function buildNumericEntry(ColumnDefinition $column): string
    {
        $entry = "Infolists\\Components\\TextEntry::make('{$column->name}')";
        $entry .= "\n                    ->numeric()";
        $entry .= $this->placeholderSuffix($column);

        return $entry;
    }

    private function buildBooleanEntry(ColumnDefinition $column): string
    {
        return "Infolists\\Components\\IconEntry::make('{$column->name}')\n                    ->boolean()";
    }

    private function buildDateEntry(ColumnDefinition $column): string
    {
        $entry = "Infolists\\Components\\TextEntry::make('{$column->name}')";
        $entry .= "\n                    ->date()";
        $entry .= $this->placeholderSuffix($column);

        return $entry;
    }

    private function buildDateTimeEntry(ColumnDefinition $column): string
    {
        $entry = "Infolists\\Components\\TextEntry::make('{$column->name}')";
        $entry .= "\n                    ->dateTime()";
        $entry .= $this->placeholderSuffix($column);

        return $entry;
    }

    private function buildTimeEntry(ColumnDefinition $column): string
    {
        $entry = "Infolists\\Components\\TextEntry::make('{$column->name}')";
        $entry .= "\n                    ->time()";
        $entry .= $this->placeholderSuffix($column);

        return $entry;
    }

    private function buildLongTextEntry(ColumnDefinition $column): string
    {
        $entry = "Infolists\\Components\\TextEntry::make('{$column->name}')";
        $entry .= "\n                    ->columnSpanFull()";
        $entry .= $this->placeholderSuffix($column);

        return $entry;
    }

    private function buildJsonEntry(ColumnDefinition $column): string
    {
        return "Infolists\\Components\\TextEntry::make('{$column->name}')\n                    ->badge()";
    }

    private function buildEnumEntry(ColumnDefinition $column): string
    {
        return "Infolists\\Components\\TextEntry::make('{$column->name}')\n                    ->badge()";
    }

    private function buildCopyableEntry(ColumnDefinition $column): string
    {
        $entry = "Infolists\\Components\\TextEntry::make('{$column->name}')";
        $entry .= "\n                    ->copyable()";
        $entry .= $this->placeholderSuffix($column);

        return $entry;
    }

    private function placeholderSuffix(ColumnDefinition $column): string
    {
        return $column->nullable ? "\n                    ->placeholder('—')" : '';
    }
}
