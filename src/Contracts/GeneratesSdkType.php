<?php

namespace SchemaCraft\Contracts;

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
     */
    public static function sdkType(): string;
}
