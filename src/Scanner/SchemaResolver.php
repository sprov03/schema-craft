<?php

namespace SchemaCraft\Scanner;

use Illuminate\Support\Str;
use SchemaCraft\Config\ConfigResolver;
use SchemaCraft\Schema;

/**
 * Resolves a model FQCN to its corresponding Schema class (and the inverse).
 *
 * Extracted from ActionScanner so both the generator system and the
 * actions system can share the same lookup logic without coupling.
 */
class SchemaResolver
{
    /**
     * Resolve a relation's related-class reference down to the Eloquent Model FQCN.
     *
     * Relation attributes (#[BelongsTo], #[HasManyThrough], ...) historically take a
     * Model FQCN, but may now also take a Schema FQCN for nicer schema-to-schema
     * navigation in the editor. This collapses the Schema form back to its Model the
     * moment it is read, so every downstream consumer keeps seeing a Model class and
     * needs no changes. Anything that is NOT a Schema subclass (a Model FQCN, the
     * MorphTo Model placeholder, etc.) passes through unchanged — backwards compatible.
     *
     * Convention is the inverse of findByModel():
     *   App\Schemas\PostSchema      → App\Models\Post
     *   App\Schemas\Blog\PostSchema → App\Models\Blog\Post
     */
    public static function resolveModelClass(string $classOrSchema): string
    {
        // Detection gate: only Schema subclasses get converted. is_subclass_of returns
        // false for non-existent/non-Schema strings, so the pass-through is safe.
        if (! is_subclass_of($classOrSchema, Schema::class)) {
            return $classOrSchema;
        }

        return self::schemaToModelFqcn($classOrSchema);
    }

    /**
     * Pure string transform of a Schema FQCN to its conventional Model FQCN — no class loading,
     * no existence gate. Use this for code generation where the target classes may not be
     * autoloadable in the current process (e.g. emitting a model for another project). Callers
     * that need the existence-gated behavior should use resolveModelClass().
     *
     *   App\Schemas\PostSchema      → App\Models\Post
     *   App\Schemas\Blog\PostSchema → App\Models\Blog\Post
     */
    public static function schemaToModelFqcn(string $schemaClass): string
    {
        $parts = explode('\\', $schemaClass);
        $schemaBaseName = array_pop($parts);
        $namespace = implode('\\', $parts);

        $modelBaseName = Str::beforeLast($schemaBaseName, 'Schema');

        // Mid-namespace swap (mirrors findByModel) so nested namespaces like
        // App\Schemas\Blog resolve to App\Models\Blog, not just trailing \Schemas.
        $modelNamespace = preg_replace('/\\\\Schemas(\\\\|$)/', '\\Models$1', $namespace, 1);

        return $modelNamespace.'\\'.$modelBaseName;
    }

    /**
     * Find the Schema class for a given Eloquent model FQCN.
     *
     * Resolution order:
     *  1. Swap \Models namespace segment with \Schemas and append "Schema"
     *     e.g. App\Models\Contact → App\Schemas\ContactSchema
     *  2. Fall back to checking all configured connection schema namespaces.
     *
     * Returns null when no schema class can be found.
     */
    public static function findByModel(string $modelFqcn): ?string
    {
        $parts = explode('\\', $modelFqcn);
        $modelBaseName = array_pop($parts);
        $namespace = implode('\\', $parts);

        // Replace \Models segment with \Schemas
        $schemaNamespace = preg_replace('/\\\\Models(\\\\|$)/', '\\Schemas$1', $namespace, 1);
        $schemaClass = $schemaNamespace.'\\'.$modelBaseName.'Schema';

        if (class_exists($schemaClass) && is_subclass_of($schemaClass, Schema::class)) {
            return $schemaClass;
        }

        // Fall back to configured connection namespaces
        if (class_exists(ConfigResolver::class)) {
            try {
                foreach (ConfigResolver::allConnectionNames() as $name) {
                    $config = ConfigResolver::resolveConnection($name);
                    $candidate = $config->schemaClass($modelBaseName);

                    if (class_exists($candidate) && is_subclass_of($candidate, Schema::class)) {
                        return $candidate;
                    }
                }
            } catch (\Throwable) {
                // ConfigResolver may not be available in unit tests
            }
        }

        return null;
    }
}
