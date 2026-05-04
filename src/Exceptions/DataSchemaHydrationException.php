<?php

namespace SchemaCraft\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class DataSchemaHydrationException extends RuntimeException
{
    public static function missingRequiredField(string $class, string $field): self
    {
        return new self(
            "Cannot hydrate [{$class}]: property [{$field}] is required (non-nullable, no default) but was not provided in the data."
        );
    }

    /**
     * Render as 422 Unprocessable Entity rather than 500.
     *
     * ApiResponseMiddleware detects the 422 status code and reformats the body
     * into the standard error envelope, keeping hydration failures in the same
     * validation-error category rather than treating them as server errors.
     */
    public function render(): JsonResponse
    {
        return new JsonResponse(['message' => $this->getMessage(), 'errors' => []], 422);
    }
}
