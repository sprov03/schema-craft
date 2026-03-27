<?php

namespace SchemaCraft\Generators;

use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Discovers and holds registered SchemaCraftGenerator instances.
 *
 * Generators can be registered explicitly via register() or auto-discovered
 * from a directory via discover(). The discover() method uses token-based
 * scanning for performance — no class loading required during discovery.
 */
class GeneratorRegistry
{
    /** @var array<class-string<SchemaCraftGenerator>, SchemaCraftGenerator> */
    private array $generators = [];

    /**
     * Register a generator class explicitly.
     *
     * @param  class-string<SchemaCraftGenerator>  $class
     */
    public function register(string $class): void
    {
        if (! is_subclass_of($class, SchemaCraftGenerator::class)) {
            throw new InvalidArgumentException("{$class} must extend ".SchemaCraftGenerator::class);
        }

        $this->generators[$class] = new $class;
    }

    /**
     * Scan a directory recursively for SchemaCraftGenerator subclasses and register them.
     */
    public function discover(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $fqcn = $this->extractGeneratorClass($file->getPathname());

            if ($fqcn !== null && ! isset($this->generators[$fqcn])) {
                $this->generators[$fqcn] = new $fqcn;
            }
        }
    }

    /**
     * Get all registered generator instances, keyed by FQCN.
     *
     * @return array<class-string<SchemaCraftGenerator>, SchemaCraftGenerator>
     */
    public function all(): array
    {
        return $this->generators;
    }

    /**
     * Resolve a single generator instance by FQCN.
     *
     * @param  class-string<SchemaCraftGenerator>  $class
     *
     * @throws InvalidArgumentException
     */
    public function resolve(string $class): SchemaCraftGenerator
    {
        if (! isset($this->generators[$class])) {
            throw new InvalidArgumentException("Generator [{$class}] is not registered.");
        }

        return $this->generators[$class];
    }

    /**
     * Extract the FQCN from a PHP file if it extends SchemaCraftGenerator.
     *
     * @return class-string<SchemaCraftGenerator>|null
     */
    private function extractGeneratorClass(string $filePath): ?string
    {
        $contents = file_get_contents($filePath);

        if ($contents === false) {
            return null;
        }

        if (! str_contains($contents, 'extends') || ! str_contains($contents, 'SchemaCraftGenerator')) {
            return null;
        }

        $tokens = token_get_all($contents);
        $namespace = '';
        $className = null;
        $extendsGenerator = false;

        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = $this->parseNamespace($tokens, $i);
            }

            if ($token[0] === T_CLASS) {
                $prev = $this->skipWhitespace($tokens, $i, -1);

                if ($prev !== null && is_array($prev) && $prev[0] === T_NEW) {
                    continue;
                }

                $className = $this->parseClassName($tokens, $i);
                $extendsGenerator = $this->parseExtendsSchemaCraftGenerator($tokens, $i);

                break;
            }
        }

        if ($className === null || ! $extendsGenerator) {
            return null;
        }

        $fqcn = $namespace !== '' ? $namespace.'\\'.$className : $className;

        if (! class_exists($fqcn)) {
            require_once $filePath;
        }

        if (! class_exists($fqcn) || ! is_subclass_of($fqcn, SchemaCraftGenerator::class)) {
            return null;
        }

        return $fqcn;
    }

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

    private function parseExtendsSchemaCraftGenerator(array $tokens, int $i): bool
    {
        $count = count($tokens);

        for ($j = $i + 1; $j < $count; $j++) {
            $token = $tokens[$j];

            if ($token === '{') {
                break;
            }

            if (is_array($token) && $token[0] === T_EXTENDS) {
                for ($k = $j + 1; $k < $count; $k++) {
                    if (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                        $parent = $tokens[$k][1];

                        return $parent === 'SchemaCraftGenerator'
                            || str_ends_with($parent, '\\SchemaCraftGenerator');
                    }

                    if (is_array($tokens[$k]) && $tokens[$k][0] !== T_WHITESPACE) {
                        break;
                    }
                }
            }
        }

        return false;
    }

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
