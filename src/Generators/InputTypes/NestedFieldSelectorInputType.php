<?php

namespace SchemaCraft\Generators\InputTypes;

use SchemaCraft\Generators\GeneratorColumn;
use SchemaCraft\Generators\GeneratorSchemaContext;
use SchemaCraft\Generators\InputDefinition;
use SchemaCraft\Generators\NestedFieldSelection;
use SchemaCraft\Generators\NestedRelationshipSelection;
use SchemaCraft\Scanner\SchemaResolver;
use SchemaCraft\Scanner\SchemaScanner;

/**
 * Renders a tree of columns + expandable relationships for defining the nested
 * data requirements of a generator (e.g. an Action class).
 *
 * The user picks:
 *   - Top-level columns from the selected schema
 *   - Any relationships, expanding each to choose specific sub-fields from
 *     the related schema
 *
 * Resolves to a NestedFieldSelection value object ready for use in Blade templates.
 *
 * Usage in data():
 *
 *     'fields' => fn ($data) => Input::nestedFieldSelector('Action Fields', selectorKey: 'schema'),
 */
class NestedFieldSelectorInputType implements InputType
{
    public function resolve(mixed $rawValue, InputDefinition $definition, array $resolved): mixed
    {
        if (! is_array($rawValue)) {
            return new NestedFieldSelection([], []);
        }

        $selectorKey = $definition->selectorKey ?? 'schema';
        $context = $resolved[$selectorKey] ?? null;

        if (! $context instanceof GeneratorSchemaContext) {
            return new NestedFieldSelection([], []);
        }

        // Resolve top-level columns
        $selectedColumnNames = $rawValue['columns'] ?? [];
        $columns = array_values(array_filter(
            $context->allColumns,
            fn ($col) => in_array($col->name, $selectedColumnNames, true),
        ));

        // Resolve relationships with their selected sub-fields
        $relationships = [];

        foreach ($rawValue['relationships'] ?? [] as $relName => $relData) {
            $rel = $context->relationship($relName);

            if ($rel === null) {
                continue;
            }

            $selectedFieldNames = $relData['fields'] ?? [];
            $relatedColumns = $this->resolveRelatedColumns($rel->relatedModelClass, $selectedFieldNames);

            $relationships[] = new NestedRelationshipSelection(
                relationship: $rel,
                selectedFields: $relatedColumns,
            );
        }

        return new NestedFieldSelection($columns, $relationships);
    }

    public function toFrontend(InputDefinition $definition, array $resolved = []): array
    {
        $selectorKey = $definition->selectorKey ?? 'schema';
        $context = $resolved[$selectorKey] ?? null;

        if (! $context instanceof GeneratorSchemaContext) {
            return ['columns' => [], 'relationships' => []];
        }

        // Top-level columns — exclude system columns (pk, timestamps, soft-delete)
        $columns = array_values(array_map(
            fn ($col) => ['name' => $col->name, 'type' => $col->columnType],
            array_filter(
                $context->allColumns,
                fn ($col) => ! $col->isPrimary() && ! $col->isTimestamp() && ! $col->isSoftDelete(),
            ),
        ));

        // Relationships with related schema class for lazy sub-field loading
        $relationships = array_map(fn ($rel) => [
            'name' => $rel->definition->name,
            'type' => $rel->type,
            'relatedModelClass' => $rel->relatedModelClass,
            'relatedSchemaClass' => SchemaResolver::findByModel($rel->relatedModelClass),
        ], $context->allRelationships);

        return [
            'columns' => $columns,
            'relationships' => $relationships,
        ];
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    /**
     * Scan the related schema and return GeneratorColumn instances for the selected field names.
     *
     * @param  string[]  $fieldNames
     * @return GeneratorColumn[]
     */
    private function resolveRelatedColumns(string $modelClass, array $fieldNames): array
    {
        if (empty($fieldNames)) {
            return [];
        }

        $schemaClass = SchemaResolver::findByModel($modelClass);

        if ($schemaClass === null || ! class_exists($schemaClass)) {
            return [];
        }

        try {
            $table = (new SchemaScanner($schemaClass))->scan();
            $allColumns = array_map(fn ($col) => new GeneratorColumn($col), $table->columns);

            return array_values(array_filter(
                $allColumns,
                fn ($col) => in_array($col->name, $fieldNames, true),
            ));
        } catch (\Throwable) {
            return [];
        }
    }
}
