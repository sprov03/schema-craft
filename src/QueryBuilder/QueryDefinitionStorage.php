<?php

namespace SchemaCraft\QueryBuilder;

use Illuminate\Filesystem\Filesystem;

/**
 * Handles persistence of QueryDefinition JSON files to disk.
 *
 * Stores each query as an individual JSON file in a configurable directory
 * (default: app/QueryDefinitions/). Files are git-friendly and can be
 * loaded back into the visual builder for editing.
 */
class QueryDefinitionStorage
{
    public function __construct(
        private Filesystem $files,
        private ?string $storagePath = null,
    ) {}

    /**
     * Save a QueryDefinition to disk.
     *
     * @return string The absolute file path where the definition was saved
     */
    public function save(QueryDefinition $query): string
    {
        $path = $this->filePath($query->name);

        $now = now()->toIso8601String();

        // Preserve original createdAt if updating
        $createdAt = $now;
        if ($this->exists($query->name)) {
            $existing = $this->load($query->name);
            $createdAt = $existing->createdAt ?? $now;
        }

        $query = new QueryDefinition(
            name: $query->name,
            baseTable: $query->baseTable,
            baseModel: $query->baseModel,
            joins: $query->joins,
            conditions: $query->conditions,
            sorts: $query->sorts,
            selects: $query->selects,
            output: $query->output,
            indexSuggestions: $query->indexSuggestions,
            baseSchema: $query->baseSchema,
            createdAt: $createdAt,
            updatedAt: $now,
        );

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, json_encode($query->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        return $path;
    }

    /**
     * Load a QueryDefinition from disk by name.
     */
    public function load(string $name): QueryDefinition
    {
        $path = $this->filePath($name);

        if (! $this->files->exists($path)) {
            throw new \InvalidArgumentException("Query definition [{$name}] not found at [{$path}].");
        }

        $data = json_decode($this->files->get($path), true);

        if ($data === null) {
            throw new \RuntimeException("Failed to parse query definition [{$name}] from [{$path}].");
        }

        return QueryDefinition::fromArray($data);
    }

    /**
     * List all saved query definitions.
     *
     * @return array<int, array{name: string, baseModel: string, updatedAt: string|null}>
     */
    public function list(): array
    {
        $directory = $this->getStoragePath();

        if (! $this->files->isDirectory($directory)) {
            return [];
        }

        $files = $this->files->glob("{$directory}/*.json");
        $queries = [];

        foreach ($files as $file) {
            $data = json_decode($this->files->get($file), true);

            if ($data === null) {
                continue;
            }

            $queries[] = [
                'name' => $data['name'] ?? pathinfo($file, PATHINFO_FILENAME),
                'baseModel' => $data['baseModel'] ?? '',
                'updatedAt' => $data['updatedAt'] ?? null,
            ];
        }

        usort($queries, fn ($a, $b) => ($b['updatedAt'] ?? '') <=> ($a['updatedAt'] ?? ''));

        return $queries;
    }

    /**
     * Delete a saved query definition.
     */
    public function delete(string $name): bool
    {
        $path = $this->filePath($name);

        if (! $this->files->exists($path)) {
            return false;
        }

        return $this->files->delete($path);
    }

    /**
     * Check if a query definition exists.
     */
    public function exists(string $name): bool
    {
        return $this->files->exists($this->filePath($name));
    }

    /**
     * Get the full file path for a query definition.
     */
    private function filePath(string $name): string
    {
        return $this->getStoragePath().'/'.$name.'.json';
    }

    /**
     * Get the storage directory path.
     */
    private function getStoragePath(): string
    {
        if ($this->storagePath !== null) {
            return $this->storagePath;
        }

        return config('schema-craft.query_definitions_path', app_path('QueryDefinitions'));
    }
}
