<?php

namespace SchemaCraft\Contracts;

use SchemaCraft\Generator\Sdk\SdkShape;

/**
 * Implement on SchemaCraft column types to declare the PHP type hint
 * used in generated SDK Data Transfer Objects.
 *
 * The SDK DTO (SdkDataGenerator) uses this to emit a correct @param
 * and @var type annotation for columns of this type. Without this
 * the generator cannot know what PHP type to use for a custom class.
 */
interface GeneratesSdkType
{
    /**
     * Return the PHP type hint string for use in SDK-generated PHPDoc.
     *
     * Examples: 'int', 'string', 'float', 'bool', 'array', 'mixed'
     * Use 'array' for any type that serializes as a JSON object or array.
     *
     * Retained for back-compat with any caller that only needs a flat hint;
     * the richer sdkShape() below is what drives typed nested DTO generation.
     */
    public static function sdkType(): string;

    /**
     * Return the structured SDK shape — scalar, object, or collection.
     *
     * This is what lets the generator emit a TYPED nested DTO ({X}Data /
     * {X}Data[]) instead of a bare `array`: the shape names the backing
     * DataSchema (or an explicit synthesized field list) so the generator
     * can recurse and document the type all the way to the bottom.
     */
    public static function sdkShape(): SdkShape;
}
