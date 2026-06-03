<?php

namespace SchemaCraft\Attributes;

use Attribute;

/**
 * Declares the item type for a collection-typed property.
 *
 * PHP has no way to express the item type of a collection at the property-type level
 * (no generics — `Collection<X>` is not reflectable at runtime). This attribute fills
 * that single language gap. Used at every layer that documents object shape:
 *
 *   - Schema columns: `#[CollectionOf(PricePoint::class)] public Collection $price_history`
 *     wires the framework's Collection primitive (SchemaCraft\Primitives\Collection) and lets the SDK pipeline emit
 *     a typed PricePointData[].
 *
 *   - Resource properties: `#[CollectionOf(CatalogVariantResource::class)] public Collection $variants`
 *     drives the Resource walker's collection-detection and the toArray() serialization.
 *
 *   - DataSchema properties (nested collections inside an object shape): same shape, same
 *     read-attribute logic, item type goes into the field descriptor for the SDK pipeline.
 *
 * Singular references do NOT use this attribute. The property type alone carries
 * everything needed (e.g. `public ?AddressShape $address;` — target FQCN, cardinality
 * (singular), and nullability are all in the type). Adding a parallel singular attribute
 * would be redundant.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_CLASS)]
class CollectionOf
{
    public function __construct(
        /**
         * The item type. Named `resource` to keep backward compatibility with the
         * original Resource-side spelling; the field carries DataSchema class names
         * for Schema columns and Resource class names for Resource properties — both
         * are valid uses.
         */
        public readonly string $resource,
    ) {}
}
