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
        public string $schemaPrefix,
        public string $modelPrefix,
        public string $servicePrefix,
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

        return new self(
            name: $name,
            connection: $config['connection'] ?? $name,
            schemaNamespace: $namespaces['schema'] ?? 'App\\Schemas',
            modelNamespace: $namespaces['model'] ?? 'App\\Models',
            serviceNamespace: $namespaces['service'] ?? 'App\\Models\\Services',
            factoryNamespace: $namespaces['factory'] ?? 'Database\\Factories',
            testNamespace: $namespaces['test'] ?? 'Tests\\Unit',
            schemaPrefix: $prefixes['schema'] ?? '',
            modelPrefix: $prefixes['model'] ?? '',
            servicePrefix: $prefixes['service'] ?? '',
        );
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
     * Handles standard Laravel PSR-4 namespace mappings:
     *   App\Schemas → app/Schemas
     *   Database\Factories → database/factories
     *   Tests\Unit → tests/Unit
     */
    private function namespaceToDirectory(string $namespace): string
    {
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
}
