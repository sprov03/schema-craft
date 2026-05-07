<?php

namespace SchemaCraft\Generators\InputTypes;

use SchemaCraft\Generators\InputDefinition;

/**
 * Multi-select input — lets the user pick zero or more values from a predefined list.
 *
 * Resolves to string[] of selected option keys.
 * Use Input::multiSelect(label, options) to create.
 */
class MultiSelectInputType implements InputType
{
    public function resolve(mixed $rawValue, InputDefinition $definition, array $resolved): mixed
    {
        if (is_array($rawValue)) {
            return $rawValue;
        }

        return $definition->default ?? [];
    }

    public function toFrontend(InputDefinition $definition, array $resolved = []): array
    {
        return ['options' => $definition->options];
    }
}
