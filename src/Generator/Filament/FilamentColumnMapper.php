<?php

namespace SchemaCraft\Generator\Filament;

use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Scanner\RelationshipDefinition;

/**
 * Maps a ColumnDefinition to a Filament table column code string.
 */
class FilamentColumnMapper
{
    /**
     * Map a column to its Filament table column code.
     */
    public function map(ColumnDefinition $column, string $indent = '                '): string
    {
        $tableColumn = $this->resolveColumnType($column);

        return $indent.$tableColumn;
    }

    /**
     * Map a BelongsTo relationship to a table column showing the related model's display value.
     */
    public function mapBelongsTo(
        RelationshipDefinition $relationship,
        string $indent = '                ',
        string $titleColumn = 'name',
    ): string {
        $column = "Tables\\Columns\\TextColumn::make('{$relationship->name}.{$titleColumn}')";
        $column .= "\n{$indent}    ->sortable()";

        return $indent.$column;
    }

    /**
     * Generate a timestamp column for created_at/updated_at.
     */
    public function mapTimestamp(string $name, string $indent = '                '): string
    {
        $column = "Tables\\Columns\\TextColumn::make('{$name}')";
        $column .= "\n{$indent}    ->dateTime()";
        $column .= "\n{$indent}    ->sortable()";
        $column .= "\n{$indent}    ->toggleable(isToggledHiddenByDefault: true)";

        return $indent.$column;
    }

    private function resolveColumnType(ColumnDefinition $column): string
    {
        // Check for enum cast
        if ($this->isEnumCast($column)) {
            return $this->buildEnumColumn($column);
        }

        return match ($column->columnType) {
            'boolean' => $this->buildBooleanColumn($column),
            'date' => $this->buildDateColumn($column),
            'timestamp', 'dateTime', 'dateTimeTz' => $this->buildDateTimeColumn($column),
            'time' => $this->buildTimeColumn($column),
            'text', 'mediumText', 'longText' => $this->buildTextColumn($column, limit: true),
            'json' => $this->buildJsonColumn($column),
            'uuid', 'ulid' => $this->buildCopyableColumn($column),
            default => $this->isNumericType($column->columnType)
                ? $this->buildNumericColumn($column)
                : $this->buildSearchableColumn($column),
        };
    }

    private function buildSearchableColumn(ColumnDefinition $column): string
    {
        $col = "Tables\\Columns\\TextColumn::make('{$column->name}')";
        $col .= "\n                    ->searchable()";
        $col .= "\n                    ->sortable()";

        return $col;
    }

    private function buildNumericColumn(ColumnDefinition $column): string
    {
        $col = "Tables\\Columns\\TextColumn::make('{$column->name}')";
        $col .= "\n                    ->numeric()";
        $col .= "\n                    ->sortable()";

        return $col;
    }

    private function buildBooleanColumn(ColumnDefinition $column): string
    {
        return "Tables\\Columns\\IconColumn::make('{$column->name}')\n                    ->boolean()";
    }

    private function buildDateColumn(ColumnDefinition $column): string
    {
        $col = "Tables\\Columns\\TextColumn::make('{$column->name}')";
        $col .= "\n                    ->date()";
        $col .= "\n                    ->sortable()";

        return $col;
    }

    private function buildDateTimeColumn(ColumnDefinition $column): string
    {
        $col = "Tables\\Columns\\TextColumn::make('{$column->name}')";
        $col .= "\n                    ->dateTime()";
        $col .= "\n                    ->sortable()";

        return $col;
    }

    private function buildTimeColumn(ColumnDefinition $column): string
    {
        $col = "Tables\\Columns\\TextColumn::make('{$column->name}')";
        $col .= "\n                    ->time()";

        return $col;
    }

    private function buildTextColumn(ColumnDefinition $column, bool $limit = false): string
    {
        $col = "Tables\\Columns\\TextColumn::make('{$column->name}')";

        if ($limit) {
            $col .= "\n                    ->limit(50)";
        }

        return $col;
    }

    private function buildJsonColumn(ColumnDefinition $column): string
    {
        return "Tables\\Columns\\TextColumn::make('{$column->name}')\n                    ->badge()";
    }

    private function buildEnumColumn(ColumnDefinition $column): string
    {
        return "Tables\\Columns\\TextColumn::make('{$column->name}')\n                    ->badge()";
    }

    private function buildCopyableColumn(ColumnDefinition $column): string
    {
        $col = "Tables\\Columns\\TextColumn::make('{$column->name}')";
        $col .= "\n                    ->copyable()";

        return $col;
    }

    private function isEnumCast(ColumnDefinition $column): bool
    {
        if ($column->castType === null) {
            return false;
        }

        $builtInCasts = ['string', 'integer', 'int', 'float', 'double', 'boolean', 'bool', 'array', 'json', 'object', 'datetime', 'date', 'timestamp', 'collection', 'encrypted'];

        return ! in_array($column->castType, $builtInCasts, true)
            && ! str_starts_with($column->castType, 'decimal:');
    }

    private function isNumericType(string $columnType): bool
    {
        return in_array($columnType, [
            'integer', 'bigInteger', 'smallInteger', 'tinyInteger',
            'unsignedBigInteger', 'unsignedInteger', 'unsignedSmallInteger', 'unsignedTinyInteger',
            'decimal', 'float', 'double', 'year',
        ], true);
    }
}
