<?php

namespace SchemaCraft\Primitives;

/**
 * Size-tier subclass: stored as UNSIGNED INT (4 bytes, 32 bits, max value 4,294,967,295).
 * Use when you have between 25 and 32 distinct flags.
 */
abstract class LargeBitmask extends Bitmask
{
    public static function schemaColumnType(): string
    {
        return 'integer';
    }

    public static function schemaColumnModifiers(): array
    {
        return ['unsigned' => true];
    }

    public static function schemaValidationRules(): array
    {
        return ['integer', 'min:0', 'max:4294967295'];
    }
}
