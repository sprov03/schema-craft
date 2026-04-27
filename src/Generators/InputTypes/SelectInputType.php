<?php

namespace SchemaCraft\Generators\InputTypes;

use SchemaCraft\Generators\InputDefinition;

class SelectInputType implements InputType
{
    public function resolutionPass(): int
    {
        return 2;
    }

    public function resolve(mixed $rawValue, InputDefinition $definition, array $resolved): mixed
    {
        return $rawValue ?? $definition->default;
    }

    public function toFrontend(InputDefinition $definition): array
    {
        return ['options' => $definition->options];
    }
}
