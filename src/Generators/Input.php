<?php

namespace SchemaCraft\Generators;

/**
 * Factory for creating InputDefinition instances.
 *
 * Used inside SchemaCraftGenerator::data() callbacks. The array key in data()
 * becomes the variable name — no key parameter is needed on the Input itself.
 *
 * ## Interactive inputs (shown in the wizard UI)
 *
 *     Input::text('Action Name')
 *         ->default(class_basename($data['schema']->modelClass))
 *         ->description('e.g. "Create", "Update"')
 *
 *     Input::select('Type', options: ['a' => 'Alpha', 'b' => 'Beta'])
 *     Input::multiSelect('Panels', options: ['admin' => 'Admin', 'app' => 'App'])
 *     Input::boolean('Soft Delete', default: false)
 *     Input::schemaSelector('Schema', selectColumns: true, selectRelationships: true)
 *     Input::schemaColumn('Column', selectorKey: 'schema')
 *     Input::schemaColumns('Columns', selectorKey: 'schema')
 *     Input::selectResourceDirectory('Resource Directory')
 *     Input::filamentPlacements('Wire Up To')
 *     Input::schemaFieldPicker('Fields', selectorKey: 'schema')
 *
 * ## Computed inputs (displayed as a read-only info row, no user interaction)
 *
 *     Input::computed($data['schema']->actionsNamespace)
 *         ->label('Actions Namespace')
 *         ->description('Where the generated action classes will live.')
 *
 * ## Custom inputs (project-registered custom types)
 *
 *     Input::custom('myType', 'Label', extra: ['option' => 'value'])
 *
 * ## Silent computed values (no Input needed — return the scalar directly)
 *
 *     'action_namespace' => fn($data) => $data['schema']->actionsNamespace,
 */
class Input
{
    /** Free-text field. */
    public static function text(string $label): InputDefinition
    {
        return new InputDefinition(label: $label, type: 'text');
    }

    /** Dropdown select with predefined options. */
    public static function select(string $label, array $options): InputDefinition
    {
        return new InputDefinition(label: $label, type: 'select', options: $options);
    }

    /** Multi-select with predefined options — resolves to string[]. */
    public static function multiSelect(string $label, array $options): InputDefinition
    {
        return (new InputDefinition(label: $label, type: 'multiselect', options: $options))
            ->default([]);
    }

    /** Checkbox toggle. */
    public static function boolean(string $label, bool $default = false): InputDefinition
    {
        return (new InputDefinition(label: $label, type: 'boolean'))->default($default);
    }

    /**
     * Schema selector — shows a searchable dropdown of all discovered schemas.
     *
     * @param  bool  $selectColumns  Show column checkboxes below the dropdown.
     * @param  bool  $selectRelationships  Show relationship checkboxes below the dropdown.
     */
    public static function schemaSelector(
        string $label,
        bool $selectColumns = false,
        bool $selectRelationships = false,
        bool $modelBackedOnly = false,
    ): InputDefinition {
        return new InputDefinition(
            label: $label,
            type: 'schemaSelector',
            selectColumns: $selectColumns,
            selectRelationships: $selectRelationships,
            modelBackedOnly: $modelBackedOnly,
        );
    }

    /**
     * Single column picker sourced from a schemaSelector step.
     *
     * @param  string  $selectorKey  The data() array key of the schemaSelector to source columns from.
     */
    public static function schemaColumn(string $label, string $selectorKey = 'schema'): InputDefinition
    {
        return new InputDefinition(label: $label, type: 'schemaColumn', selectorKey: $selectorKey);
    }

    /**
     * Searchable combobox pre-populated with columns from a schemaSelector step.
     *
     * Unlike schemaColumn (strict dropdown), the user can also type any custom
     * value not in the list. Resolves to a plain string.
     *
     * @param  string  $selectorKey  The data() array key of the schemaSelector to source columns from.
     */
    public static function schemaColumnCombobox(string $label, string $selectorKey = 'schema'): InputDefinition
    {
        return new InputDefinition(label: $label, type: 'schemaColumnCombobox', selectorKey: $selectorKey);
    }

    /**
     * Multi-column picker sourced from a schemaSelector step.
     *
     * @param  string  $selectorKey  The data() array key of the schemaSelector to source columns from.
     */
    public static function schemaColumns(string $label, string $selectorKey = 'schema'): InputDefinition
    {
        return new InputDefinition(label: $label, type: 'schemaColumns', selectorKey: $selectorKey);
    }

