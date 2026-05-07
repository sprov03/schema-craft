<?php

namespace SchemaCraft\Generator\Api;

/**
 * Unified result for a single file after a generator run (template + all inlines).
 *
 * Returned by GeneratorRunner::run(). Preview serialises it to JSON; Run writes it to disk.
 * The file cache is the single source of truth: templates seed it, inlines modify it.
 *
 * $content         - final cache value = what the file will contain after Run.
 * $originalContent - what is currently on disk (null = new file).
 *
 * @param  InlineGeneratedFile[]  $inlineResults
 */
class FileRunResult
{
    /**
     * @param  InlineGeneratedFile[]  $inlineResults
     */
    public function __construct(
        public readonly string $path,
        public readonly string $content,
        public readonly ?string $originalContent,
        public readonly bool $isNew,
        public readonly bool $isTemplate,
        public readonly array $inlineResults,
    ) {}

    /**
     * True when the file content changed from its original state.
     * New files are always considered modified.
     */
    public function isModified(): bool
    {
        return $this->isNew || $this->content !== $this->originalContent;
    }

    /**
     * True when every inline targeting this file was skipped (none were applied).
     */
    public function allInlinesSkipped(): bool
    {
        if (empty($this->inlineResults)) {
            return false;
        }

        foreach ($this->inlineResults as $inline) {
            if (! $inline->skipped) {
                return false;
            }
        }

        return true;
    }
}
