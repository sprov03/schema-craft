<?php

namespace SchemaCraft\Generators;

/**
 * Factory for creating InputDefinition instances.
 *
 * ## Input types
 *
 * - `Input::text('key', 'Label')` — Free text field
 * - `Input::select('key', 'Label', ['a' => 'A'])` — Dropdown select
 * - `Input::boolean('key', 'Label', false)` — Checkbox toggle
 * - `Input::schemaSelector('key', 'Label')` — Schema picker with optional column/relationship selection
 * - `Input::schemaColumn('key', 'Label', 'schema')` — Single column picker from a schemaSelector
 * - `Input::schemaColumns('key', 'Label', 'schema')` — Multi column picker from a schemaSelector
 * - `Input::selectResourceDirectory('key', 'Label')` — Filament resource directory picker
 */
class Input
{
    public static function text(string $key, string $label): InputDefinition
    {
        return new InputDefinition(key: $key, label: $label, type: 'text');
    }

    public static function select(string $key, string $label, array $options): InputDefinition
    {
        return new InputDefinition(key: $key, label: $label, type: 'select', options: $options);
    }

    public static function boolean(string $key, string $label, bool $default = false): InputDefinition
    {
        return new InputDefinition(key: $key, label: $label, type: 'boolean', default: $default);
    }

    /**
     * Schema selector input — shows a dropdown of all schemas. When selected,
     * the schema is resolved to a GeneratorSchemaContext available in templates.
     *
     * @param  bool  $selectColumns  Show column checkboxes below the schema dropdown.
     * @param  bool  $selectRelationships  Show relationship checkboxes below the schema dropdown.
     */
    public static function schemaSelector(
        string $key,
        string $label,
        bool $selectColumns = true,
        bool $selectRelationships = false,
    ): InputDefinition {
        return new InputDefinition(
            key: $key,
            label: $label,
            type: 'schemaSelector',
            selectColumns: $selectColumns,
            selectRelationships: $selectRelationships,
        );
    }

    /**
     * Single column picker that references a schemaSelector input.
     *
     * @param  string  $selectorKey  The key of the schemaSelector input to source columns from.
     */
    public static function schemaColumn(string $key, string $label, string $selectorKey = 'schema'): InputDefinition
    {
        return new InputDefinition(key: $key, label: $label, type: 'schemaColumn', selectorKey: $selectorKey);
    }

    /**
     * Multi column picker that references a schemaSelector input.
     *
     * @param  string  $selectorKey  The key of the schemaSelector input to source columns from.
     */
    public static function schemaColumns(string $key, string $label, string $selectorKey = 'schema'): InputDefinition
    {
        return new InputDefinition(key: $key, label: $label, type: 'schemaColumns', selectorKey: $selectorKey);
    }

    /**
     * Resource directory picker — populated from Filament panel discovery + config.
     */
    public static function selectResourceDirectory(string $key, string $label): InputDefinition
    {
        return new InputDefinition(key: $key, label: $label, type: 'selectResourceDirectory');
    }
}
