<?php

namespace SchemaCraft\Exceptions;

use RuntimeException;

class DataSchemaHydrationException extends RuntimeException
{
    public static function missingRequiredField(string $class, string $field): self
    {
        return new self(
            "Cannot hydrate [{$class}]: property [{$field}] is required (non-nullable, no default) but was not provided in the data."
        );
    }
}
