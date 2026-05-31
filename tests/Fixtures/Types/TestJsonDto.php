<?php

namespace SchemaCraft\Tests\Fixtures\Types;

use SchemaCraft\DataSchema;
use SchemaCraft\Types\AbstractJsonDtoType;

/**
 * JSON-DTO fixture for the SDK golden test.
 *
 * Migrated to the DataSchema-backed form: the object shape lives in TestSpec
 * (a DataSchema), and the SDK reflects it to emit a typed TestSpecData nested
 * DTO instead of a bare `array`. Runtime hydrate/dehydrate is handled by the
 * base via dtoClass().
 */
class TestJsonDto extends AbstractJsonDtoType
{
    protected static function dtoClass(): string
    {
        return TestSpec::class;
    }
}

/**
 * The object shape stored in a TestJsonDto column.
 */
class TestSpec extends DataSchema
{
    public string $sku;

    public int $weight;

    public ?string $material;
}
