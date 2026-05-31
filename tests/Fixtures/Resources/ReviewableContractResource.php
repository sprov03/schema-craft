<?php

namespace SchemaCraft\Tests\Fixtures\Resources;

use SchemaCraft\SchemaCraftResource;

/**
 * Contract Resource for the morphTo `reviewable` field — declares the shape every morph
 * target is expected to expose.
 *
 * Why schema-less: this Resource isn't backed by a Schema; it's the typed common-fields
 * contract a polymorphic field is typed against. Target Resources (Catalog, CatalogVariant,
 * …) are trusted to expose these fields — that trust gap is what the discriminated-union
 * task is meant to close.
 */
class ReviewableContractResource extends SchemaCraftResource
{
    public int $id;

    public string $name;
}
