<?php

namespace SchemaCraft\Contracts;

/**
 * Implement on any SchemaCraft column type that needs to control
 * how its value is serialized in API responses.
 *
 * The API Resource layer calls toApiRepresentation() instead of
 * returning the raw stored value, allowing types to transform their
 * representation (e.g. bitmask int → flags array, DTO → flat array).
 *
 * This is the outbound half of the API contract. The inbound half is ParsesApiInput.
 */
interface FormatsApiOutput
{
    /**
     * Return the value as it should appear in an API response.
     *
     * For scalar types, this is typically the same as toRaw().
     * For complex types (bitmask, DTO, collection), this returns
     * a client-friendly shape.
     */
    public function toApiRepresentation(): mixed;
}
