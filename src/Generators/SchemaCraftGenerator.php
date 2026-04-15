<?php

namespace SchemaCraft\Generators;

/**
 * Base class for custom SchemaCraft generators.
 *
 * Extend this class to define a generator that can be discovered by the
 * SchemaCraft visualizer. Implement name() and templates(); override
 * inputs() and templateData() as needed.
 *
 * ## Example
 *
 *     class ResourceGenerator extends SchemaCraftGenerator
 *     {
 *         public function name(): string { return 'Filament Resource'; }
 *
 *         public function inputs(): array
 *         {
 *             return [
 *                 Input::schemaSelector('schema', 'Schema', selectColumns: true, selectRelationships: true),
 *                 Input::text('class_name', 'Class Name'),
 *                 Input::selectResourceDirectory('resource_directory', 'Resource Directory'),
 *             ];
 *         }
 *
 *         public function templates(): array
 *         {
 *             return [
 *                 Template::file(
 *                     '[resource_directory]/[schema.model.plural.title]/[schema.model.title]Resource.php',
 *                     'generators.resources.resource',
 *                 ),
 *
 *                 ...Template::forEachRelationship('schema', 'relationship', [
 *                     Template::file(
 *                         '[resource_directory]/[schema.model.plural.title]/RelationManagers/[relationship.name.title]RelationManager.php',
 *                         'generators.resources.relation_manager',
 *                         ['ClassName' => '[relationship.name.title]RelationManager'],
 *                     ),
 *                 ], 'collection'),
 *             ];
 *         }
 *     }
 *
 * In your Blade template, use {!! $phpOpenTag !!} to output the PHP opening tag,
 * and access model names via NameChain: {!! $schema->model->title !!}
 */
abstract class SchemaCraftGenerator
{
    /**
     * Human-readable name shown in the visualizer dropdown.
     */
    abstract public function name(): string;

    /**
     * Input fields shown in the visualizer form.
     *
     * Use Input::schemaSelector() for schema selection (replaces the old schemas() method).
     *
     * @return InputDefinition[]
     */
    public function inputs(): array
    {
        return [];
    }

    /**
     * Blade templates to render and write.
     *
     * @return TemplateDefinition[]
     */
    abstract public function templates(): array;

    /**
     * Extra data injected into all templates at render time.
     *
     * Override to provide custom variables beyond schema contexts and inputs.
     *
     * @return array<string, mixed>
     */
    public function templateData(): array
    {
        return [];
    }
}
