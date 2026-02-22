<?php

namespace SchemaCraft\Migration;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SchemaCraft\Schema;

/**
 * Discovers all classes that extend Schema in given directories.
 *
 * Uses token-based PHP file scanning for performance — no class loading
 * or reflection required during discovery.
 */
class SchemaDiscovery
{
    /**
     * Find all Schema subclasses in the given directories.
     *
     * @param  string[]  $directories  Absolute paths to scan.
     * @return class-string<Schema>[]
     */
    public function discover(array $directories): array
    {
        $classes = [];

        foreach ($directories as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory),
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $fqcn = $this->extractSchemaClass($file->getPathname());

                if ($fqcn !== null) {
                    $classes[] = $fqcn;
                }
            }
        }

        sort($classes);

        return $classes;
    }

    /**
     * Extract the FQCN from a PHP file if it extends Schema.
     *
     * @return class-string<Schema>|null
     */
    private function extractSchemaClass(string $filePath): ?string
    {
        $contents = file_get_contents($filePath);

        if ($contents === false) {
            return null;
        }

        // Quick check before tokenizing
        if (! str_contains($contents, 'extends') || ! str_contains($contents, 'Schema')) {
            return null;
        }

        $tokens = token_get_all($contents);
        $namespace = '';
        $className = null;
        $extendsSchema = false;

        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token)) {
                continue;
            }

            // Capture namespace
            if ($token[0] === T_NAMESPACE) {
                $namespace = $this->parseNamespace($tokens, $i);
            }

            // Capture class name and check if it extends Schema
            if ($token[0] === T_CLASS) {
                // Skip anonymous classes
                $prev = $this->skipWhitespace($tokens, $i, -1);

                if ($prev !== null && is_array($prev) && $prev[0] === T_NEW) {
                    continue;
                }

                $className = $this->parseClassName($tokens, $i);

                // Look for "extends" and check if it's "Schema" or ends with "\Schema"
                $extendsSchema = $this->parseExtendsSchema($tokens, $i);

                break;
            }
        }

        if ($className === null || ! $extendsSchema) {
            return null;
        }

        $fqcn = $namespace !== '' ? $namespace.'\\'.$className : $className;

        // Verify the class actually exists and extends Schema
        if (! class_exists($fqcn)) {
            return null;
        }

        if (! is_subclass_of($fqcn, Schema::class)) {
            return null;
        }

        return $fqcn;
    }

    /**
     * Parse a namespace from tokens starting at the T_NAMESPACE position.
     */
    private function parseNamespace(array $tokens, int &$i): string
    {
        $namespace = '';
        $count = count($tokens);
        $i++;

        for (; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === ';' || $token === '{') {
                break;
            }

            if (is_array($token) && in_array($token[0], [T_NAME_QUALIFIED, T_STRING, T_NS_SEPARATOR], true)) {
                $namespace .= $token[1];
            }
        }

        return trim($namespace);
    }

    /**
     * Parse a class name from tokens starting at the T_CLASS position.
     */
    private function parseClassName(array $tokens, int $i): ?string
    {
        $count = count($tokens);

        for ($j = $i + 1; $j < $count; $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                return $tokens[$j][1];
            }

            if (is_array($tokens[$j]) && $tokens[$j][0] !== T_WHITESPACE) {
                break;
            }
        }

        return null;
    }

    /**
     * Check if the class extends Schema (or \SchemaCraft\Schema, etc.).
     */
    private function parseExtendsSchema(array $tokens, int $i): bool
    {
        $count = count($tokens);

        for ($j = $i + 1; $j < $count; $j++) {
            $token = $tokens[$j];

            if ($token === '{') {
                break;
            }

            if (is_array($token) && $token[0] === T_EXTENDS) {
                // Get the parent class name
                for ($k = $j + 1; $k < $count; $k++) {
                    if (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                        $parent = $tokens[$k][1];

                        // Check if parent is "Schema" or ends with "\Schema"
                        return $parent === 'Schema' || str_ends_with($parent, '\\Schema');
                    }

                    if (is_array($tokens[$k]) && $tokens[$k][0] !== T_WHITESPACE) {
                        break;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Skip whitespace tokens in a direction.
     */
    private function skipWhitespace(array $tokens, int $i, int $direction): mixed
    {
        $j = $i + $direction;

        while ($j >= 0 && $j < count($tokens)) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j += $direction;

                continue;
            }

            return $tokens[$j];
        }

        return null;
    }
}
