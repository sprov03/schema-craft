<?php

namespace SchemaCraft\Generators;

use Illuminate\Contracts\View\Factory as ViewFactory;
use SchemaCraft\Generator\Api\FileRunResult;
use SchemaCraft\Generator\Api\InlineGeneratedFile;

/**
 * Renders a generator's Blade templates and returns the resulting files.
 *
 * Architecture — the file cache is the single source of truth:
 *
 *  Pass 1 — Templates are rendered and used to seed the cache.
 *    • New files: cache ← template content (so inlines can target them before disk write).
 *    • Existing files: cache ← disk content via readFromFileCache() so every subsequent
 *      access — inlines and Pass 3 — always sees real content, never an empty string.
 *
 *  Pass 2 — Inline insertions read from and write to the cache.
 *    • Multiple insertions into the same file compound correctly because each sees
 *      the prior modification already in the cache.
 *    • Files targeted by inlines but not by any template are seeded from disk on
 *      first access inside runInlineTemplate().
 *
 *  Pass 3 — One FileRunResult is built per file from the final cache state.
 *    • Preview caller: serialise results to JSON, no disk writes.
 *    • Run caller: write new files, inline-modified files, and force-replaced
 *      existing template files to disk.
 *
 * This design means both callers execute the same logic — the only difference is
 * what happens after run() returns, eliminating the divergence that previously caused
 * preview to show stale template content alongside the correctly-modified inline result.
 */
class GeneratorRunner
{
    public function __construct(private readonly ViewFactory $view) {}

    /** @var array<string, string>  absPath → current content (single source of truth per run) */
    private array $fileCache = [];

