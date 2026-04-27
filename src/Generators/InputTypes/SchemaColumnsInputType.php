<?php

namespace SchemaCraft\Generators\InputTypes;

use SchemaCraft\Generators\GeneratorSchemaContext;
use SchemaCraft\Generators\InputDefinition;

class SchemaColumnsInputType implements InputType
{
    public function resolutionPass(): int
    {
        return 2;
    }

    public function resolve(mixed $rawValue, InputDefinition $definition, array $resolved): mixed
    {
        if (! is_array($rawValue)) {
            return [];
        }

        /** @var GeneratorSchemaContext|null $context */
        $context = $resolved[$definition->selectorKey ?? 'schema'] ?? null;

        if ($context === null) {
            return [];
        }

        return array_values(array_filter(array_map(function (string $name) use ($context) {
            foreach ($context->allColumns as $col) {
                if ($col->name === $name) {
                    return $col;
                }
            }

            return null;
        }, $rawValue)));
    }

    public function toFrontend(InputDefinition $definition): array
    {
        return ['selectorKey' => $definition->selectorKey];
    }
}