    /**
     * Recursive dot-path field picker sourced from a schemaSelector step.
     *
     * Lets the user pick flat columns and nested relationship fields using
     * dot-path notation (e.g. "comments.*.body", "author.name"). Relationships
     * expand lazily via the /api/generators/related-schema endpoint.
     *
     * Resolves to string[] of dot-path strings.
     *
     * @param  string  $selectorKey  The data() array key of the schemaSelector to source fields from.
     */
    public static function schemaFieldPicker(string $label, string $selectorKey = 'schema'): InputDefinition
    {
        return new InputDefinition(label: $label, type: 'schemaFieldPicker', selectorKey: $selectorKey);
    }

    /**
     * Nested field selector — pick top-level columns plus per-relationship sub-fields.
     *
     * Renders a two-section tree: flat columns with Select All/Deselect All, then
     * expandable relationship rows each showing sub-field checkboxes loaded lazily
     * from the related schema.
     *
     * Resolves to a NestedFieldSelection value object containing GeneratorColumn[]
     * for top-level fields and NestedRelationshipSelection[] for relationship fields.
     *
     * @param  string  $selectorKey  The data() array key of the schemaSelector that provides the schema context.
     */
    public static function nestedFieldSelector(string $label, string $selectorKey = 'schema'): InputDefinition
    {
        return new InputDefinition(label: $label, type: 'nestedFieldSelector', selectorKey: $selectorKey);
    }

    /**
     * Filament resource directory picker — populated from panel discovery + config.
     *
     * Resolves to a ResourceDirectoryValue giving both the path and its PSR-4 namespace.
     * Use ->default('app/Filament/Admin/Resources') to pre-select a directory.
     */
    public static function selectResourceDirectory(string $label): InputDefinition
    {
        return new InputDefinition(label: $label, type: 'selectResourceDirectory');
    }

    /**
     * Filament placement picker — lets the user choose one or more page/slot targets.
     *
     * Resolves to an array of placement descriptors:
     *     [['file' => '...', 'anchor' => '...', 'searchPattern' => '...'], ...]
     *
     * @param  string|null  $schemaKey  data() key holding a GeneratorSchemaContext — filters resources
     *                                  to only those whose name matches the schema's model basename.
     * @param  string|null  $requiresInstanceKey  data() key holding a bool — when true, only instance
     *                                            slots (view/edit header actions, table row actions)
     *                                            are offered; when false, only non-instance slots
     *                                            (list header actions, table actions) are offered.
     */
    public static function filamentPlacements(
        string $label,
        ?string $schemaKey = null,
        ?string $requiresInstanceKey = null,
    ): InputDefinition {
        return new InputDefinition(
            label: $label,
            type: 'filamentPlacements',
            schemaKey: $schemaKey,
            requiresInstanceKey: $requiresInstanceKey,
        );
    }

    /**
     * Register the generated action onto one or more APIs.
     *
     * Lists every configured API; each checked API picks the resource its endpoint returns
     * (scoped to that API's resources, with inline create). Resolves to a { apiName => resourceFqcn }
     * map for ApiRegistration::writesFor(). SchemaCraft-internal — see ApiRegistrationInputType.
     *
     * @param  string|null  $schemaKey  data() key holding the GeneratorSchemaContext used to scope
     *                                  each API's resource list to the selected schema.
     */
    public static function apiRegistration(string $label, ?string $schemaKey = null): InputDefinition
    {
        return new InputDefinition(
            label: $label,
            type: 'apiRegistration',
            schemaKey: $schemaKey,
        );
    }

    /**
     * Computed display value — shown as a read-only info row in the wizard.
     *
     * The value must be a scalar or plain array (JSON-serialisable).
     * Use ->label() and ->description() to control what the UI shows.
     *
     * For silent computed values with no UI display, return the scalar directly
     * from the data() callback instead of using Input::computed().
     */
    public static function computed(mixed $value): InputDefinition
    {
        return (new InputDefinition(label: '', type: 'computed'))
            ->withComputedValue($value);
    }

    /**
     * Custom input type registered via InputTypeRegistry.
     *
     * @param  array<string, mixed>  $extra  Arbitrary config passed to the InputType handler.
     */
    public static function custom(string $type, string $label, array $extra = [], mixed $default = null): InputDefinition
    {
        return (new InputDefinition(label: $label, type: $type, extra: $extra))
            ->default($default);
    }
}
