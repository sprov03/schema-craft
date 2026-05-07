<?php

namespace SchemaCraft\Visualizer;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use SchemaCraft\Generator\Api\GeneratedFile;
use SchemaCraft\Generator\Api\InlineGeneratedFile;
use SchemaCraft\Generators\GeneratorRegistry;
use SchemaCraft\Generators\GeneratorRunner;
use SchemaCraft\Generators\GeneratorSchemaContext;
use SchemaCraft\Generators\InputDefinition;
use SchemaCraft\Generators\InputTypeRegistry;
use SchemaCraft\Generators\InputTypes\SchemaFieldPickerInputType;
use SchemaCraft\Generators\SchemaCraftGenerator;
use SchemaCraft\Scanner\SchemaResolver;
use SchemaCraft\Scanner\SchemaScanner;

class GeneratorController
{
    public function __construct(
        private readonly GeneratorRegistry $registry,
        private readonly GeneratorRunner $runner,
        private readonly InputTypeRegistry $inputTypeRegistry,
    ) {}

    /**
     * Return all registered generators (class + name only).
     * Input metadata is fetched lazily via nextStep().
     */
    public function config(): JsonResponse
    {
        $generators = [];

        foreach ($this->registry->all() as $class => $generator) {
            $generators[] = [
                'class' => $class,
                'name' => $generator->name(),
            ];
        }

        return new JsonResponse(['generators' => $generators]);
    }

    /**
     * Return the next interactive step for the wizard.
     *
     * The UI sends the full accumulated state on every call. The server iterates
     * the generator's data() keys in order:
     *  - Keys already in accumulated: resolve them (to build $resolved context) and skip.
     *  - First key NOT in accumulated: return it as the next step, along with any
     *    computed values that were auto-resolved along the way.
     *  - When all keys are consumed: return done=true.
     *
     * Request body:
     *   generator   - FQCN of the generator
     *   accumulated - associative map of all values collected so far (interactive + computed)
     *
     * Response (step):
     *   { done: false, computed: {key: {value, label?, description?}}, step: {key, label, type, ...} }
     *
     * Response (complete):
     *   { done: true, computed: {} }
     */
    public function nextStep(Request $request): JsonResponse
    {
        $request->validate([
            'generator' => ['required', 'string'],
            'accumulated' => ['sometimes', 'array'],
        ]);

        $generator = $this->registry->resolve($request->input('generator'));
        $accumulated = $request->input('accumulated', []);

        $resolved = [];
        $computedBag = [];

        foreach ($generator->data() as $key => $callback) {
            if (array_key_exists($key, $accumulated)) {
                // Already answered — reconstruct resolved value for context
                $result = $callback($resolved);

                if ($result instanceof InputDefinition) {
                    if ($result->hasValue) {
                        // Forced value always wins over whatever was accumulated
                        $handler = $this->inputTypeRegistry->get($result->type);
                        $resolved[$key] = $handler->resolve($result->forcedValue, $result, $resolved);
                    } elseif ($result->type === 'computed') {
                        $resolved[$key] = $result->computedValue;
                    } else {
                        $handler = $this->inputTypeRegistry->get($result->type);
                        $resolved[$key] = $handler->resolve($accumulated[$key], $result, $resolved);
                    }
                } else {
                    // Silent computed — trust accumulated value as-is
                    $resolved[$key] = $accumulated[$key];
                }
            } else {
                // Not yet answered — evaluate callback to determine what to show next
                $result = $callback($resolved);

                // Forced value — resolve silently, skip UI entirely
                if ($result instanceof InputDefinition && $result->hasValue) {
                    $handler = $this->inputTypeRegistry->get($result->type);
                    $resolved[$key] = $handler->resolve($result->forcedValue, $result, $resolved);
                    $jsonValue = $resolved[$key] instanceof \Stringable ? (string) $resolved[$key] : $resolved[$key];
                    $computedBag[$key] = ['value' => $jsonValue];

                    continue;
                }

                if ($result instanceof InputDefinition && $result->type === 'computed') {
                    // Displayable computed row — resolve value silently and add to bag
                    $resolved[$key] = $result->computedValue;

                    // JSON-safe representation: cast Stringable objects (e.g. ResourceDirectoryValue) to string
                    $jsonValue = $result->computedValue instanceof \Stringable
                        ? (string) $result->computedValue
                        : $result->computedValue;

                    $entry = ['value' => $jsonValue];

                    if ($result->label !== '') {
                        $entry['label'] = $result->label;
                    }

                    if ($result->description !== null) {
                        $entry['description'] = $result->description;
                    }

                    $computedBag[$key] = $entry;

                    continue;
                }

                if ($result instanceof InputDefinition) {
                    // Interactive step — return it to the UI
                    $handler = $this->inputTypeRegistry->get($result->type);

                    $step = array_merge([
                        'key' => $key,
                        'label' => $result->label,
                        'type' => $result->type,
                        'default' => $result->default,
                        'description' => $result->description,
                        'help' => $result->help,
                    ], $handler->toFrontend($result, $resolved));

                    return new JsonResponse([
                        'done' => false,
                        'computed' => $computedBag,
                        'step' => $step,
                    ]);
                }

                // Silent computed — auto-advance
                $resolved[$key] = $result;
                $computedBag[$key] = ['value' => $result instanceof \Stringable ? (string) $result : $result];

                continue;
            }
        }

        return new JsonResponse([
            'done' => true,
            'computed' => $computedBag,
        ]);
    }

