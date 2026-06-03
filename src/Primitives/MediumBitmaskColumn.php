<?php

namespace SchemaCraft\Primitives;

/**
 * Size-tier subclass: stored as UNSIGNED MEDIUMINT (3 bytes, 24 bits, max value 16,777,215).
 * Use when you have between 9 and 24 distinct flags.
 */
abstract class MediumBitmaskColumn extends BitmaskColumn
{
    public static function schemaColumnType(): string
    {
        return 'mediumInteger';
    }

    public static function schemaColumnModifiers(): array
    {
        return ['unsigned' => true];
    }

    public static function schemaValidationRules(): array
    {
        return ['integer', 'min:0', 'max:16777215'];
    }
}
