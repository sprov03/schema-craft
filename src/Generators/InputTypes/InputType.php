<?php

namespace SchemaCraft\Generators\InputTypes;

use SchemaCraft\Generators\InputDefinition;

/**
 * Contract for generator input type handlers.
 *
 * Each input type is responsible for:
 *  - Describing itself to the frontend via toFrontend()
 *  - Resolving raw accumulated data into a usable PHP value via resolve()
 *
 * The wizard calls toFrontend() with the current resolved context so that
 * dependent inputs (e.g. schemaColumn) can populate their options from
 * prior-step results without a separate API call.
 */
interface InputType
{
    /**
     * Resolve raw frontend data into the PHP value available in Blade templates.
     *
     * @param  mixed  $rawValue  The raw value from the accumulated payload.
     * @param  InputDefinition  $definition  The input definition for this field.
     * @param  array<string, mixed>  $resolved  Values already resolved from prior steps.
     */
    public function resolve(mixed $rawValue, InputDefinition $definition, array $resolved): mixed;

    /**
     * Return the frontend descriptor for this step.
     *
     * Base fields (key, label, type, default, description, help) are included
     * automatically by the controller. Return only type-specific fields here
     * (e.g. options, schemas, columns, groups).
     *
     * @param  array<string, mixed>  $resolved  Currently resolved data — lets dependent
     *                                          inputs (e.g. schemaColumn) populate their
     *                                          options from the already-resolved schema context.
     * @return array<string, mixed>
     */
    public function toFrontend(InputDefinition $definition, array $resolved = []): array;
}
