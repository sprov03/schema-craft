<?php

namespace SchemaCraft\Generators\InputTypes;

use SchemaCraft\Generators\GeneratorSchemaContext;
use SchemaCraft\Generators\InputDefinition;
use SchemaCraft\Scanner\SchemaScanner;

class SchemaSelectorInputType implements InputType
{
    public function resolutionPass(): int
    {
        return 1;
    }

    public function resolve(mixed $rawValue, InputDefinition $definition, array $resolved): mixed
    {
        if (! $rawValue || empty($rawValue['class']) || ! class_exists($rawValue['class'])) {
            return null;
        }

        $table = (new SchemaScanner($rawValue['class']))->scan();

        $selectedColumns = $definition->selectColumns
            ? ($rawValue['selectedColumns'] ?? [])
            : null;

        $selectedRelationships = $definition->selectRelationships
            ? ($rawValue['selectedRelationships'] ?? [])
            : null;

        return new GeneratorSchemaContext($table, $selectedColumns, $selectedRelationships);
    }

    public function toFrontend(InputDefinition $definition): array
    {
        return [
            'selectColumns' => $definition->selectColumns,
            'selectRelationships' => $definition->selectRelationships,
            'selectorKey' => $definition->selectorKey,
        ];
    }
}