    /**
     * Render all templates and inline insertions for a generator run.
     *
     * @param  array<string, mixed>  $inputValues
     * @param  bool  $writeResults  When true, write new and modified files to disk.
     * @param  string[]  $skipFiles  Relative paths to exclude from writing (user opted out).
     * @param  string[]  $forceReplace  Relative paths of existing file-template targets to
     *                       overwrite with freshly rendered content. Without this, an existing
     *                       file-template target is preserved (seeded from disk, render ignored).
     * @return FileRunResult[]
     */
    public function run(
        SchemaCraftGenerator $generator,
        array $inputValues,
        bool $writeResults = false,
        array $skipFiles = [],
        array $forceReplace = [],
    ): array {
        $this->fileCache = [];

        $allData = array_merge(
            ['phpOpenTag' => '<?php'],
            $inputValues,
            $generator->templateData(),
        );

        // ─── Pass 1: Templates → seed file cache ─────────────────────────────
        //
        // New file: seed cache with template content so inlines can target it
        // in Pass 2 before it exists on disk.
        //
        // Existing file: seed cache from disk so inlines (and Pass 3) always
        // see the real current content — never an empty string.
        $relativePaths = [];  // absPath → relative path string
        $isTemplateFile = []; // absPath → bool

        foreach ($this->runTemplates($generator->templates(), $allData) as $generatedFile) {
            $absPath = base_path($generatedFile->path);
            $relativePaths[$absPath] = $generatedFile->path;
            $isTemplateFile[$absPath] = true;

            // File templates render the whole file. A new file's render IS its intended
            // content. An existing file is PRESERVED by default (seed cache from disk, render
            // ignored) so hand-customized files aren't clobbered. The render is written into
            // the cache for an existing file only when the caller explicitly lists its path in
            // $forceReplace (the UI's per-file "Replace" toggle). Either way the cache is the
            // single source of truth — preview and write both act on whatever ends up here.
            if (! file_exists($absPath) || in_array($generatedFile->path, $forceReplace, true)) {
                $this->writeToFileCache($absPath, $generatedFile->content);
            } else {
                $this->readFromFileCache($absPath); // preserve: seed from disk, render not applied
            }
        }

        // ─── Pass 2: Inlines → read from cache (or disk), modify, write back ─
        //
        // runInlineTemplate() checks the cache first; if the path is not there
        // it reads from disk and seeds the cache. Modifications are written back
        // to the cache so compound edits within the same run see each other.
        $inlineDefs = array_map(
            fn ($item) => $item instanceof InlineTemplate ? $item->build() : $item,
            $generator->inlineTemplates($inputValues),
        );

        $inlineResultsByPath = []; // absPath → InlineGeneratedFile[]

        foreach ($inlineDefs as $inlineDef) {
            $result = $this->runInlineTemplate($inlineDef, $allData);
            $inlineAbs = base_path($result->path);

            if (! isset($relativePaths[$inlineAbs])) {
                $relativePaths[$inlineAbs] = $result->path;
            }

            $inlineResultsByPath[$inlineAbs][] = $result;
        }

        // ─── Pass 3: Build results from cache, write to disk if requested ────
        //
        // One FileRunResult per file. Read disk NOW to get the original content
        // for diffing — this is the only place disk is read in the output path.
        // Write rules: new file or modified existing file → write; else skip.
        $results = [];
        $allAbsPaths = array_unique(array_merge(
            array_keys($isTemplateFile),
            array_keys($inlineResultsByPath),
        ));

        foreach ($allAbsPaths as $absPath) {
            $path = $relativePaths[$absPath] ?? '';
            $final = $this->readFromFileCache($absPath) ?? '';
            $diskContent = file_exists($absPath) ? file_get_contents($absPath) : null;
            $isNew = $diskContent === null;
            $inlines = $inlineResultsByPath[$absPath] ?? [];

            $isSkipped = in_array($path, $skipFiles, true);

            if ($writeResults && ! $isSkipped && ($isNew || $final !== $diskContent)) {
                $dir = dirname($absPath);
                if (! is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                file_put_contents($absPath, $final);
            }

            $results[] = new FileRunResult(
                path: $path,
                content: $final,
                originalContent: $diskContent,
                isNew: $isNew,
                isTemplate: isset($isTemplateFile[$absPath]),
                inlineResults: $inlines,
            );
        }

        if ($writeResults) {
            $generator->afterRun($inputValues);
        }

        return $results;
    }

    // ─── Template rendering ───────────────────────────────────────────────────

    /**
     * @param  TemplateDefinition[]  $templates
     * @return \SchemaCraft\Generator\Api\GeneratedFile[]
     */
    private function runTemplates(array $templates, array $allData): array
    {
        $files = [];

        foreach ($templates as $templateDef) {
            if ($templateDef->iterateOver !== null) {
                $iterable = $this->resolveChain($templateDef->iterateOver, $allData);

                if (! is_iterable($iterable)) {
                    continue;
                }

                $items = is_array($iterable) ? $iterable : iterator_to_array($iterable);

                if ($templateDef->iterateFilter !== null) {
                    $items = $this->applyFilter($items, $templateDef->iterateFilter);
                }

                foreach ($items as $item) {
                    $iterData = array_merge($allData, [$templateDef->iterateAs => $item]);
                    $extras = $this->resolveExtraVariables($templateDef->extraVariables, $iterData);
                    $iterData = array_merge($iterData, $extras);
                    $content = $this->view->make($templateDef->viewName, $iterData)->render();
                    $path = $this->resolveOutputPath($templateDef->outputPath, $iterData);
                    $files[] = new \SchemaCraft\Generator\Api\GeneratedFile(path: $path, content: $content);
                }
            } else {
                $extras = $this->resolveExtraVariables($templateDef->extraVariables, $allData);
                $data = array_merge($allData, $extras);
                $content = $this->view->make($templateDef->viewName, $data)->render();
                $path = $this->resolveOutputPath($templateDef->outputPath, $data);
                $files[] = new \SchemaCraft\Generator\Api\GeneratedFile(path: $path, content: $content);
            }
        }

        return $files;
    }

    // ─── Inline template processing ───────────────────────────────────────────

    /**
     * Apply one inline insertion to the file cache and return the result.
     *
     * $fileCache is passed by reference so each insertion is immediately visible
     * to subsequent insertions into the same file (compound edits).
     *
     * originalContent on the returned value is always set to the content that was
     * in the cache (or on disk) before THIS insertion — never null — so the caller
     * can determine the pre-run state of inline-only files from the first result.
     *
     * @param  array<string, string>  $fileCache  Shared per-run cache: abs path → content.
     */
    private function runInlineTemplate(
        InlineTemplateDefinition $def,
        array $allData,
    ): InlineGeneratedFile {
        $extras = $this->resolveExtraVariables($def->extraVariables, $allData);
        $data = array_merge($allData, $extras);

        $path = $this->resolveOutputPath($def->targetPath, $data);
        $absPath = base_path($path);

        // Raw content bypasses Blade entirely — used for short literals like `use` statements.
        $snippet = $def->rawContent !== null
            ? $def->rawContent
            : $this->renderSnippet($def->viewName, $data);

        // Resolve [bracket] placeholders in anchor and searchPattern
        $anchor = $def->anchor !== null ? $this->resolveOutputPath($def->anchor, $data) : null;
        $searchPattern = $def->searchPattern !== null ? $this->resolveOutputPath($def->searchPattern, $data) : null;

        // Cache-first file load — seeds from disk if the path hasn't been touched yet
        $current = $this->readFromFileCache($absPath);

        if ($current === null) {
            return new InlineGeneratedFile(
                path: $path,
                content: '',
                snippet: $snippet,
                skipped: true,
                skipReason: 'file_not_found',
                originalContent: null,
            );
        }

        // Duplicate detection
        if (str_contains($current, trim($snippet))) {
            return new InlineGeneratedFile(
                path: $path,
                content: $current,
                snippet: $snippet,
                skipped: true,
                skipReason: 'already_present',
                originalContent: $current,
            );
        }

        [$modified, $skipReason] = $this->applyInsertion(
            $current, $snippet, $def->insertMode, $searchPattern, $anchor, $def->useRegex
        );

        if ($skipReason !== null) {
            return new InlineGeneratedFile(
                path: $path,
                content: $current,
                snippet: $snippet,
                skipped: true,
                skipReason: $skipReason,
                originalContent: $current,
            );
        }

        // Update cache so subsequent insertions into the same file see this change
        $this->writeToFileCache($absPath, $modified);

        return new InlineGeneratedFile(
            path: $path,
            content: $modified,
            snippet: $snippet,
            skipped: false,
            skipReason: null,
            originalContent: $current,
        );
    }

    // ─── Insertion logic ──────────────────────────────────────────────────────

    /**
     * Apply the insertion and return [modifiedContent, skipReason].
     * skipReason is null on success, a string code on failure.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function applyInsertion(
        string $content,
        string $snippet,
        string $mode,
        ?string $pattern,
        ?string $anchor,
        bool $useRegex,
    ): array {
        return match ($mode) {
            InlineTemplateDefinition::MODE_APPEND => [$content.$snippet, null],
            InlineTemplateDefinition::MODE_PREPEND => [$snippet.$content, null],
            default => $this->applyPatternInsertion($content, $snippet, $mode, $pattern, $anchor, $useRegex),
        };
    }

    /** @return array{0: ?string, 1: ?string} */
    private function applyPatternInsertion(
        string $content,
        string $snippet,
        string $mode,
        ?string $pattern,
        ?string $anchor,
        bool $useRegex,
    ): array {
        if ($pattern === null) {
            return [null, 'pattern_not_found'];
        }

        $insertAfter = $mode === InlineTemplateDefinition::MODE_AFTER;

        $insertPos = $useRegex
            ? $this->findInsertPositionRegex($content, $pattern, $anchor, $insertAfter)
            : $this->findInsertPositionLiteral($content, $pattern, $anchor, $insertAfter);

        if ($insertPos === null) {
            // Distinguish anchor-not-found from pattern-not-found
            if ($anchor !== null) {
                $anchorFound = $useRegex
                    ? (bool) preg_match($anchor, $content)
                    : str_contains($content, $anchor);

                if (! $anchorFound) {
                    return [null, 'anchor_not_found'];
                }
            }

            return [null, 'pattern_not_found'];
        }

        return [substr($content, 0, $insertPos).$snippet.substr($content, $insertPos), null];
    }

    private function findInsertPositionLiteral(
        string $content,
        string $pattern,
        ?string $anchor,
        bool $insertAfter,
    ): ?int {
        $searchFrom = 0;

        if ($anchor !== null) {
            $anchorPos = strpos($content, $anchor);

            if ($anchorPos === false) {
                return null;
            }

            $searchFrom = $anchorPos + strlen($anchor);
        }

        $patternPos = strpos($content, $pattern, $searchFrom);

        if ($patternPos === false) {
            return null;
        }

        return $insertAfter
            ? $patternPos + strlen($pattern)
            : $patternPos;
    }

    private function findInsertPositionRegex(
        string $content,
        string $pattern,
        ?string $anchor,
        bool $insertAfter,
    ): ?int {
        $searchFrom = 0;

        if ($anchor !== null) {
            if (! preg_match($anchor, $content, $anchorMatch, PREG_OFFSET_CAPTURE)) {
                return null;
            }

            $searchFrom = $anchorMatch[0][1] + strlen($anchorMatch[0][0]);
        }

        $searchContent = $searchFrom > 0 ? substr($content, $searchFrom) : $content;

        if (! preg_match($pattern, $searchContent, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $matchStart = $searchFrom + $match[0][1];
        $matchEnd = $matchStart + strlen($match[0][0]);

        return $insertAfter ? $matchEnd : $matchStart;
    }

    // ─── File cache ──────────────────────────────────────────────────────────

    /**
     * Return the cached content for $absPath.
     *
     * On cache miss, reads from disk and seeds the cache so all subsequent
     * accesses — in any pass — see the same content without re-reading disk.
     * Returns null only when the file does not exist on disk either.
     */
    private function readFromFileCache(string $absPath): ?string
    {
        if (array_key_exists($absPath, $this->fileCache)) {
            return $this->fileCache[$absPath];
        }

        if (file_exists($absPath)) {
            return $this->fileCache[$absPath] = file_get_contents($absPath);
        }

        return null;
    }

    private function writeToFileCache(string $absPath, string $content): void
    {
        $this->fileCache[$absPath] = $content;
    }

    // ─── Snippet rendering ────────────────────────────────────────────────────

    /**
     * Render an inline snippet template preserving leading whitespace.
     *
     * Laravel's PhpEngine::evaluatePath() applies ltrim() to all rendered output,
     * which strips indentation from snippet templates that start with whitespace.
     * For full-file templates that is fine; for inline snippets inserted mid-file
     * the indentation matters. We compile through the Blade compiler but capture
     * the output buffer directly, bypassing the ltrim.
     */
    private function renderSnippet(string $viewName, array $data): string
    {
        $view = $this->view->make($viewName, $data);

        if ($view instanceof \Illuminate\View\View) {
            $engine = $view->getEngine();

            if ($engine instanceof \Illuminate\View\Engines\CompilerEngine) {
                $compiler = $engine->getCompiler();
                $sourcePath = $view->getPath();

                if ($compiler->isExpired($sourcePath)) {
                    $compiler->compile($sourcePath);
                }

                $compiled = $compiler->getCompiledPath($sourcePath);
                $obLevel = ob_get_level();
                ob_start();

                try {
                    // $__env is required by compiled Blade directives such as @foreach.
                    // The standard render() path injects it via shared view data; the
                    // direct-include path here must inject it explicitly.
                    $__env = $this->view;
                    extract($data, EXTR_SKIP);
                    include $compiled;
                } catch (\Throwable $e) {
                    while (ob_get_level() > $obLevel) {
                        ob_end_clean();
                    }
                    throw $e;
                }

                return ob_get_clean();
            }
        }

        return $view->render();
    }

    // ─── Path and variable resolution ─────────────────────────────────────────

    /**
     * Substitute [dot.path] placeholders in an output path using chained resolution.
     */
    private function resolveOutputPath(string $path, array $allData): string
    {
        return preg_replace_callback('/\[([^\]]+)\]/', function ($match) use ($allData) {
            $resolved = $this->resolveChain($match[1], $allData);

            return $resolved !== null ? (string) $resolved : $match[0];
        }, $path);
    }

    /**
     * Resolve a dot-separated path through the data array.
     */
    private function resolveChain(string $dotPath, array $data): mixed
    {
        $segments = explode('.', $dotPath);
        $value = $data[array_shift($segments)] ?? null;

        if (! empty($segments) && is_string($value)) {
            $value = new NameChain($value);
        }

        foreach ($segments as $segment) {
            if ($value === null) {
                return null;
            }

            if (is_object($value) && method_exists($value, '__get')) {
                $value = $value->{$segment};
            } elseif (is_object($value) && property_exists($value, $segment)) {
                $value = $value->{$segment};
            } elseif (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return null;
            }
        }

        return $value;
    }

    /**
     * Resolve extra variables — string values get [bracket] placeholders substituted.
     */
    private function resolveExtraVariables(array $extraVars, array $allData): array
    {
        $resolved = [];

        foreach ($extraVars as $key => $value) {
            if (is_string($value)) {
                $resolved[$key] = $this->resolveOutputPath($value, $allData);
            } else {
                $resolved[$key] = $value;
            }
        }

        return $resolved;
    }

    /**
     * Apply a named filter to an iterable of items.
     */
    private function applyFilter(array $items, string $filter): array
    {
        return match ($filter) {
            'collection' => array_filter($items, fn ($r) => method_exists($r, 'isCollection') && $r->isCollection()),
            'singular' => array_filter($items, fn ($r) => method_exists($r, 'isSingular') && $r->isSingular()),
            default => $items,
        };
    }
}
