<?php

namespace SchemaCraft\Attributes\Resources;

use Attribute;

/**
 * Declares the item type for a collection-typed Resource property.
 *
 * Why this exists at the Resource layer: PHP has no way to express the item type of a
 * collection at the property-type level (no generics — `Collection<X>` is not reflectable
 * at runtime). This attribute fills that single language gap. It does not encode Laravel
 * relationship mechanics (hasMany vs belongsToMany vs morphMany vs hasManyThrough) — the
 * Resource layer doesn't care which DB-side mechanic produces the collection; it only
 * cares "this property is a collection of X resources."
 *
 * Singular relationships do NOT use this attribute. The property type alone carries
 * everything needed (e.g. `public ?CatalogBrandResource $brand;` — target FQCN, cardinality
 * (singular), and nullability are all in the type). Adding a parallel singular attribute
 * would be redundant.
 *
 * Example:
 *   // Singular — no attribute needed
 *   public ?CatalogBrandResource $brand;
 *
 *   // Collection — attribute supplies the item type
 *   #[CollectionOf(CatalogVariantResource::class)]
 *   public Collection $variants;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class CollectionOf
{
    public function __construct(
        public readonly string $resource,
    ) {}
}
