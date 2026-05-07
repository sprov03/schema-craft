<?php

namespace SchemaCraft\Generators;

/**
 * Fluent builder for InlineTemplateDefinition.
 *
 * Use in a generator's inlineTemplates() method to describe code insertions
 * into existing files:
 *
 *     // Insert after a pattern (with optional disambiguating anchor):
 *     InlineTemplate::make('generators.filament.action-wire')
 *         ->into($placement['file'])
 *         ->anchor($placement['anchor'])
 *         ->after($placement['searchPattern'])
 *
 *     // Append to end of file:
 *     InlineTemplate::make('generators.routes.api-entry')
 *         ->into('routes/api.php')
 *         ->append()
 *
 *     // Regex-based insertion:
 *     InlineTemplate::make('generators.action-wire')
 *         ->into('routes/api.php')
 *         ->afterRegex('/Route::middleware\([^)]+\)->group\(/')
 *
 * All methods return $this for chaining. Call build() to produce the definition,
 * or pass the builder directly to inlineTemplates() — the runner calls build() automatically.
 */
class InlineTemplate
{
    private string $targetPath = '';

    private string $insertMode = InlineTemplateDefinition::MODE_AFTER;

    private ?string $searchPattern = null;

    private ?string $anchor = null;

    private bool $useRegex = false;

    private array $extraVariables = [];

    private ?string $rawContent = null;

    public function __construct(private readonly ?string $viewName) {}

    public static function make(string $viewName): static
    {
        return new static($viewName);
    }

    /**
     * Create an inline insertion from a raw string — no Blade template needed.
     * Duplicate detection still applies: if the string already exists in the target
     * file it will be skipped automatically.
     */
    public static function raw(string $content): static
    {
        $instance = new static(null);
        $instance->rawContent = $content;

        return $instance;
    }

    /** Target file path relative to base_path(). Supports [bracket] placeholders. */
    public function into(string $targetPath): static
    {
        $this->targetPath = $targetPath;

        return $this;
    }

    /**
     * Optional context anchor — find this string in the file first, then search for
     * the pattern only after it. Supports [bracket] placeholders.
     *
     * Use when the search pattern (e.g. 'return [') appears multiple times and you
     * need to target a specific occurrence (e.g. inside a specific method).
     */
    public function anchor(string $anchor): static
    {
        $this->anchor = $anchor;

        return $this;
    }

    /** Insert rendered content immediately after this literal string. */
    public function after(string $pattern): static
    {
        $this->searchPattern = $pattern;
        $this->insertMode = InlineTemplateDefinition::MODE_AFTER;
        $this->useRegex = false;

        return $this;
    }

    /** Insert rendered content immediately before this literal string. */
    public function before(string $pattern): static
    {
        $this->searchPattern = $pattern;
        $this->insertMode = InlineTemplateDefinition::MODE_BEFORE;
        $this->useRegex = false;

        return $this;
    }

    /** Insert rendered content immediately after the first match of this PCRE regex. */
    public function afterRegex(string $pattern): static
    {
        $this->searchPattern = $pattern;
        $this->insertMode = InlineTemplateDefinition::MODE_AFTER;
        $this->useRegex = true;

        return $this;
    }

    /** Insert rendered content immediately before the first match of this PCRE regex. */
    public function beforeRegex(string $pattern): static
    {
        $this->searchPattern = $pattern;
        $this->insertMode = InlineTemplateDefinition::MODE_BEFORE;
        $this->useRegex = true;

        return $this;
    }

    /** Append rendered content to the very end of the file. No pattern needed. */
    public function append(): static
    {
        $this->insertMode = InlineTemplateDefinition::MODE_APPEND;
        $this->searchPattern = null;
        $this->useRegex = false;

        return $this;
    }

    /** Prepend rendered content to the very start of the file. No pattern needed. */
    public function prepend(): static
    {
        $this->insertMode = InlineTemplateDefinition::MODE_PREPEND;
        $this->searchPattern = null;
        $this->useRegex = false;

        return $this;
    }

    /** Extra variables merged into the Blade template data for this insertion only. */
    public function with(array $extraVariables): static
    {
        $this->extraVariables = $extraVariables;

        return $this;
    }

    public function build(): InlineTemplateDefinition
    {
        return new InlineTemplateDefinition(
            viewName: $this->viewName,
            targetPath: $this->targetPath,
            insertMode: $this->insertMode,
            searchPattern: $this->searchPattern,
            anchor: $this->anchor,
            useRegex: $this->useRegex,
            extraVariables: $this->extraVariables,
            rawContent: $this->rawContent,
        );
    }
}
