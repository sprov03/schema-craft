<?php

namespace SchemaCraft\Generator\Sdk;

/**
 * One field of a synthesized SDK object shape (see SdkShape::synthesizedObject).
 *
 * Exists because the bitmask type has no DataSchema to reflect — its {value, flags}
 * wire shape is built by hand from flags(). Each field carries either a scalar type
 * hint OR a nested SdkShape (e.g. the `flags` field is itself a synthesized object
 * with one bool per flag), so the generator can recurse the same way it does for
 * reflected DataSchemas and document the shape all the way to the bottom.
 */
final class SdkShapeField
{
    private function __construct(
        public readonly string $name,
        public readonly bool $nullable,
        public readonly ?string $scalarType,
        public readonly ?SdkShape $shape,
    ) {}

    public static function scalar(string $name, string $type, bool $nullable = false): self
    {
        return new self(name: $name, nullable: $nullable, scalarType: $type, shape: null);
    }

    public static function nested(string $name, SdkShape $shape, bool $nullable = false): self
    {
        return new self(name: $name, nullable: $nullable, scalarType: null, shape: $shape);
    }
}
