<?php

namespace SchemaCraft\Visualizer;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use SchemaCraft\Config\ConfigResolver;
use SchemaCraft\Generators\FilamentPanelDiscovery;
use SchemaCraft\Generators\GeneratorColumn;
use SchemaCraft\Generators\GeneratorRegistry;
use SchemaCraft\Generators\GeneratorRunner;
use SchemaCraft\Generators\GeneratorSchemaContext;
use SchemaCraft\Migration\SchemaDiscovery;
use SchemaCraft\Scanner\SchemaScanner;

class GeneratorController
{
    public function __construct(
        private readonly GeneratorRegistry $registry,
        private readonly GeneratorRunner $runner,
    ) {}

    /**
     * Return all registered generators with their input metadata,
     * plus the list of available schema classes for schema selectors.
     */
    public function config(): JsonResponse
    {
        $generators = [];

        foreach ($this->registry->all() as $class => $generator) {
            $inputs = array_map(fn ($input) => [
                'key' => $input->key,
                'label' => $input->label,
                'type' => $input->type,
                'default' => $input->default,
                'options' => $input->options,
                'selectColumns' => $input->selectColumns,
                'selectRelationships' => $input->selectRelationships,
                'selectorKey' => $input->selectorKey,
            ], $generator->inputs());

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

        // Discover resource directories for selectResourceDirectory inputs
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

        $generatedFiles = $this->runner->run($generator, $inputValues);

        $files = array_map(fn ($file) => [
            'path' => $file->path,
            'content' => $file->content,
            'exists' => file_exists(base_path($file->path)),
        ], $generatedFiles);

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

        $generatedFiles = $this->runner->run($generator, $inputValues);

        $resultFiles = [];
        $createdCount = 0;

        foreach ($generatedFiles as $file) {
            $absPath = base_path($file->path);

            if (file_exists($absPath) && ! $force) {
                $resultFiles[] = [
                    'path' => $file->path,
                    'skipped' => true,
                    'message' => basename($file->path).' already exists.',
                ];

                continue;
            }

            $fs->ensureDirectoryExists(dirname($absPath));
            $fs->put($absPath, $file->content);
            $createdCount++;
            $resultFiles[] = [
                'path' => $file->path,
                'created' => true,
                'message' => basename($file->path).' created.',
            ];
        }

        return new JsonResponse([
            'success' => true,
            'files' => $resultFiles,
            'message' => "{$createdCount} file(s) created.",
        ]);
    }

    /**
     * Resolve input values from the request, building GeneratorSchemaContext
     * for schemaSelector inputs and resolving schemaColumn references.
     *
     * @return array<string, mixed>
     */
    private function resolveGeneratorData(Request $request, $generator): array
    {
        $rawInputs = $request->input('inputs', []);
        $inputValues = [];
        $schemaContexts = []; // Track schema contexts for schemaColumn resolution

        // First pass: resolve schemaSelector inputs to GeneratorSchemaContext
        foreach ($generator->inputs() as $inputDef) {
            if ($inputDef->type !== 'schemaSelector') {
                continue;
            }

            $selectorData = $rawInputs[$inputDef->key] ?? null;

            if (! $selectorData || empty($selectorData['class']) || ! class_exists($selectorData['class'])) {
                continue;
            }

            $table = (new SchemaScanner($selectorData['class']))->scan();
            $selectedColumns = $selectorData['selectedColumns'] ?? [];
            $selectedRelationships = $selectorData['selectedRelationships'] ?? [];

            $context = new GeneratorSchemaContext($table, $selectedColumns, $selectedRelationships);
            $inputValues[$inputDef->key] = $context;
            $schemaContexts[$inputDef->key] = $context;
        }

        // Second pass: resolve all other inputs
        foreach ($generator->inputs() as $inputDef) {
            if ($inputDef->type === 'schemaSelector') {
                continue; // Already handled
            }

            $value = $rawInputs[$inputDef->key] ?? $inputDef->default;

            if ($inputDef->type === 'schemaColumn') {
                $value = $this->resolveColumnValue($value, $schemaContexts, $inputDef->selectorKey);
            } elseif ($inputDef->type === 'schemaColumns') {
                $value = is_array($value)
                    ? array_filter(array_map(
                        fn ($name) => $this->resolveColumnValue($name, $schemaContexts, $inputDef->selectorKey),
                        $value,
                    ))
                    : [];
            }

            $inputValues[$inputDef->key] = $value;
        }

        return $inputValues;
    }

    /**
     * Resolve a column name string to a GeneratorColumn from a schema context.
     */
    private function resolveColumnValue(mixed $columnName, array $schemaContexts, ?string $selectorKey): ?GeneratorColumn
    {
        if (! is_string($columnName) || empty($columnName)) {
            return null;
        }

        $context = $schemaContexts[$selectorKey ?? 'schema'] ?? null;

        if ($context === null) {
            return null;
        }

        foreach ($context->allColumns as $col) {
            if ($col->name === $columnName) {
                return $col;
            }
        }

        return null;
    }

    private function resolveModelName(string $schemaClass): string
    {
        $className = class_basename($schemaClass);

        return Str::beforeLast($className, 'Schema') ?: $className;
    }
}
