<?php

namespace SchemaCraft\Generators;

/**
 * Factory for creating InputDefinition instances.
 *
 * ## Built-in input types
 *
 * - `Input::text('key', 'Label')` — Free text field
 * - `Input::select('key', 'Label', ['a' => 'A'])` — Dropdown select
 * - `Input::boolean('key', 'Label', false)` — Checkbox toggle
 * - `Input::schemaSelector('key', 'Label')` — Schema picker with optional column/relationship selection
 * - `Input::schemaColumn('key', 'Label', 'schema')` — Single column picker from a schemaSelector
 * - `Input::schemaColumns('key', 'Label', 'schema')` — Multi column picker from a schemaSelector
 * - `Input::selectResourceDirectory('key', 'Label')` — Filament resource directory picker
 * - `Input::filamentPlacements('key', 'Label')` — Filament panel/resource/page/slot cascade picker
 *
 * ## Custom input types
 *
 * Register a custom type via InputTypeRegistry, then reference it:
 *
 *     Input::custom('myCustomType', 'key', 'Label', extra: ['option' => 'value'])
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
     *
     * The chosen value is resolved to a {@see ResourceDirectoryValue} instance
     * before being passed to templates, giving you both the raw relative path
     * and the derived PSR-4 namespace:
     *
     *     namespace {!! $resource_directory->namespace !!}\...;   // App\Filament\Admin\Resources\Posts
     *     [resource_directory]/[schema.model.plural.title]/...     // app/Filament/Admin/Resources/Posts/...
     *
     * Output-path interpolation (`[resource_directory]`) calls __toString()
     * and receives the path, so existing templates keep working unchanged.
     */
    public static function selectResourceDirectory(string $key, string $label): InputDefinition
    {
        return new InputDefinition(key: $key, label: $label, type: 'selectResourceDirectory');
    }

    /**
     * Filament placement picker — cascading panel → resource → page → slot selection.
     *
     * Resolves to an array of placement targets for use in inlineTemplates():
     *
     *     [
     *         ['file' => 'app/Filament/.../Pages/ListPosts.php', 'anchor' => 'getHeaderActions(): array', 'searchPattern' => 'return ['],
     *         ...
     *     ]
     *
     * The generator's inlineTemplates() loops over these to wire actions into pages.
     */
    public static function filamentPlacements(string $key, string $label): InputDefinition
    {
        return new InputDefinition(key: $key, label: $label, type: 'filamentPlacements');
    }

    /**
     * Create an input for a project-registered custom type.
     *
     * The type name must be registered in InputTypeRegistry before use.
     *
     * @param  array<string, mixed>  $extra  Arbitrary config passed to the InputType handler.
     */
    public static function custom(string $type, string $key, string $label, array $extra = [], mixed $default = null): InputDefinition
    {
        return new InputDefinition(key: $key, label: $label, type: $type, default: $default, extra: $extra);
    }
}
