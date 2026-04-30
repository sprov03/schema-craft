<?php

namespace SchemaCraft\Scanner;

use SchemaCraft\Config\ConfigResolver;
use SchemaCraft\Schema;

/**
 * Resolves a model FQCN to its corresponding Schema class.
 *
 * Extracted from ActionScanner so both the generator system and the
 * actions system can share the same lookup logic without coupling.
 */
class SchemaResolver
{
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
