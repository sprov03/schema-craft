<?php

namespace SchemaCraft\Generator\Filament;

use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Scanner\RelationshipDefinition;

/**
 * Maps a ColumnDefinition to a Filament form field code string.
 */
class FilamentFieldMapper
{
    /**
     * Map a column to its Filament form field code.
     */
    public function map(ColumnDefinition $column, string $indent = '                '): string
    {
        $field = $this->resolveFieldType($column);

        return $indent.$field;
    }

    /**
     * Map a BelongsTo relationship to a Select field with relationship binding.
     */
    public function mapBelongsTo(
        RelationshipDefinition $relationship,
        string $indent = '                ',
        string $titleColumn = 'name',
    ): string {
        $foreignColumn = $relationship->foreignColumn ?? ($relationship->name.'_id');
        $field = "Components\\Select::make('{$foreignColumn}')";
        $field .= "\n{$indent}    ->relationship('{$relationship->name}', '{$titleColumn}')";

        if ($relationship->nullable) {
            $field .= "\n{$indent}    ->nullable()";
        } else {
            $field .= "\n{$indent}    ->required()";
        }

        $field .= "\n{$indent}    ->searchable()";
        $field .= "\n{$indent}    ->preload()";

        return $indent.$field;
    }

    private function resolveFieldType(ColumnDefinition $column): string
    {
        // Check for enum cast
        if ($this->isEnumCast($column)) {
            return $this->buildEnumSelect($column);
        }

        return match ($column->columnType) {
            'text', 'mediumText', 'longText' => $this->buildTextarea($column),
            'boolean' => $this->buildToggle($column),
            'date' => $this->buildDatePicker($column),
            'timestamp', 'dateTime', 'dateTimeTz' => $this->buildDateTimePicker($column),
            'time' => $this->buildTimePicker($column),
            'json' => $this->buildKeyValue($column),
            default => $this->buildTextInput($column),
        };
    }

    private function buildTextInput(ColumnDefinition $column): string
    {
        $field = "Components\\TextInput::make('{$column->name}')";

        if ($this->isNumericType($column->columnType)) {
            $field .= "\n                    ->numeric()";
        }

        if (! $column->nullable) {
            $field .= "\n                    ->required()";
        }

        if ($column->length !== null) {
            $field .= "\n                    ->maxLength({$column->length})";
        } elseif ($column->columnType === 'string') {
            $field .= "\n                    ->maxLength(255)";
        }

        if ($column->unique) {
            $field .= "\n                    ->unique(ignoreRecord: true)";
        }

        return $field;
    }

    private function buildTextarea(ColumnDefinition $column): string
    {
        $field = "Components\\Textarea::make('{$column->name}')";

        if (! $column->nullable) {
            $field .= "\n                    ->required()";
        }

        $maxLength = match ($column->columnType) {
            'mediumText' => 16777215,
            'longText' => null,
            default => 65535,
        };

        if ($maxLength !== null) {
            $field .= "\n                    ->maxLength({$maxLength})";
        }

        return $field;
    }

    private function buildToggle(ColumnDefinition $column): string
    {
        $field = "Components\\Toggle::make('{$column->name}')";

        if (! $column->nullable) {
            $field .= "\n                    ->required()";
        }

        return $field;
    }

    private function buildDatePicker(ColumnDefinition $column): string
    {
        $field = "Components\\DatePicker::make('{$column->name}')";

        if (! $column->nullable) {
            $field .= "\n                    ->required()";
        }

        return $field;
    }

    private function buildDateTimePicker(ColumnDefinition $column): string
    {
        $field = "Components\\DateTimePicker::make('{$column->name}')";

        if (! $column->nullable) {
            $field .= "\n                    ->required()";
        }

        return $field;
    }

    private function buildTimePicker(ColumnDefinition $column): string
    {
        $field = "Components\\TimePicker::make('{$column->name}')";

        if (! $column->nullable) {
            $field .= "\n                    ->required()";
        }

        return $field;
    }

    private function buildKeyValue(ColumnDefinition $column): string
    {
        $field = "Components\\KeyValue::make('{$column->name}')";

        if (! $column->nullable) {
            $field .= "\n                    ->required()";
        }

        return $field;
    }

    private function buildEnumSelect(ColumnDefinition $column): string
    {
        $enumClass = $column->castType;
        $field = "Components\\Select::make('{$column->name}')";
        $field .= "\n                    ->options({$enumClass}::class)";

        if (! $column->nullable) {
            $field .= "\n                    ->required()";
        }

        return $field;
    }

    private function isEnumCast(ColumnDefinition $column): bool
    {
        if ($column->castType === null) {
            return false;
        }

        // Enum casts are FQCN of BackedEnum classes (contain backslash or are not built-in types)
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
