<?php

namespace SchemaCraft\Generator\Api;

/**
 * Value object representing the result of an inline template insertion.
 *
 * Unlike GeneratedFile (which creates a new file), this describes a modification
 * to an existing file — inserting a rendered snippet at a specific location.
 */
class InlineGeneratedFile
{
    /**
     * @param  string  $path  Path of the target file relative to base_path().
     * @param  string  $content  Full file content after insertion (used for preview).
     * @param  string  $snippet  The rendered snippet that was (or would be) inserted.
     * @param  bool  $skipped  True when the insertion was not applied.
     * @param  string|null  $skipReason  Why the insertion was skipped, or null if it was applied.
     *                                   Values: 'already_present' | 'file_not_found' | 'anchor_not_found' | 'pattern_not_found'
     * @param  string|null  $originalContent  File content before insertion. Non-null only when the insertion was applied.
     */
    public function __construct(
        public readonly string $path,
        public readonly string $content,
        public readonly string $snippet,
        public readonly bool $skipped,
        public readonly ?string $skipReason = null,
        public readonly ?string $originalContent = null,
    ) {}
}
