<?php

namespace SchemaCraft\Generators\InputTypes;

use SchemaCraft\Generators\GeneratorSchemaContext;
use SchemaCraft\Generators\InputDefinition;

/**
 * A searchable column picker that also accepts free-text values.
 *
 * Renders as a combobox (text input + datalist) pre-populated with the
 * columns from a schemaSelector step. The user can filter by typing or
 * enter any custom value not in the list.
 *
 * Resolves to a plain string — either a column name or whatever the user typed.
 *
 * Usage:
 *
 *     'title_attribute' => fn($data) => Input::schemaColumnCombobox('Title Attribute')
 *         ->default($data['schema']?->titleColumn?->name ?? 'id')
 *         ->description('Column used as the record label in Filament.')
 */
class SchemaColumnComboboxInputType implements InputType
{
    public function resolve(mixed $rawValue, InputDefinition $definition, array $resolved): mixed
    {
        return is_string($rawValue) ? $rawValue : '';
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
            ], $context->allColumns);
        }

        return [
            'selectorKey' => $selectorKey,
            'columns' => $columns,
        ];
    }
}
