<?php

namespace SchemaCraft\Generators\InputTypes;

use SchemaCraft\Generators\InputDefinition;

/**
 * Contract for generator input type handlers.
 *
 * Each input type is responsible for:
 *  - Describing itself to the frontend (toFrontend)
 *  - Resolving raw request data into a usable PHP value (resolve)
 *  - Declaring which resolution pass it belongs to (resolutionPass)
 *
 * Resolution passes allow dependent inputs to run after their dependencies:
 *  - Pass 1: independent inputs (e.g. schemaSelector scans the schema first)
 *  - Pass 2: inputs that depend on pass-1 results (e.g. schemaColumn references a schemaSelector)
 */
interface InputType
{
    /**
     * Resolution pass order.
     *
     * Return 1 for inputs that resolve independently.
     * Return 2 for inputs that depend on pass-1 resolved values.
     */
    public function resolutionPass(): int;

    /**
     * Resolve raw frontend input into a value available in Blade templates.
     *
     * @param  mixed  $rawValue  The raw value submitted from the frontend.
     * @param  InputDefinition  $definition  The input definition for this field.
     * @param  array<string, mixed>  $resolved  Values already resolved in earlier passes.
     */
    public function resolve(mixed $rawValue, InputDefinition $definition, array $resolved): mixed;

    /**
     * Return extra fields to merge into the frontend input definition payload.
     *
     * Base fields (key, label, type, default) are always included automatically.
     * Return type-specific fields here (e.g. options, selectColumns, panels).
     *
     * @return array<string, mixed>
     */
    public function toFrontend(InputDefinition $definition): array;
}
