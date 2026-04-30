<?php

namespace SchemaCraft\Generators\InputTypes;

use SchemaCraft\Generators\GeneratorSchemaContext;
use SchemaCraft\Generators\InputDefinition;

class SchemaColumnInputType implements InputType
{
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

    public function toFrontend(InputDefinition $definition, array $resolved = []): array
    {
        $selectorKey = $definition->selectorKey ?? 'schema';
        $context = $resolved[$selectorKey] ?? null;

        $columns = [];

        if ($context instanceof GeneratorSchemaContext) {
            $columns = array_map(fn ($col) => [
                'name' => $col->name,
                'type' => $col->columnType,
                'nullable' => $col->nullable,
            ], $context->allColumns);
        }

        return [
            'selectorKey' => $selectorKey,
            'columns' => $columns,
        ];
    }
}