    /**
     * Lazy-load columns and relationships for a related model.
     *
     * Used by the schemaFieldPicker to expand relationship groups at arbitrary depth.
     *
     * Query params:
     *   model - FQCN of the Eloquent model to resolve the schema for
     */
    public function relatedSchema(Request $request): JsonResponse
    {
        $request->validate([
            'model' => ['required', 'string'],
        ]);

        $modelFqcn = $request->query('model');
        $schemaClass = SchemaResolver::findByModel($modelFqcn);

        if ($schemaClass === null || ! class_exists($schemaClass)) {
            return new JsonResponse([
                'columns' => [],
                'relationships' => [],
            ]);
        }

        $table = (new SchemaScanner($schemaClass))->scan();
        $context = new GeneratorSchemaContext($table, null, null);

        [$columns, $relationships] = SchemaFieldPickerInputType::buildFromContext($context);

        return new JsonResponse([
            'columns' => $columns,
            'relationships' => $relationships,
        ]);
    }

    /**
     * Preview the generated files without writing to disk.
     */
    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'generator' => ['required', 'string'],
            'accumulated' => ['sometimes', 'array'],
        ]);

        $generator = $this->registry->resolve($request->input('generator'));
        $resolved = $this->resolveAccumulated($request->input('accumulated', []), $generator);

        $results = $this->runner->run($generator, $resolved);

        // Merge multiple inline results for the same path into one preview entry.
        // The runner's file cache already chains insertions correctly, so the last
        // InlineGeneratedFile for a given path holds the fully-modified content.
        // We keep the first result's originalContent as the true "before" state.
        $inlineByPath = [];
        $regularFiles = [];

        // ─── Frontend file-entry contract ────────────────────────────────────────
        //
        // Each entry in the `files` array sent to the visualizer must conform to
        // this shape. The frontend showFilePreviewModal() and showFile() functions
        // branch entirely on these keys — there are NO type guards on `type`.
        //
        //   {
        //     type:            'file' | 'inline'
        //     path:            string   — relative path from project root
        //     content:         string   — the newly generated content
        //     exists:          bool     — true when the file currently exists on disk
        //     existingContent: string|null — current on-disk content, or null for new files
        //     skipped?:        bool     — inline only; true when the insertion was skipped
        //   }
        //
        // The frontend `getCategory()` function classifies entries as:
        //   'new'       — exists === false  (existingContent is null)
        //   'changed'   — existingContent != null && content !== existingContent
        //   'unchanged' — existingContent != null && content === existingContent
        //   'deleted'   — f.removed === true  (only used in schema-removal flows)
        //
        // CRITICAL: `existingContent` MUST be populated for existing files on all
        // entry types ('file' and 'inline'). Without it, getCategory() permanently
        // falls through to 'unchanged' and the diff toggle is never shown — even
        // when the generated content differs significantly from what is on disk.
        //
        // This was lost during the unification of the old per-generator preview
        // controllers (GenerateController::createResourcePreview, filamentPreview,
        // sdkPreview) into this single generic preview() method. Those older methods
        // all explicitly read file_get_contents() and set existingContent.
        // ─────────────────────────────────────────────────────────────────────────

        foreach ($results as $result) {
            if ($result instanceof InlineGeneratedFile) {
                $path = $result->path;
                if (! isset($inlineByPath[$path])) {
                    $inlineByPath[$path] = [
                        'type' => 'inline',
                        'path' => $path,
                        'content' => $result->content,
                        'skipped' => $result->skipped,
                        'exists' => file_exists(base_path($path)),
                        'existingContent' => $result->originalContent,
                    ];
                } else {
                    // Subsequent insertion into the same file: update to latest content.
                    // existingContent stays as the true original from the first insertion.
                    $inlineByPath[$path]['content'] = $result->content;
                    if (! $result->skipped) {
                        $inlineByPath[$path]['skipped'] = false;
                    }
                }
            } else {
                // Read the on-disk content so the frontend can classify this entry
                // correctly and render the before/after diff view. See contract above.
                $absPath = base_path($result->path);
                $existingContent = file_exists($absPath) ? file_get_contents($absPath) : null;

                $regularFiles[] = [
                    'type' => 'file',
                    'path' => $result->path,
                    'content' => $result->content,
                    'exists' => $existingContent !== null,
                    'existingContent' => $existingContent,
                ];
            }
        }

        $files = array_merge($regularFiles, array_values($inlineByPath));

        return new JsonResponse(['success' => true, 'files' => $files]);
    }

    /**
     * Generate files and write them to disk.
     */
    public function run(Request $request, Filesystem $fs): JsonResponse
    {
        $request->validate([
            'generator' => ['required', 'string'],
            'accumulated' => ['sometimes', 'array'],
        ]);

        $generator = $this->registry->resolve($request->input('generator'));
        $force = (bool) $request->input('force', false);
        $resolved = $this->resolveAccumulated($request->input('accumulated', []), $generator);

        $results = $this->runner->run($generator, $resolved, writeInlineResults: true);

        $resultFiles = [];
        $createdCount = 0;

        foreach ($results as $result) {
            if ($result instanceof InlineGeneratedFile) {
                $resultFiles[] = $this->writeInlineResult($result);

                if (! $result->skipped) {
                    $createdCount++;
                }

                continue;
            }

            /** @var GeneratedFile $result */
            $absPath = base_path($result->path);

            if (file_exists($absPath) && ! $force) {
                $resultFiles[] = [
                    'type' => 'file',
                    'path' => $result->path,
                    'skipped' => true,
                    'message' => basename($result->path).' already exists.',
                ];

                continue;
            }

            $fs->ensureDirectoryExists(dirname($absPath));
            $fs->put($absPath, $result->content);
            $createdCount++;
            $resultFiles[] = [
                'type' => 'file',
                'path' => $result->path,
                'created' => true,
                'message' => basename($result->path).' created.',
            ];
        }

        return new JsonResponse([
            'success' => true,
            'files' => $resultFiles,
            'message' => "{$createdCount} file(s) created or modified.",
        ]);
    }

    /**
     * Return the columns and relationships for a schema class.
     *
     * Used by the schemaSelector widget to lazily load column/relationship
     * checkboxes after the user picks a schema from the dropdown.
     */
    public function schemaInfo(Request $request): JsonResponse
    {
        $request->validate(['class' => ['required', 'string']]);

        $schemaClass = $request->query('class');

        if (! $schemaClass || ! class_exists($schemaClass)) {
            return new JsonResponse(['columns' => [], 'relationships' => []], 404);
        }

        $table = (new SchemaScanner($schemaClass))->scan();
        $context = new GeneratorSchemaContext($table, null, null);

        $columns = array_map(fn ($col) => [
            'name' => $col->name,
            'type' => $col->columnType,
        ], $context->allColumns);

        $relationships = array_map(fn ($rel) => [
            'name' => $rel->definition->name,
            'type' => $rel->type,
        ], $context->allRelationships);

        return new JsonResponse([
            'columns' => $columns,
            'relationships' => $relationships,
        ]);
    }

    /**
     * Return the parsed GENERATORS.md as a single document with sections.
     *
     * Uses the same markdown parsing format as DocsController so the frontend
     * can render it with the same docs-article CSS.
     */
    public function generatorsDocs(): JsonResponse
    {
        $path = realpath(__DIR__.'/../../GENERATORS.md');

        if (! $path || ! file_exists($path)) {
            return new JsonResponse(['error' => 'GENERATORS.md not found.'], 404);
        }

        return new JsonResponse(['document' => $this->parseMarkdownDocument($path, 'Custom Generators')]);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Resolve the full accumulated payload into a map of typed PHP values
     * suitable for passing to the generator runner and inlineTemplates().
     *
     * Iterates data() in order, reconstructing $resolved context at each step
     * so that InputType::resolve() can reference earlier values (e.g. schema context).
     *
     * @param  array<string, mixed>  $accumulated
     * @return array<string, mixed>
     */
    private function resolveAccumulated(array $accumulated, SchemaCraftGenerator $generator): array
    {
        $resolved = [];

        foreach ($generator->data() as $key => $callback) {
            $rawValue = $accumulated[$key] ?? null;
            $result = $callback($resolved);

            if ($result instanceof InputDefinition) {
                if ($result->hasValue) {
                    // Forced value — always resolve from definition, ignore accumulated
                    $handler = $this->inputTypeRegistry->get($result->type);
                    $resolved[$key] = $handler->resolve($result->forcedValue, $result, $resolved);
                } elseif ($result->type === 'computed') {
                    $resolved[$key] = $result->computedValue;
                } else {
                    $handler = $this->inputTypeRegistry->get($result->type);
                    $value = $rawValue ?? $result->default;
                    $resolved[$key] = $handler->resolve($value, $result, $resolved);
                }
            } else {
                // Silent computed — trust accumulated, fall back to callback result
                $resolved[$key] = array_key_exists($key, $accumulated) ? $rawValue : $result;
            }
        }

        return $resolved;
    }

    /**
     * Parse a markdown file into a document structure matching DocsController's format.
     *
     * @return array{name: string, slug: string, sections: array}
     */
    private function parseMarkdownDocument(string $filePath, ?string $fallbackName = null): array
    {
        $content = file_get_contents($filePath);
        $name = $this->extractMarkdownTitle($content, $filePath, $fallbackName);
        $sections = $this->splitMarkdownByH2($content);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'sections' => $sections,
        ];
    }

    private function extractMarkdownTitle(string $content, string $filePath, ?string $fallbackName): string
    {
        if (preg_match('/^# (.+)$/m', $content, $matches)) {
            return trim($matches[1]);
        }

        if ($fallbackName !== null) {
            return $fallbackName;
        }

        $basename = pathinfo($filePath, PATHINFO_FILENAME);
        $basename = preg_replace('/^\d+[-_]/', '', $basename);

        return Str::headline($basename);
    }

    /**
     * @return array<int, array{title: string, slug: string, content: string}>
     */
    private function splitMarkdownByH2(string $markdown): array
    {
        $parts = preg_split('/^(## .+)$/m', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE);

        $sections = [];

        $intro = trim($parts[0] ?? '');
        if ($intro !== '') {
            $sections[] = [
                'title' => 'Introduction',
                'slug' => 'introduction',
                'content' => $intro,
            ];
        }

        for ($i = 1, $count = count($parts); $i < $count; $i += 2) {
            $title = trim(str_replace('## ', '', $parts[$i]));
            $body = trim($parts[$i + 1] ?? '');
            $fullContent = $parts[$i]."\n\n".$body;

            $sections[] = [
                'title' => $title,
                'slug' => Str::slug($title),
                'content' => $fullContent,
            ];
        }

        return $sections;
    }

    private function writeInlineResult(InlineGeneratedFile $result): array
    {
        if ($result->skipped) {
            $reason = match ($result->skipReason) {
                'file_not_found' => 'file not found',
                'already_present' => 'already inserted',
                'anchor_not_found' => 'anchor not found in file',
                'pattern_not_found' => 'insertion pattern not found',
                default => $result->skipReason ?? 'skipped',
            };

            return [
                'type' => 'inline',
                'path' => $result->path,
                'skipped' => true,
                'skipReason' => $result->skipReason,
                'message' => basename($result->path).' — '.$reason.'.',
            ];
        }

        return [
            'type' => 'inline',
            'path' => $result->path,
            'inserted' => true,
            'message' => basename($result->path).' — snippet inserted.',
        ];
    }
}
