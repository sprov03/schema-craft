<?php

namespace SchemaCraft\Generators;

use Illuminate\Contracts\View\Factory as ViewFactory;
use SchemaCraft\Generator\Api\GeneratedFile;
use SchemaCraft\Generator\Api\InlineGeneratedFile;

/**
 * Renders a generator's Blade templates and returns the resulting files.
 *
 * Supports chained dot-notation in output paths (e.g. [schema.model.plural.title]),
 * extra variables per template, and iteration over collections (e.g. relationships).
 */
class GeneratorRunner
{
    public function __construct(private readonly ViewFactory $view) {}

    /**
     * Render all templates and inline insertions for a generator run.
     *
     * When $writeInlineResults is false (preview mode), inline modifications are
     * computed in memory only — the file cache ensures multiple insertions into the
     * same file compound correctly even without touching disk.
     *
     * When $writeInlineResults is true (run mode), each successful inline insertion
     * is written to disk immediately before processing the next one, so subsequent
     * insertions into the same file operate on the already-modified content.
     *
     * @param  array<string, mixed>  $inputValues
     * @return array<GeneratedFile|InlineGeneratedFile>
     */
    public function run(
        SchemaCraftGenerator $generator,
        array $inputValues,
        bool $writeInlineResults = false,
    ): array {
        $allData = array_merge(
            ['phpOpenTag' => '<?php'],
            $inputValues,
            $generator->templateData(),
        );

        $files = $this->runTemplates($generator->templates(), $allData);

        $inlineDefs = array_map(
            fn ($item) => $item instanceof InlineTemplate ? $item->build() : $item,
            $generator->inlineTemplates($inputValues),
        );

        // Per-run file cache: absolute path → current content.
        // Shared across all inline insertions so same-file compound edits work
        // correctly in both preview (memory-only) and run (memory + disk) modes.
        //
        // Pre-seed from disk when the file already exists so that inline templates
        // operate on the real file content, preserving any edits the developer has
        // made. When the file does not exist yet (new file generated in this same
        // run), seed from the freshly-rendered template content instead, so inline
        // templates can still insert into it before it is written to disk.
        $fileCache = [];
        foreach ($files as $generatedFile) {
            $absPath = base_path($generatedFile->path);
            $fileCache[$absPath] = file_exists($absPath)
                ? file_get_contents($absPath)
                : $generatedFile->content;
        }

        foreach ($inlineDefs as $inlineDef) {
            $files[] = $this->runInlineTemplate($inlineDef, $allData, $fileCache, $writeInlineResults);
        }

        if ($writeInlineResults) {
            $generator->afterRun($inputValues);
        }

        return $files;
    }

    // ─── Template rendering ───────────────────────────────────────────────────

    /**
     * @param  TemplateDefinition[]  $templates
     * @return GeneratedFile[]
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
                    $files[] = new GeneratedFile(path: $path, content: $content);
                }
            } else {
                $extras = $this->resolveExtraVariables($templateDef->extraVariables, $allData);
                $data = array_merge($allData, $extras);
                $content = $this->view->make($templateDef->viewName, $data)->render();
                $path = $this->resolveOutputPath($templateDef->outputPath, $data);
                $files[] = new GeneratedFile(path: $path, content: $content);
            }
        }

        return $files;
    }

    // ─── Inline template processing ───────────────────────────────────────────

    /**
     * @param  array<string, string>  $fileCache  Shared per-run cache: abs path → content.
     */
    private function runInlineTemplate(
        InlineTemplateDefinition $def,
        array $allData,
        array &$fileCache,
        bool $writeInlineResults,
    ): InlineGeneratedFile {
        $extras = $this->resolveExtraVariables($def->extraVariables, $allData);
        $data = array_merge($allData, $extras);

        $path = $this->resolveOutputPath($def->targetPath, $data);
        $absPath = base_path($path);
        $snippet = $this->renderSnippet($def->viewName, $data);

        // Resolve [bracket] placeholders in anchor and searchPattern
        $anchor = $def->anchor !== null ? $this->resolveOutputPath($def->anchor, $data) : null;
        $searchPattern = $def->searchPattern !== null ? $this->resolveOutputPath($def->searchPattern, $data) : null;

        // Cache-first file load
        if (array_key_exists($absPath, $fileCache)) {
            $current = $fileCache[$absPath];
        } elseif (file_exists($absPath)) {
            $current = file_get_contents($absPath);
            $fileCache[$absPath] = $current;
        } else {
            return new InlineGeneratedFile(
                path: $path,
                content: '',
                snippet: $snippet,
                skipped: true,
                skipReason: 'file_not_found',
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
            );
        }

        // Update cache so subsequent insertions into the same file see this change
        $fileCache[$absPath] = $modified;

        if ($writeInlineResults) {
            file_put_contents($absPath, $modified);
        }

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
