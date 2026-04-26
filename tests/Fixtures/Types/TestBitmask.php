<?php

namespace SchemaCraft\Tests\Fixtures\Types;

use SchemaCraft\Types\AbstractBitmaskType;

class TestBitmask extends AbstractBitmaskType
{
    protected static function flags(): array
    {
        return ['READ' => 1, 'WRITE' => 2, 'EXECUTE' => 4];
    }

    // Override the abstract base defaults so scanner tests keep their expected values.
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
        return ['integer', 'min:0'];
    }
}
