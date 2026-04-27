<?php

namespace SchemaCraft\Visualizer;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use SchemaCraft\Config\ConfigResolver;
use SchemaCraft\Generator\Api\GeneratedFile;
use SchemaCraft\Generator\Api\InlineGeneratedFile;
use SchemaCraft\Generators\FilamentPanelDiscovery;
use SchemaCraft\Generators\GeneratorRegistry;
use SchemaCraft\Generators\GeneratorRunner;
use SchemaCraft\Generators\InputTypeRegistry;
use SchemaCraft\Generators\SchemaCraftGenerator;
use SchemaCraft\Migration\SchemaDiscovery;
use SchemaCraft\Scanner\SchemaScanner;

class GeneratorController
{
    public function __construct(
        private readonly GeneratorRegistry $registry,
        private readonly GeneratorRunner $runner,
        private readonly InputTypeRegistry $inputTypeRegistry,
    ) {}

    /**
     * Return all registered generators with their input metadata,
     * plus the list of available schema classes for schema selectors.
     */
    public function config(): JsonResponse
    {
        $generators = [];

        foreach ($this->registry->all() as $class => $generator) {
            $inputs = array_map(function ($input) {
                $handler = $this->inputTypeRegistry->get($input->type);

                return array_merge([
                    'key' => $input->key,
                    'label' => $input->label,
                    'type' => $input->type,
                    'default' => $input->default,
                ], $handler->toFrontend($input));
            }, $generator->inputs());

            $generators[] = [
                'class' => $class,
                'name' => $generator->name(),
                'inputs' => $inputs,
            ];
        }

        $directories = ConfigResolver::schemaDirectories();
        $schemaClasses = (new SchemaDiscovery)->discover($directories);

        $schemas = array_map(fn ($schemaClass) => [
            'class' => $schemaClass,
            'modelName' => $this->resolveModelName($schemaClass),
        ], $schemaClasses);

        $resourceDirectories = (new FilamentPanelDiscovery)->discover();

        return new JsonResponse([
            'generators' => $generators,
            'schemas' => $schemas,
            'resourceDirectories' => $resourceDirectories,
        ]);
    }

    /**
     * Return column and relationship data for a schema selector input.
     * Used by the UI to populate column/relationship checkboxes.
     *
     * Query params:
     *   generator  - FQCN of the generator
     *   selectors  - associative array of selector key => schema class FQCN
     */
    public function detail(Request $request): JsonResponse
    {
        $request->validate([
            'generator' => ['required', 'string'],
        ]);

        $generator = $this->registry->resolve($request->query('generator'));
        $selectorData = [];

        $selectors = $request->input('selectors', []);

        foreach ($selectors as $selectorKey => $schemaClass) {
            if (! $schemaClass || ! class_exists($schemaClass)) {
                $selectorData[$selectorKey] = ['columns' => [], 'relationships' => []];

                continue;
            }

            $table = (new SchemaScanner($schemaClass))->scan();

            $selectorData[$selectorKey] = [
                'columns' => array_map(fn ($col) => [
                    'name' => $col->name,
                    'type' => $col->columnType,
                    'nullable' => $col->nullable,
                    'primary' => $col->primary,
                ], $table->columns),
                'relationships' => array_map(fn ($rel) => [
                    'name' => $rel->name,
                    'type' => $rel->type,
                    'relatedModel' => class_basename($rel->relatedModel),
                ], $table->relationships),
            ];
        }

        return new JsonResponse([
            'success' => true,
            'selectorData' => $selectorData,
        ]);
    }

    /**
     * Preview the generated files without writing to disk.
     */
    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'generator' => ['required', 'string'],
        ]);

        $generator = $this->registry->resolve($request->input('generator'));
        $inputValues = $this->resolveGeneratorData($request, $generator);

        $results = $this->runner->run($generator, $inputValues);

        $files = array_map(function ($result) {
            if ($result instanceof InlineGeneratedFile) {
                return [
                    'type' => 'inline',
                    'path' => $result->path,
                    'content' => $result->content,
                    'snippet' => $result->snippet,
                    'skipped' => $result->skipped,
                    'exists' => file_exists(base_path($result->path)),
                ];
            }

            return [
                'type' => 'file',
                'path' => $result->path,
                'content' => $result->content,
                'exists' => file_exists(base_path($result->path)),
            ];
        }, $results);

        return new JsonResponse(['success' => true, 'files' => $files]);
    }

    /**
     * Generate files and write them to disk.
     */
    public function run(Request $request, Filesystem $fs): JsonResponse
    {
        $request->validate([
            'generator' => ['required', 'string'],
        ]);

        $generator = $this->registry->resolve($request->input('generator'));
        $force = (bool) $request->input('force', false);
        $inputValues = $this->resolveGeneratorData($request, $generator);

        $results = $this->runner->run($generator, $inputValues, writeInlineResults: true);

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
     * Resolve input values from the request using registered InputType handlers.
     *
     * Pass 1: resolve all pass-1 inputs (e.g. schemaSelector — independent)
     * Pass 2: resolve all pass-2 inputs (e.g. schemaColumn — depend on pass-1 results)
     *
     * @return array<string, mixed>
     */
    private function resolveGeneratorData(Request $request, SchemaCraftGenerator $generator): array
    {
        $rawInputs = $request->input('inputs', []);
        $resolved = [];

        foreach ([1, 2] as $pass) {
            foreach ($generator->inputs() as $inputDef) {
                $handler = $this->inputTypeRegistry->get($inputDef->type);

                if ($handler->resolutionPass() !== $pass) {
                    continue;
                }

                $resolved[$inputDef->key] = $handler->resolve(
                    $rawInputs[$inputDef->key] ?? $inputDef->default,
                    $inputDef,
                    $resolved,
                );
            }
        }

        return $resolved;
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

    private function resolveModelName(string $schemaClass): string
    {
        $className = class_basename($schemaClass);

        return Str::beforeLast($className, 'Schema') ?: $className;
    }
}
