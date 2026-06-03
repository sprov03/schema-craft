<?php

namespace SchemaCraft\Contracts;

/**
 * Implement on SchemaCraft column types to declare the PHP type hint
 * used in generated SDK Data Transfer Objects.
 *
 * The SDK DTO (SdkDataGenerator) uses this to emit a correct @param
 * and @var type annotation for columns of this type. Without this
 * the generator cannot know what PHP type to use for a custom class.
 *
 * Note: shape declaration (for typed nested DTOs) is no longer part of this
 * contract. The framework introspects recognized primitives directly —
 * Bitmask subclasses, DataSchema-typed properties, and AbstractCollectionType
 * subclasses — via SdkShape::forType(). Type authors only declare the data
 * they already have (DataSchema class, flag constants) once; the framework
 * derives the SDK shape from that.
 */
interface GeneratesSdkType
{
    /**
     * Return the PHP type hint string for use in SDK-generated PHPDoc.
     *
     * Examples: 'int', 'string', 'float', 'bool', 'array', 'mixed'
     * Use 'array' for any type that serializes as a JSON object or array.
     */
    public static function sdkType(): string;
}
