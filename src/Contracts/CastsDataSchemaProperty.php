<?php

namespace SchemaCraft\Contracts;

/**
 * Contract for custom types that can be used as DataSchema properties.
 *
 * Implement this on any class that needs to be hydrated from a raw
 * value (string, int, etc.) and dehydrated back for serialization.
 *
 * Examples: bitmask columns, value objects, custom enums with extra logic.
 *
 * Types that are handled automatically without this interface:
 * - DataSchema subclasses (recursive fromArray/toArray)
 * - BackedEnum (PHP native ::from() / ->value)
 * - DateTimeInterface (Carbon::parse() / ISO 8601)
 */
interface CastsDataSchemaProperty
{
    /**
     * Create an instance from the raw stored/transmitted value.
     */
    public static function fromRaw(mixed $value): static;

    /**
     * Convert the instance back to its raw stored/transmitted value.
     */
    public function toRaw(): mixed;
}
