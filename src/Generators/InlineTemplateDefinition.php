<?php

namespace SchemaCraft\Generators;

/**
 * Value object describing a code insertion into an existing file.
 *
 * Insertion modes:
 *   'after'   — find anchor (optional) then searchPattern, insert immediately after it
 *   'before'  — find anchor (optional) then searchPattern, insert immediately before it
 *   'append'  — add snippet to the very end of the file (no pattern needed)
 *   'prepend' — add snippet to the very start of the file (no pattern needed)
 *
 * When $anchor is provided, $searchPattern is only searched for after the anchor
 * position. This disambiguates patterns that appear multiple times in a file
 * (e.g. 'return [' appears in every method — anchor on the method signature first).
 *
 * When $useRegex is true, $searchPattern and $anchor are treated as PCRE patterns
 * passed to preg_match(). The insertion point is placed at the start or end of
 * the full match (not a capture group).
 *
 * Duplicate detection: if the rendered snippet (trimmed) already exists anywhere
 * in the file, the insertion is skipped — safe to run generators more than once.
 *
 * Raw content: when $rawContent is set, $viewName is ignored and the string is used
 * directly as the snippet — no Blade rendering. Useful for injecting short literals
 * like `use` statements without needing a dedicated template file.
 */
class InlineTemplateDefinition
{
    public const MODE_AFTER = 'after';

    public const MODE_BEFORE = 'before';

    public const MODE_APPEND = 'append';

    public const MODE_PREPEND = 'prepend';

    public function __construct(
        /** Blade view name to render as the inserted content. Ignored when $rawContent is set. */
        public readonly ?string $viewName,
        /** Target file path relative to base_path(). Supports [bracket] placeholders. */
        public readonly string $targetPath,
        /** Insertion mode: 'after' | 'before' | 'append' | 'prepend'. */
        public readonly string $insertMode = self::MODE_AFTER,
        /**
         * String or regex pattern to search for as the insertion point.
         * Not used for 'append' or 'prepend' modes. Supports [bracket] placeholders.
         */
        public readonly ?string $searchPattern = null,
        /**
         * Optional anchor to locate context before searching for $searchPattern.
         * Supports [bracket] placeholders. When set, $searchPattern is only matched
         * after the anchor position — useful when the pattern is not unique in the file.
         */
        public readonly ?string $anchor = null,
        /**
         * When true, $searchPattern and $anchor are treated as PCRE regex patterns.
         * When false (default), plain string search via strpos() is used.
         */
        public readonly bool $useRegex = false,
        /** Extra variables merged into the Blade template data for this insertion. */
        public readonly array $extraVariables = [],
        /** Raw string content to insert directly, bypassing Blade rendering entirely. */
        public readonly ?string $rawContent = null,
    ) {}
}
