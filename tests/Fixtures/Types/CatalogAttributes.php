<?php

namespace SchemaCraft\Tests\Fixtures\Types;

use SchemaCraft\DataSchema;

/**
 * Typed object shape for Catalog's `attributes_json` column. The DataSchema IS the column's
 * shape declaration — no wrapper class. The schema scanner detects properties typed as a
 * DataSchema subclass and wires the DataSchema-as-column-type pattern automatically.
 */
class CatalogAttributes extends DataSchema
{
    public ?string $color;

    public ?string $material;

    public ?int $weight_grams;
}
