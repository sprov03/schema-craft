<?php

namespace SchemaCraft\Generator;

use Illuminate\Support\Str;
use ReflectionClass;
use SchemaCraft\Scanner\SchemaScanner;
use SchemaCraft\Scanner\TableDefinition;
use SchemaCraft\Schema;
use SchemaCraft\SchemaModel;

/**
 * Resolves the full dependency tree for a schema by walking its
 * child relationships recursively with cycle detection.
 *
 * Used by both the API generator (to cascade Resource files) and the
 * SDK generator (to cascade Data DTOs for referenced models).
 */
class DependencyResolver
{
    /**
     * Relationship types that produce child references in resources/DTOs.
     * BelongsTo and MorphTo are excluded — they only produce FK column IDs.
     */
    private const CHILD_RELATIONSHIP_TYPES = [
        'hasMany',
        'hasOne',
        'belongsToMany',
        'morphMany',
        'morphOne',
        'morphToMany',
    ];

    /**
     * Warnings collected during resolution for unresolvable models.
     *
     * @var string[]
     */
    private array $warnings = [];

    /**
     * Resolve all dependency schemas referenced via child relationships.
     *
     * Returns only dependencies — the root schema is excluded.
     *
     * @return array<string, TableDefinition> Keyed by model name
     */
    public function resolveDependencies(TableDefinition $rootTable): array
    {
        $this->warnings = [];
        $resolved = [];
        $visited = [];

        // Mark the root model as visited so we don't re-process it
        $rootModelFqcn = $this->deriveModelFqcn($rootTable->schemaClass);
        if ($rootModelFqcn !== null) {
            $visited[$rootModelFqcn] = true;
        }

        $this->walkDependencies($rootTable, $resolved, $visited);

        return $resolved;
    }

    /**
     * Get warnings collected during the last resolveDependencies() call.
     *
     * @return string[]
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Resolve a schema class name from a related model's FQCN.
     *
     * Strategy:
     * 1. Convention: App\Models\Comment → App\Schemas\CommentSchema
     * 2. Reflection: Read $schema property from SchemaModel subclass
     *
     * @return class-string<Schema>|null
     */
    public function resolveSchemaClass(string $relatedModelFqcn): ?string
    {
        // Strategy 1: Convention-based resolution
        $conventionSchema = $this->resolveByConvention($relatedModelFqcn);
        if ($conventionSchema !== null) {
            return $conventionSchema;
        }

        // Strategy 2: Reflection on SchemaModel subclass
        return $this->resolveByReflection($relatedModelFqcn);
    }

    /**
     * Recursively walk the dependency tree.
     *
     * @param  array<string, TableDefinition>  $resolved
     * @param  array<string, true>  $visited
     */
    private function walkDependencies(
        TableDefinition $table,
        array &$resolved,
        array &$visited,
    ): void {
        foreach ($table->relationships as $rel) {
            if (! in_array($rel->type, self::CHILD_RELATIONSHIP_TYPES)) {
                continue;
            }

            $relatedModelFqcn = $rel->relatedModel;

            // Skip if already visited (cycle detection)
            if (isset($visited[$relatedModelFqcn])) {
                continue;
            }

            $visited[$relatedModelFqcn] = true;

            $schemaClass = $this->resolveSchemaClass($relatedModelFqcn);

            if ($schemaClass === null) {
                $this->warnings[] = "Could not resolve schema for {$relatedModelFqcn}. Skipping.";

                continue;
            }

            $scanner = new SchemaScanner($schemaClass);
            $depTable = $scanner->scan();

            $modelName = Str::beforeLast(class_basename($schemaClass), 'Schema');

            if (! isset($resolved[$modelName])) {
                $resolved[$modelName] = $depTable;
            }

            // Recurse into this dependency's relationships
            $this->walkDependencies($depTable, $resolved, $visited);
        }
    }

    /**
     * Derive the model FQCN from a schema class name.
     *
     * e.g., App\Schemas\PostSchema → App\Models\Post
     *       SchemaCraft\Tests\Fixtures\Schemas\PostSchema → SchemaCraft\Tests\Fixtures\Models\Post
     */
    private function deriveModelFqcn(string $schemaClass): ?string
    {
        $namespace = Str::beforeLast($schemaClass, '\\');
        $className = class_basename($schemaClass);
        $modelName = Str::beforeLast($className, 'Schema');

        if ($modelName === $className) {
            return null;
        }

        // Replace last 'Schemas' namespace segment with 'Models'
        $modelNamespace = preg_replace('/\\\\Schemas$/', '\\Models', $namespace);

        if ($modelNamespace === $namespace) {
            return null;
        }

        return $modelNamespace.'\\'.$modelName;
    }

    /**
     * Convention-based resolution: replace Models→Schemas namespace and append Schema suffix.
     *
     * @return class-string<Schema>|null
     */
    private function resolveByConvention(string $relatedModelFqcn): ?string
    {
        $namespace = Str::beforeLast($relatedModelFqcn, '\\');
        $className = class_basename($relatedModelFqcn);

        // Replace last 'Models' namespace segment with 'Schemas'
        $schemaNamespace = preg_replace('/\\\\Models$/', '\\Schemas', $namespace);

        if ($schemaNamespace === $namespace) {
            return null;
        }

        $candidate = $schemaNamespace.'\\'.$className.'Schema';

        if (class_exists($candidate) && is_subclass_of($candidate, Schema::class)) {
            return $candidate;
        }

        return null;
    }

    /**
     * Reflection-based resolution: read $schema property from SchemaModel subclass.
     *
     * @return class-string<Schema>|null
     */
    private function resolveByReflection(string $relatedModelFqcn): ?string
    {
        if (! class_exists($relatedModelFqcn)) {
            return null;
        }

        if (! is_subclass_of($relatedModelFqcn, SchemaModel::class)) {
            return null;
        }

        $reflection = new ReflectionClass($relatedModelFqcn);

        if (! $reflection->hasProperty('schema')) {
            return null;
        }

        $property = $reflection->getProperty('schema');
        $schemaClass = $property->getDefaultValue();

        if ($schemaClass !== null && class_exists($schemaClass) && is_subclass_of($schemaClass, Schema::class)) {
            return $schemaClass;
        }

        return null;
    }
}
