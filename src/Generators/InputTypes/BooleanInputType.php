<?php

namespace SchemaCraft\Generators\InputTypes;

use SchemaCraft\Generators\InputDefinition;

class BooleanInputType implements InputType
{
    public function resolutionPass(): int
    {
        return 2;
    }

    public function resolve(mixed $rawValue, InputDefinition $definition, array $resolved): mixed
    {
        return (bool) ($rawValue ?? $definition->default ?? false);
    }

    public function toFrontend(InputDefinition $definition): array
    {
        return [];
    }
}
