<?php

namespace SchemaCraft\Generators\InputTypes;

use SchemaCraft\Generators\InputDefinition;

class TextInputType implements InputType
{
    public function resolve(mixed $rawValue, InputDefinition $definition, array $resolved): mixed
    {
        return $rawValue ?? $definition->default ?? '';
    }

    public function toFrontend(InputDefinition $definition, array $resolved = []): array
    {
        return [];
    }
}
