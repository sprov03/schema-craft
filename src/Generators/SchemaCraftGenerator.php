<?php

namespace SchemaCraft\Generators;

/**
 * Base class for custom SchemaCraft generators.
 *
 * Extend this class and implement name(), templates(), and data().
 *
 * ## data() — sequential input definition
 *
 * Each entry is a callback that receives previously-resolved data and returns
 * either an Input (interactive field shown in the wizard UI) or a scalar/array
 * (computed value stored silently and available to subsequent steps).
 *
 *     public function data(): array
 *     {
 *         return [
 *             // Interactive step — shows a schema picker
 *             'schema' => fn($data) => Input::schemaSelector('Schema'),
 *
 *             // Silent computed — derived from schema, never shown
 *             'action_namespace' => fn($data) => $data['schema']->actionsNamespace,
 *
 *             // Lazy interactive — default pre-filled from prior step
 *             'action_name' => fn($data) => Input::text('Action Name')
 *                 ->default(class_basename($data['schema']->modelClass)),
 *
 *             // Displayable computed — shown as a read-only info row
 *             'target_path' => fn($data) => Input::computed($data['schema']->actionsPath)
 *                 ->label('Target Path')
 *                 ->description('Where the file will be written.'),
 *
 *             // Recursive field picker — dot-path column/relationship selection
 *             'fields' => fn($data) => Input::schemaFieldPicker('Fields'),
 *         ];
 *     }
 *
 * Template path interpolation uses the data() array keys:
 *
 *     '[schema.actionsPath]/[action_name.title][schema.model.title]Action.php'
 *
 * ## templates()
 *
 *     public function templates(): array
 *     {
 *         return [
 *             Template::file('[schema.actionsPath]/[action_name.title]Action.php', 'generators.action.action'),
 *         ];
 *     }
 */
abstract class SchemaCraftGenerator
{
    /**
     * Human-readable name shown in the visualizer generator list.
     */
    abstract public function name(): string;

    /**
     * Sequential input definitions for the wizard.
     *
     * Return an associative array keyed by the variable name that will be
     * available in Blade templates and inlineTemplates(). Each value is a
     * closure receiving currently-resolved data and returning either an
     * InputDefinition (interactive) or a scalar/array (computed).
     *
     * @return array<string, \Closure(array<string,mixed>): (InputDefinition|mixed)>
     */
    public function data(): array
    {
        return [];
    }

    /**
     * @deprecated Use data() instead. Kept for documentation purposes only;
     *             the GeneratorController no longer calls this method.
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
     * Inline template insertions into existing files.
     *
     * @param  array<string, mixed>  $data  The fully resolved data from all steps.
     * @return array<InlineTemplate|InlineTemplateDefinition>
     */
    public function inlineTemplates(array $data): array
    {
        return [];
    }

    /**
     * Called after all templates have been rendered and written to disk.
     *
     * Override in subclasses to perform post-generation side effects such as
     * writing back metadata (e.g. #[Title]) to the originating schema file.
     * Only invoked during an actual run — never during preview.
     *
     * @param  array<string, mixed>  $data  The fully resolved data from all wizard steps.
     */
    public function afterRun(array $data): void {}

    /**
     * Extra data injected into all templates at render time.
     *
     * @return array<string, mixed>
     */
    public function templateData(): array
    {
        return [];
    }
}
