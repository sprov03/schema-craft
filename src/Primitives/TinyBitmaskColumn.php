<?php

namespace SchemaCraft\Primitives;

/**
 * Size-tier subclass: stored as UNSIGNED TINYINT (1 byte, 8 bits, max value 255).
 * Use when you have up to 8 distinct flags.
 */
abstract class TinyBitmaskColumn extends BitmaskColumn
{
    public static function schemaColumnType(): string
    {
        return 'tinyInteger';
    }

    public static function schemaColumnModifiers(): array
    {
        return ['unsigned' => true];
    }

    public static function schemaValidationRules(): array
    {
        return ['integer', 'min:0', 'max:255'];
    }
}
