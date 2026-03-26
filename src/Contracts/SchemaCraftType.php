<?php

namespace SchemaCraft\Contracts;

/**
 * Implement this interface on custom types (bitmasks, DTOs, value objects, etc.)
 * so SchemaCraft can resolve the correct database column type, modifiers, and
 * validation rules without needing attributes on every property.
 *
 * Types that DON'T need this interface:
 * - Native PHP types (string, int, float, bool, array) — already mapped
 * - BackedEnum — scanner reads the backing type via reflection
 * - CarbonInterface / DateTimeInterface — already mapped to timestamp
 *
 * Types that DO need this interface:
 * - Any class implementing CastsAttributes where the column is NOT json
 * - Any class where PHP's type system doesn't tell SchemaCraft the column type
 */
interface SchemaCraftType
{
    /**
     * The canonical database column type.
     *
     * Must return a valid Laravel Blueprint column type string.
     * Examples: 'mediumInteger', 'bigInteger', 'json', 'string', 'text'
     */
    public static function schemaColumnType(): string;

    /**
     * Column modifiers that apply to this type.
     *
     * Supported keys: 'unsigned' (bool), 'length' (int), 'precision' (int), 'scale' (int)
     *
     * @return array<string, mixed>
     */
    public static function schemaColumnModifiers(): array;

    /**
     * Validation rules for this type.
     *
     * These rules are used by the Actions system when generating validation.
     *
     * @return array<int, mixed>
     */
    public static function schemaValidationRules(): array;
}
