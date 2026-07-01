<?php

namespace SchemaCraft\Config;

/**
 * Value object representing the configuration for a single DB connection.
 */
class ConnectionConfig
{
    public function __construct(
        public string $name,
        public string $connection,
        public string $schemaNamespace,
        public string $modelNamespace,
        public string $serviceNamespace,
        public string $factoryNamespace,
        public string $testNamespace,
        public string $actionNamespace,
        public string $schemaPrefix,
        public string $modelPrefix,
        public string $servicePrefix,
        // Raw, open-ended namespaces map from config. Backs namespace()/hasNamespace() so
        // generators can reference arbitrary keys (not just the typed ones above).
        public array $namespaces = [],
    ) {}

    /**
     * Create a ConnectionConfig from a raw config array.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(string $name, array $config): self
    {
        $prefixes = $config['prefixes'] ?? [];
        $namespaces = $config['namespaces'] ?? [];
        $modelNamespace = $namespaces['model'] ?? 'App\\Models';

        return new self(
            name: $name,
            connection: $config['connection'] ?? $name,
            schemaNamespace: $namespaces['schema'] ?? 'App\\Schemas',
            modelNamespace: $modelNamespace,
            serviceNamespace: $namespaces['service'] ?? 'App\\Models\\Services',
            factoryNamespace: $namespaces['factory'] ?? 'Database\\Factories',
            testNamespace: $namespaces['test'] ?? 'Tests\\Unit',
            actionNamespace: $namespaces['actions'] ?? $modelNamespace.'\\Actions',
            schemaPrefix: $prefixes['schema'] ?? '',
            modelPrefix: $prefixes['model'] ?? '',
            servicePrefix: $prefixes['service'] ?? '',
            namespaces: $namespaces,
        );
    }

    /**
     * Resolve a configured namespace by its raw config key (e.g. 'factory', 'seeder').
     *
     * The `namespaces` map is intentionally open — projects may add arbitrary keys and
     * reference them from generators. Referencing a key that isn't configured throws with a
     * clear, actionable message rather than silently defaulting, so a missing entry surfaces
     * immediately ("go add it to schema-craft.php") instead of a file quietly landing in the
     * wrong place. Use hasNamespace() first when a key is genuinely optional.
     */
    public function namespace(string $key): string
    {
        if (! isset($this->namespaces[$key])) {
            $available = array_keys($this->namespaces);

            throw new \InvalidArgumentException(
                "Namespace key [{$key}] is not configured for db connection [{$this->name}]. "
                ."Add it under schema-craft.php → db_connections.{$this->name}.namespaces. "
                .'Available keys: '.($available !== [] ? implode(', ', $available) : '(none)').'.'
            );
        }

        return $this->namespaces[$key];
    }

    /**
     * Whether a namespace key is configured for this connection.
     */
    public function hasNamespace(string $key): bool
    {
        return isset($this->namespaces[$key]);
    }

    /**
     * Get the prefixed model class name.
     *
     * e.g. prefix 'Prefix' + base 'Account' → 'PrefixAccount'
     */
    public function prefixedModelName(string $baseModelName): string
    {
        return $this->modelPrefix.$baseModelName;
    }

    /**
     * Get the prefixed schema class name.
     *
     * e.g. prefix 'Prefix' + base 'Account' → 'PrefixAccountSchema'
     */
    public function prefixedSchemaName(string $baseModelName): string
    {
        return $this->schemaPrefix.$baseModelName.'Schema';
    }

    /**
     * Get the fully-qualified schema class name for a base model name.
     */
    public function schemaClass(string $baseModelName): string
    {
        return $this->schemaNamespace.'\\'.$this->prefixedSchemaName($baseModelName);
    }

    /**
     * Get the fully-qualified model class name for a base model name.
     */
    public function modelClass(string $baseModelName): string
    {
        return $this->modelNamespace.'\\'.$this->prefixedModelName($baseModelName);
    }

    /**
     * Whether the generated files need an explicit $connection property.
     *
     * Returns true when the connection is not the application default.
     */
    public function needsConnectionProperty(): bool
    {
        $appDefault = config('database.default', 'mysql');

        return $this->connection !== 'default' && $this->connection !== $appDefault;
    }

    /**
     * Get the schema directory path relative to base_path().
     */
    public function schemaDirectory(): string
    {
        return $this->namespaceToDirectory($this->schemaNamespace);
    }

