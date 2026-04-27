<?php

namespace SchemaCraft\Generators\InputTypes;

use SchemaCraft\Generators\FilamentPanelDiscovery;
use SchemaCraft\Generators\InputDefinition;
use SchemaCraft\Generators\ResourceDirectoryValue;

class SelectResourceDirectoryInputType implements InputType
{
    public function resolutionPass(): int
    {
        return 2;
    }

    public function resolve(mixed $rawValue, InputDefinition $definition, array $resolved): mixed
    {
        return is_string($rawValue) && $rawValue !== ''
            ? new ResourceDirectoryValue($rawValue)
            : null;
    }

    public function toFrontend(InputDefinition $definition): array
    {
        return ['directories' => (new FilamentPanelDiscovery)->discover()];
    }
}
