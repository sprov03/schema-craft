<?php

namespace SchemaCraft\Tests\Fixtures\Types;

use SchemaCraft\Primitives\JsonColumn;

/**
 * Typed JSON column for Catalog's `attributes_json` column. Extends the JsonColumn
 * primitive — class identity declares "this is a DB JSON column," the typed properties
 * declare the shape, the primitive provides the cast + generator dispatch surface.
 */
class CatalogAttributes extends JsonColumn
{
    public ?string $color;

    public ?string $material;

    public ?int $weight_grams;
}
