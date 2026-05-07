<?php

namespace SchemaCraft\Generators;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Support\Str;
use RuntimeException;
use SchemaCraft\Contracts\SchemaCraftColumn;
use SchemaCraft\Generator\FakerMethodMapper;
use SchemaCraft\Generator\Filament\FilamentColumnMapper;
use SchemaCraft\Generator\Filament\FilamentEntryMapper;
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

    private static ?FilamentEntryMapper $entryMapperInstance = null;

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
     * Returns true if this column is a foreign key column.
     *
     * A column is only a FK when TWO conditions are met:
     *   1. Its name ends with _id (naming convention)
     *   2. Its cast type is a plain integer built-in ('integer' / 'int')
     *
     * The second condition enforces the principle that explicit schema
     * documentation trumps naming conventions. If a developer declares
     * `public SomeEnum $loan_type_id` or `public CustomDto $record_id`,
     * the explicit PHP type in the schema is the source of truth — the
     * column is NOT a FK even though the name matches the pattern.
     *
     * Only a column with an integer cast can be a FK, because all real
     * FK columns reference an integer primary key. If the cast is a FQCN
     * (contains a backslash) or any other non-integer type, the schema has
     * explicitly documented something different and that wins.
     */
    public function isFK(): bool
    {
        if (! str_ends_with($this->definition->name, '_id')) {
            return false;
        }

        $cast = $this->definition->castType;

        // No explicit cast means nothing in the schema overrides the convention —
        // the naming convention alone is sufficient to identify this as a FK.
        if ($cast === null) {
            return true;
        }

        // Only a plain integer built-in cast is consistent with FK semantics.
        // Any FQCN cast (enum, DTO, custom type) means the schema has explicitly
        // documented a different type — that documentation overrides the naming
        // convention, and the column is NOT treated as a FK.
        return in_array($cast, ['integer', 'int'], true);
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

    /**
     * Returns true if this column's cast is a backed enum class.
     *
     * Used by templates/partials to opt into `->badge()` rendering without
     * having to reach into `$column->definition->castType`.
     */
    public function isEnum(): bool
    {
        $cast = $this->definition->castType;

        if ($cast === null || ! class_exists($cast)) {
            return false;
        }

        return is_subclass_of($cast, \BackedEnum::class);
    }

    /**
     * Returns the backed-enum FQCN when `isEnum()` is true, otherwise null.
     */
    public function enumClass(): ?string
    {
        return $this->isEnum() ? $this->definition->castType : null;
    }

    // ─── Mapper delegation ───────────────────────────────────────

    /**
     * Returns a Faker expression string for this column (e.g. '$faker->safeEmail()').
     *
     * If the cast type implements SchemaCraftColumn, its fakerExpression() is used.
     * BackedEnum is handled separately. Any other existing class that does NOT
     * implement SchemaCraftColumn throws — there is no silent fallback.
     */
    public function fakerValue(): string
    {
        $cast = $this->definition->castType;

        if ($cast !== null && class_exists($cast) && ! is_subclass_of($cast, \BackedEnum::class)) {
            if (is_subclass_of($cast, SchemaCraftColumn::class)) {
                return $cast::fakerExpression($this->definition);
            }

            if (is_subclass_of($cast, CastsAttributes::class)) {
                throw new RuntimeException(
                    "Cast class [{$cast}] must implement SchemaCraftColumn. "
                    .'Extend AbstractBitmaskType, AbstractJsonDtoType, or AbstractCollectionType, '
                    .'or implement SchemaCraftColumn directly. No fallback is provided.'
                );
            }
        }

        self::$fakerMapperInstance ??= new FakerMethodMapper;

        return self::$fakerMapperInstance->map($this->definition);
    }

    /**
     * Returns a trimmed Filament form field component string for this column.
     *
     * SchemaCraftColumn types must implement asFilamentField() themselves.
     * Any existing class that does NOT implement SchemaCraftColumn throws.
     */
    public function asFilamentField(): string
    {
        if ($custom = $this->getSchemaCraftColumnClass()) {
            return trim($custom::asFilamentField($this));
        }

        self::$fieldMapperInstance ??= new FilamentFieldMapper;

        return trim(self::$fieldMapperInstance->map($this->definition, ''));
    }

    /**
     * Returns a trimmed Filament table column component string for this column.
     *
     * SchemaCraftColumn types must implement asFilamentColumn() themselves.
     * Any existing class that does NOT implement SchemaCraftColumn throws.
     */
    public function asFilamentColumn(): string
    {
        if ($custom = $this->getSchemaCraftColumnClass()) {
            return trim($custom::asFilamentColumn($this));
        }

        self::$columnMapperInstance ??= new FilamentColumnMapper;

        return trim(self::$columnMapperInstance->map($this->definition, ''));
    }

    /**
     * Returns a trimmed Filament infolist entry component string for this column.
     *
     * SchemaCraftColumn types must implement asFilamentEntry() themselves.
     * Any existing class that does NOT implement SchemaCraftColumn throws.
     */
    public function asFilamentEntry(): string
    {
        if ($custom = $this->getSchemaCraftColumnClass()) {
            return trim($custom::asFilamentEntry($this));
        }

        self::$entryMapperInstance ??= new FilamentEntryMapper;

        return trim(self::$entryMapperInstance->map($this->definition, ''));
    }

    /**
     * Returns the cast class FQCN if it implements SchemaCraftColumn, else null.
     *
     * BackedEnum is excluded — it is handled by the built-in enum mappers.
     * Any other class that exists but does NOT implement SchemaCraftColumn throws
     * immediately rather than falling back to generic rendering.
     */
    private function getSchemaCraftColumnClass(): ?string
    {
        $cast = $this->definition->castType;

        if ($cast === null || ! class_exists($cast)) {
            return null;
        }

        if (is_subclass_of($cast, \BackedEnum::class)) {
            return null;
        }

        // Fully compliant — delegate directly.
        if (is_subclass_of($cast, SchemaCraftColumn::class)) {
            return $cast;
        }

        // Custom Eloquent cast that doesn't implement SchemaCraftColumn — throw.
        // Built-ins like DateTime and Carbon don't implement CastsAttributes and fall through.
        if (is_subclass_of($cast, CastsAttributes::class)) {
            throw new RuntimeException(
                "Cast class [{$cast}] must implement SchemaCraftColumn. "
                .'Extend AbstractBitmaskType, AbstractJsonDtoType, or AbstractCollectionType, '
                .'or implement SchemaCraftColumn directly. No fallback is provided.'
            );
        }

        return null;
    }
}
