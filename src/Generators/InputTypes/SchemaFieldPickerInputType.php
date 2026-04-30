<?php

namespace SchemaCraft\Generators\InputTypes;

use SchemaCraft\Generators\GeneratorSchemaContext;
use SchemaCraft\Generators\InputDefinition;

/**
 * Recursive dot-path field picker input type.
 *
 * Renders a two-level UI: flat column checkboxes at the top level, and
 * expandable relationship groups that lazy-load their schema via the
 * /api/generators/related-schema endpoint. Supports arbitrary depth.
 *
 * Dot-path notation:
 *   - Flat column:             "title"
 *   - BelongsTo column:        "author.name"
 *   - HasMany column:          "comments.*.body"
 *   - Deep nested:             "comments.*.author.name"
 *
 * Resolves to string[] of selected dot-path strings.
 *
 * Usage:
 *
 *     'fields' => fn($data) => Input::schemaFieldPicker('Fields')
 *         ->description('Select which fields the action accepts.')
 */
class SchemaFieldPickerInputType implements InputType
{
    /** Collection relationship types that use the .*.  wildcard. */
    private const COLLECTION_TYPES = [
        'hasMany', 'belongsToMany', 'morphMany', 'morphToMany', 'morphedByMany',
    ];

    public function resolve(mixed $rawValue, InputDefinition $definition, array $resolved): mixed
    {
        if (! is_array($rawValue)) {
            return [];
        }

        return array_values(array_filter(
            $rawValue,
            fn ($v) => is_string($v) && $v !== '',
        ));
    }

    public function toFrontend(InputDefinition $definition, array $resolved = []): array
    {
        $selectorKey = $definition->selectorKey ?? 'schema';
        $context = $resolved[$selectorKey] ?? null;

        $columns = [];
        $relationships = [];

        if ($context instanceof GeneratorSchemaContext) {
            [$columns, $relationships] = $this->buildFromContext($context);
        }

        return [
            'renderAs' => 'fieldPicker',
            'selectorKey' => $selectorKey,
            'columns' => $columns,
            'relationships' => $relationships,
        ];
    }

    /**
     * Build column and relationship arrays from a GeneratorSchemaContext.
     * Reused by GeneratorController::buildFieldPickerColumns() for the
     * /api/generators/related-schema lazy-load endpoint.
     *
     * @return array{0: array<array<string,mixed>>, 1: array<array<string,mixed>>}
     */
    public static function buildFromContext(GeneratorSchemaContext $context): array
    {
        $skip = ['created_at', 'updated_at', 'deleted_at'];

        // Collect FK and morph column names to exclude from flat list
        $fkNames = [];
        $morphNames = [];

        foreach ($context->allRelationships as $rel) {
            if ($rel->definition->type === 'belongsTo') {
                $fkNames[] = $rel->definition->foreignColumn
                    ?? \Illuminate\Support\Str::snake($rel->definition->name).'_id';
            }

            if ($rel->definition->type === 'morphTo') {
                $morphName = $rel->definition->morphName ?? $rel->definition->name;
                $morphNames[] = $morphName.'_type';
                $morphNames[] = $morphName.'_id';
            }
        }

        $exclude = array_merge($skip, $fkNames, $morphNames);

        $columns = [];

        foreach ($context->allColumns as $col) {
            if (in_array($col->name, $exclude, true)) {
                continue;
            }

            $columns[] = [
                'name' => $col->name,
                'type' => $col->columnType,
                'nullable' => $col->nullable,
            ];
        }

        $relationships = [];

        foreach ($context->allRelationships as $rel) {
            $relationships[] = [
                'name' => $rel->definition->name,
                'type' => $rel->definition->type,
                'relatedModel' => $rel->definition->relatedModel,
                'isCollection' => in_array($rel->definition->type, self::COLLECTION_TYPES, true),
            ];
        }

        return [$columns, $relationships];
    }
}