    /**
     * Get the action namespace for a specific model.
     *
     * e.g. 'App\PanaceaCore\Actions' + 'Dog' → 'App\PanaceaCore\Actions\Dog'
     */
    public function actionNamespaceForModel(string $modelName): string
    {
        return $this->actionNamespace.'\\'.$modelName;
    }

    /**
     * Get the action directory path relative to base_path().
     */
    public function actionDirectory(): string
    {
        return $this->namespaceToDirectory($this->actionNamespace);
    }

    /**
     * Get the model directory path relative to base_path().
     */
    public function modelDirectory(): string
    {
        return $this->namespaceToDirectory($this->modelNamespace);
    }

    /**
     * Get the service directory path relative to base_path().
     */
    public function serviceDirectory(): string
    {
        return $this->namespaceToDirectory($this->serviceNamespace);
    }

    /**
     * Get the absolute path to a service file for a given base model name.
     */
    public function servicePath(string $baseModelName): string
    {
        $prefixedModel = $this->prefixedModelName($baseModelName);

        return base_path($this->serviceDirectory().'/'.$prefixedModel.'Service.php');
    }

    /**
     * Get the factory directory path relative to base_path().
     */
    public function factoryDirectory(): string
    {
        return $this->namespaceToDirectory($this->factoryNamespace);
    }

    /**
     * Get the absolute path to a factory file for a given base model name.
     */
    public function factoryPath(string $baseModelName): string
    {
        $prefixedModel = $this->prefixedModelName($baseModelName);

        return base_path($this->factoryDirectory().'/'.$prefixedModel.'Factory.php');
    }

    /**
     * Get the model test directory path relative to base_path().
     */
    public function modelTestDirectory(): string
    {
        return $this->namespaceToDirectory($this->testNamespace);
    }

    /**
     * Get the absolute path to a model test file for a given base model name.
     */
    public function modelTestPath(string $baseModelName): string
    {
        $prefixedModel = $this->prefixedModelName($baseModelName);

        return base_path($this->modelTestDirectory().'/'.$prefixedModel.'ModelTest.php');
    }

    /**
     * Convert a namespace to a directory path relative to base_path().
     *
     * Resolution order (single source of truth — no second copy of the composer logic):
     *   1. Composer's PSR-4 map (authoritative) via ConfigResolver::resolveViaComposerAutoload().
     *      Only used when the resolved directory is under base_path(), since callers build
     *      factory/service/etc. paths relative to base_path(). This is what lets a custom root
     *      like a plain `Factories\` resolve to its real `factories/` dir instead of `Factories/`.
     *   2. Laravel-convention fallback for when Composer hasn't registered the prefix — notably
     *      the test harness, where App\/Database\/Tests\ aren't in the Testbench autoloader.
     *   3. Verbatim namespace-as-path.
     */
    public static function namespaceToDirectory(string $namespace): string
    {
        $absolute = ConfigResolver::resolveViaComposerAutoload($namespace);

        if ($absolute !== null) {
            // Composer registers PSR-4 dirs relative to vendor/composer (e.g.
            // "<base>/vendor/composer/../../factories"), so collapse the .. segments before
            // stripping base_path — otherwise callers get ugly, comparison-fragile paths.
            $absolute = self::normalizePath($absolute);
            $base = self::normalizePath(base_path());

            if (str_starts_with($absolute, $base)) {
                return ltrim(substr($absolute, strlen($base)), '/');
            }
        }

        $path = str_replace('\\', '/', $namespace);

        if (str_starts_with($path, 'App/')) {
            return 'app/'.substr($path, 4);
        }

        if (str_starts_with($path, 'Database/Factories')) {
            return 'database/factories'.substr($path, 18);
        }

        if (str_starts_with($path, 'Database/Seeders')) {
            return 'database/seeders'.substr($path, 16);
        }

        if (str_starts_with($path, 'Tests/')) {
            return 'tests/'.substr($path, 6);
        }

        return $path;
    }

    /**
     * Lexically collapse "." and ".." segments in a path without touching the filesystem
     * (realpath() can't be used — target dirs may not exist yet at generation time).
     */
    private static function normalizePath(string $path): string
    {
        $isAbsolute = str_starts_with($path, '/');
        $parts = [];

        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }

            if ($seg === '..') {
                if ($parts !== [] && end($parts) !== '..') {
                    array_pop($parts);
                } elseif (! $isAbsolute) {
                    $parts[] = '..';
                }

                continue;
            }

            $parts[] = $seg;
        }

        return ($isAbsolute ? '/' : '').implode('/', $parts);
    }
}
