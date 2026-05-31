<?php

namespace SchemaCraft\Tests\Fixtures\Types;

use SchemaCraft\DataSchema;
use SchemaCraft\Types\AbstractJsonDtoType;

/**
 * Models the catalog's `attributes_json` column as a typed shape rather than a bare array.
 *
 * The DB column stays json; this declares WHAT lives inside it (CatalogAttributes), so the
 * runtime casts to a typed object and the SDK emits a typed CatalogAttributesData instead of
 * `array`. This is the documented-to-the-bottom form the bare-array guard requires.
 */
class CatalogAttributesJson extends AbstractJsonDtoType
{
    protected static function dtoClass(): string
    {
        return CatalogAttributes::class;
    }
}

/** The typed object shape stored inside an attributes_json (CatalogAttributesJson) column. */
class CatalogAttributes extends DataSchema
{
    public ?string $color;

    public ?string $material;

    public ?int $weight_grams;
}
