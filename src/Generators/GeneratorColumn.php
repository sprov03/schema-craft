<?php

namespace SchemaCraft\Generators;

use Illuminate\Support\Str;
use SchemaCraft\Generator\FakerMethodMapper;
use SchemaCraft\Generator\Filament\FilamentColumnMapper;
use SchemaCraft\Generator\Filament\FilamentFieldMapper;
use SchemaCraft\Scanner\ColumnDefinition;

/**
 * Wraps a ColumnDefinition with code-generation helper methods for use in Blade generator templates.
 *
 * All ColumnDefinition properties are accessible directly (e.g. $column->name, $column->nullable)
 * via __get() magic. Additional helper methods provide common code-generation patterns.
 */
class GeneratorColumn
{
    private static ?FakerMethodMapper $fakerMapperInstance = null;

    private static ?FilamentFieldMapper $fieldMapperInstance = null;

    private static ?FilamentColumnMapper $columnMapperInstance = null;

    public function __construct(public readonly ColumnDefinition $definition) {}

    /**
     * Forward property reads to the wrapped ColumnDefinition.
     */
    public function __get(string $name): mixed
    {
        return $this->definition->$name;
    }

    public function __isset(string $name): bool
    {
        return isset($this->definition->$name);
    }

    // ─── PHP type helpers ────────────────────────────────────────

    /**
     * Returns the PHP type hint for this column (e.g. 'string', 'int', 'bool').
     */
    public function phpType(): string
    {
        return match ($this->definition->columnType) {
            'boolean' => 'bool',
            'integer', 'bigInteger', 'smallInteger', 'tinyInteger',
            'unsignedBigInteger', 'unsignedInteger', 'unsignedSmallInteger', 'unsignedTinyInteger' => 'int',
            'decimal', 'float', 'double' => 'float',
            'json' => 'array',
            'date', 'timestamp', 'dateTime', 'dateTimeTz' => 'CarbonInterface',
            default => 'string',
        };
    }

    /**
     * Returns the nullable PHP type hint (e.g. '?string' for nullable columns).
     */
    public function phpTypeNullable(): string
    {
        $type = $this->phpType();

        return $this->definition->nullable ? '?'.$type : $type;
    }

    // ─── Name helpers ────────────────────────────────────────────

    public function camelName(): string
    {
        return Str::camel($this->definition->name);
    }

    public function studlyName(): string
    {
        return Str::studly($this->definition->name);
    }

    public function humanName(): string
    {
        return Str::headline($this->definition->name);
    }

    // ─── Code generation helpers ─────────────────────────────────

    /**
     * Returns a typed method parameter string, e.g. 'string $name' or '?string $name = null'.
     */
    public function asMethodParam(): string
    {
        $type = $this->phpType();
        $var = '$'.$this->camelName();

        if ($this->definition->nullable) {
            return '?'.$type.' '.$var.' = null';
        }

        return $type.' '.$var;
    }

    /**
     * Returns an assignment statement for this column.
     *
     * For FK columns (_id suffix): uses ->associate() / ->dissociate() pattern.
     * For regular columns: uses direct property assignment.
     */
    public function asAssignment(string $modelVar = '$model'): string
    {
        if ($this->isFK()) {
            $rel = $this->relationshipName();
            $param = '$'.$rel;

            if ($this->definition->nullable) {
                return "if ({$param} !== null) {\n    {$modelVar}->{$rel}()->associate({$param});\n} else {\n    {$modelVar}->{$rel}()->dissociate();\n}";
            }

            return "{$modelVar}->{$rel}()->associate({$param});";
        }

        return "{$modelVar}->{$this->definition->name} = \${$this->camelName()};";
    }

    /**
     * Returns true if this column is a foreign key column (ends with _id).
     */
    public function isFK(): bool
    {
        return str_ends_with($this->definition->name, '_id');
    }

    /**
     * Returns true if this column is marked as a primary key.
     */
    public function isPrimary(): bool
    {
        return $this->definition->primary;
    }

    /**
     * Returns true if this column is a Laravel timestamps column (created_at / updated_at).
     */
    public function isTimestamp(): bool
    {
        return in_array($this->definition->name, ['created_at', 'updated_at'], true);
    }

    /**
     * Returns true if this column is the soft-delete column (deleted_at).
     */
    public function isSoftDelete(): bool
    {
        return $this->definition->name === 'deleted_at';
    }

    /**
     * Returns the relationship name for FK columns (strips _id suffix, camelCase).
     * e.g. 'owner_id' → 'owner', 'created_by_user_id' → 'createdByUser'
     */
    public function relationshipName(): string
    {
        return Str::camel(substr($this->definition->name, 0, -3));
    }

    // ─── Mapper delegation ───────────────────────────────────────

    /**
     * Returns a Faker expression string for this column (e.g. '$faker->safeEmail()').
     */
    public function fakerValue(): string
    {
        self::$fakerMapperInstance ??= new FakerMethodMapper;

        return self::$fakerMapperInstance->map($this->definition);
    }

    /**
     * Returns a trimmed Filament form field component string for this column.
     */
    public function asFilamentField(): string
    {
        self::$fieldMapperInstance ??= new FilamentFieldMapper;

        return trim(self::$fieldMapperInstance->map($this->definition, ''));
    }

    /**
     * Returns a trimmed Filament table column component string for this column.
     */
    public function asFilamentColumn(): string
    {
        self::$columnMapperInstance ??= new FilamentColumnMapper;

        return trim(self::$columnMapperInstance->map($this->definition, ''));
    }
}
