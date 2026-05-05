<?php

namespace SchemaCraft\Generators;

/**
 * Describes a single generator input field.
 *
 * The array key in SchemaCraftGenerator::data() is the variable name stored
 * in the accumulated data and injected into Blade templates — InputDefinition
 * itself does not carry a key.
 *
 * Fluent setters allow inline configuration:
 *
 *     Input::text('Action Name')
 *         ->default(class_basename($data['schema']->modelClass))
 *         ->description('e.g. "Create", "Update"')
 *         ->help('Used as the class name prefix for the generated action.')
 */
class InputDefinition
{
    /** Default value shown / pre-filled in the UI. Mutable to support ->default() fluent setter. */
    public mixed $default = null;

    /**
     * When set via ->value(), the UI step is skipped entirely and this raw value
     * is resolved through the input type's resolve() method automatically.
     */
    public mixed $forcedValue = null;

    /** Whether ->value() has been called (distinguishes null from "not set"). */
    public bool $hasValue = false;

    /** Short description shown below the field label. */
    public ?string $description = null;

    /** Longer help text shown in an info tooltip. */
    public ?string $help = null;

    /**
     * For type='computed' only: the pre-resolved scalar/array value to store
     * in accumulated and optionally display as a read-only info row.
     */
    public mixed $computedValue = null;

    public function __construct(
        public string $label,
        public readonly string $type,
        /** Predefined options for type='select'. */
        public readonly array $options = [],
        /** Whether this schemaSelector should show column checkboxes. */
        public readonly bool $selectColumns = false,
        /** Whether this schemaSelector should show relationship checkboxes. */
        public readonly bool $selectRelationships = false,
        /** For schemaColumn / schemaColumns / schemaFieldPicker / nestedFieldSelector — which data() key holds the schema context. */
        public readonly ?string $selectorKey = null,
        /** For filamentPlacements — which data() key holds the GeneratorSchemaContext used to filter resources by model. */
        public readonly ?string $schemaKey = null,
        /** For filamentPlacements — which data() key holds a bool indicating whether the action needs an existing model instance. */
        public readonly ?string $requiresInstanceKey = null,
        /** Arbitrary extra config for custom input types. */
        public readonly array $extra = [],
    ) {}

    /**
     * Force a value and bypass the UI entirely.
     *
     * The raw value is passed through the input type's own resolve() method,
     * so it is typed correctly (e.g. a path string through selectResourceDirectory
     * still produces a ResourceDirectoryValue).
     *
     *     Input::selectResourceDirectory('Resource Directory')->value('app/Filament/Admin/Resources')
     *     Input::text('Class Name')->value('MyClass')
     */
    public function value(mixed $raw): static
    {
        $this->forcedValue = $raw;
        $this->hasValue = true;

        return $this;
    }

    /** Set the default value fluently. */
    public function default(mixed $value): static
    {
        $this->default = $value;

        return $this;
    }

    /** Override the label fluently (useful on computed inputs). */
    public function label(string $text): static
    {
        $this->label = $text;

        return $this;
    }

    /** Set a short description shown below the field label. */
    public function description(string $text): static
    {
        $this->description = $text;

        return $this;
    }

    /** Set help text shown in an info tooltip next to the label. */
    public function help(string $text): static
    {
        $this->help = $text;

        return $this;
    }

    /**
     * Internal — called by Input::computed() to store the pre-resolved value.
     * Not intended for direct use in generators.
     */
    public function withComputedValue(mixed $value): static
    {
        $this->computedValue = $value;

        return $this;
    }
}
