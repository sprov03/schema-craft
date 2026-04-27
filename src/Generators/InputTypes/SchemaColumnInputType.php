<?php

namespace SchemaCraft\Generators\InputTypes;

use SchemaCraft\Generators\GeneratorSchemaContext;
use SchemaCraft\Generators\InputDefinition;

class SchemaColumnInputType implements InputType
{
    public function resolutionPass(): int
    {
        return 2;
    }

    public function resolve(mixed $rawValue, InputDefinition $definition, array $resolved): mixed
    {
        if (! is_string($rawValue) || $rawValue === '') {
            return null;
        }

        /** @var GeneratorSchemaContext|null $context */
        $context = $resolved[$definition->selectorKey ?? 'schema'] ?? null;

        if ($context === null) {
            return null;
        }

        foreach ($context->allColumns as $col) {
            if ($col->name === $rawValue) {
                return $col;
            }
        }

        return null;
    }

    public function toFrontend(InputDefinition $definition): array
    {
        return ['selectorKey' => $definition->selectorKey];
    }
}
