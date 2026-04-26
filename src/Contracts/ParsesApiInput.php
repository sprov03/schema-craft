<?php

namespace SchemaCraft\Contracts;

/**
 * Implement on any SchemaCraft column type that accepts structured input
 * from API requests.
 *
 * The API contract sends and receives the same shape: whatever
 * toApiRepresentation() produces is the only accepted input format.
 * This means clients never need to know the raw DB representation.
 *
 * This is the inbound half of the API contract. The outbound half is FormatsApiOutput.
 */
interface ParsesApiInput
{
    /**
     * Create an instance from the API request payload.
     *
     * The $input shape must match what toApiRepresentation() returns.
     * Throw \InvalidArgumentException for malformed input rather than silently
     * producing a zero-value instance.
     */
    public static function fromApiInput(mixed $input): static;
}
