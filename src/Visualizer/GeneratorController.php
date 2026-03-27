<?php

namespace SchemaCraft\Visualizer;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use SchemaCraft\Config\ConfigResolver;
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
     * Return all registered generators with their schema/input metadata,
     * plus the list of available schema classes for schema pickers.
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
                'schemaKey' => $input->schemaKey,
            ], $generator->inputs());

            $generators[] = [
                'class' => $class,
                'name' => $generator->name(),
                'schemas' => $generator->schemas(),
                'inputs' => $inputs,
            ];
        }

        $directories = ConfigResolver::schemaDirectories();
        $schemaClasses = (new SchemaDiscovery)->discover($directories);

        $schemas = array_map(fn ($schemaClass) => [
            'class' => $schemaClass,
            'modelName' => $this->resolveModelName($schemaClass),
        ], $schemaClasses);

        return new JsonResponse([
            'generators' => $generators,
            'schemas' => $schemas,
        ]);
    }

    /**
     * Return column data for the schema slots of a generator.
     * Used by the UI to populate per-schema column checkboxes.
     *
     * Query params:
     *   generator  - FQCN of the generator
     *   schemas    - associative array of slot key => schema class FQCN
     */
    public function detail(Request $request): JsonResponse
    {
        $request->validate([
            'generator' => ['required', 'string'],
        ]);

        $generator = $this->registry->resolve($request->query('generator'));
        $schemaColumns = [];

        foreach ($generator->schemas() as $slotKey) {
            $schemaClass = $request->input('schemas.'.$slotKey);

            if (! $schemaClass || ! class_exists($schemaClass)) {
                $schemaColumns[$slotKey] = [];

                continue;
            }

            $table = (new SchemaScanner($schemaClass))->scan();

            $schemaColumns[$slotKey] = array_map(fn ($col) => [
                'name' => $col->name,
                'type' => $col->columnType,
                'nullable' => $col->nullable,
                'primary' => $col->primary,
            ], $table->columns);
        }

        return new JsonResponse([
            'success' => true,
            'schemaColumns' => $schemaColumns,
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

        [$schemaContexts, $inputValues] = $this->resolveGeneratorData($request, $generator);

        $generatedFiles = $this->runner->run($generator, $schemaContexts, $inputValues);

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

        [$schemaContexts, $inputValues] = $this->resolveGeneratorData($request, $generator);

        $generatedFiles = $this->runner->run($generator, $schemaContexts, $inputValues);

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
     * Build schema contexts and resolve input values from the request.
     *
     * @return array{0: array<string, GeneratorSchemaContext>, 1: array<string, mixed>}
     */
    private function resolveGeneratorData(Request $request, $generator): array
    {
        $schemaContexts = [];
        $schemasInput = $request->input('schemas', []);

        foreach ($generator->schemas() as $slotKey) {
            $slotData = $schemasInput[$slotKey] ?? null;

            if (! $slotData || empty($slotData['class'])) {
                continue;
            }

            $schemaClass = $slotData['class'];

            if (! class_exists($schemaClass)) {
                continue;
            }

            $table = (new SchemaScanner($schemaClass))->scan();
            $selectedColumns = $slotData['selectedColumns'] ?? [];
            $schemaContexts[$slotKey] = new GeneratorSchemaContext($table, $selectedColumns);
        }

        // Resolve input values
        $rawInputs = $request->input('inputs', []);
        $inputValues = [];

        foreach ($generator->inputs() as $inputDef) {
            $value = $rawInputs[$inputDef->key] ?? $inputDef->default;

            if ($inputDef->type === 'schemaColumn') {
                // Resolve column name string to GeneratorColumn
                $value = $this->resolveColumnValue($value, $schemaContexts, $inputDef->schemaKey);
            } elseif ($inputDef->type === 'schemaColumns') {
                // Resolve array of column names to GeneratorColumn[]
                $value = is_array($value)
                    ? array_filter(array_map(
                        fn ($name) => $this->resolveColumnValue($name, $schemaContexts, $inputDef->schemaKey),
                        $value,
                    ))
                    : [];
            }

            $inputValues[$inputDef->key] = $value;
        }

        return [$schemaContexts, $inputValues];
    }

    /**
     * Resolve a column name string to a GeneratorColumn from the given schema slot.
     */
    private function resolveColumnValue(mixed $columnName, array $schemaContexts, ?string $schemaKey): ?GeneratorColumn
    {
        if (! is_string($columnName) || empty($columnName)) {
            return null;
        }

        $context = $schemaContexts[$schemaKey ?? 'schema'] ?? null;

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
