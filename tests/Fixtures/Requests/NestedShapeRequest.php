<?php

namespace SchemaCraft\Tests\Fixtures\Requests;

use SchemaCraft\Request;
use SchemaCraft\Tests\Fixtures\Types\CatalogAttributes;

/**
 * Request with a nested shape property (CatalogAttributes is a JsonColumn → DataSchema).
 * Exercises nested-JSON validation: the nested object must validate as an array with
 * dot-notation rules for its inner fields, not as a scalar string.
 */
class NestedShapeRequest extends Request
{
    public string $label;

    public CatalogAttributes $attributes;
}
