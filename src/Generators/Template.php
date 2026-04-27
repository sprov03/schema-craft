<?php

namespace SchemaCraft\Generators;

/**
 * Factory for creating TemplateDefinition instances.
 *
 * ## Usage
 *
 *     // Single file:
 *     Template::file('app/[schema.model.title].php', 'generators.my-template')
 *
 *     // Single file with extra variables:
 *     Template::file(
 *         'app/[schema.model.title]Resource.php',
 *         'generators.resource',
 *         ['ClassName' => '[schema.model.title]Resource'],
 *     )
 *
 *     // Iterate over relationships:
 *     ...Template::forEachRelationship('schema', 'relationship', [
 *         Template::file(
 *             'app/RelationManagers/[relationship.name.title]RelationManager.php',
 *             'generators.relation-manager',
 *         ),
 *     ], 'collection')
 */
class Template
{
    /**
     * Define a single template to render.
     *
     * @param  string  $outputPath  Output path with [bracket] placeholders.
     * @param  string  $viewName  Blade view name.
     * @param  array<string, mixed>  $extraVariables  Extra variables; string values may contain [bracket] placeholders.
     */
    public static function file(string $outputPath, string $viewName, array $extraVariables = []): TemplateDefinition
    {
        return new TemplateDefinition(
            outputPath: $outputPath,
            viewName: $viewName,
            extraVariables: $extraVariables,
        );
    }

    /**
     * Iterate over any dot-path iterable and render templates for each item.
     *
     * @param  string  $iterableKey  Dot-path to the iterable (e.g. 'schema.relationships').
     * @param  string  $as  Variable name for each item in templates.
     * @param  TemplateDefinition[]  $templates  Templates to render per item.
     * @param  string|null  $filter  Optional filter: 'collection' or 'singular'.
     * @return TemplateDefinition[]
     */
    public static function forEach(string $iterableKey, string $as, array $templates, ?string $filter = null): array
    {
        return array_map(fn (TemplateDefinition $tpl) => new TemplateDefinition(
            outputPath: $tpl->outputPath,
            viewName: $tpl->viewName,
            extraVariables: $tpl->extraVariables,
            iterateOver: $tpl->iterateOver ?? $iterableKey,
            iterateAs: $tpl->iterateAs ?? $as,
            iterateFilter: $tpl->iterateFilter ?? $filter,
        ), $templates);
    }

    /**
     * Sugar for iterating over a schema's relationships.
     *
     * Equivalent to: Template::forEach("{$schemaKey}.relationships", $as, $templates, $filter)
     *
     * @param  string  $schemaKey  The schema input key (e.g. 'schema').
     * @param  string  $as  Variable name for each relationship in templates.
     * @param  TemplateDefinition[]  $templates  Templates to render per relationship.
     * @param  string|null  $filter  Optional filter: 'collection' or 'singular'.
     * @return TemplateDefinition[]
     */
    public static function forEachRelationship(string $schemaKey, string $as, array $templates, ?string $filter = null): array
    {
        return self::forEach("{$schemaKey}.relationships", $as, $templates, $filter);
    }

    /**
     * Start building an inline template — an insertion into an existing file.
     *
     * Returns an InlineTemplate fluent builder. Call ->build() or pass the builder
     * directly to inlineTemplates() (the runner calls build() automatically).
     *
     *     Template::inline('generators.filament.action-wire')
     *         ->into($placement['file'])
     *         ->anchor($placement['anchor'])
     *         ->after($placement['searchPattern'])
     */
    public static function inline(string $viewName): InlineTemplate
    {
        return InlineTemplate::make($viewName);
    }
}
