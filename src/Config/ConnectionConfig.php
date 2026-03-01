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
     * Convert a namespace to a directory path relative to base_path().
     *
     * App\Schemas → app/Schemas
     */
    private function namespaceToDirectory(string $namespace): string
    {
        $path = str_replace('\\', '/', $namespace);

        if (str_starts_with($path, 'App/')) {
            $path = 'app/'.substr($path, 4);
        }

        return $path;
    }
}
