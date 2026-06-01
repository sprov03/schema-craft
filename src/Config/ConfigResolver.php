<?php

namespace SchemaCraft\Config;

use InvalidArgumentException;

/**
 * Resolves API configuration from the schema-craft config file.
 *
 * When no config file exists, returns hardcoded defaults that match
 * the original behavior of the commands — fully backward-compatible.
 */
class ConfigResolver
{
    /**
     * Resolve the ConnectionConfig for a given DB connection config name.
     *
     * When $connectionName is null, uses the default connection from config.
     * When no config file exists at all, returns hardcoded defaults.
     */
    public static function resolveConnection(?string $connectionName = null): ConnectionConfig
    {
        $connections = config('schema-craft.db_connections');

        // No config at all — return hardcoded defaults
        if ($connections === null || count($connections) === 0) {
            return self::connectionDefaults();
        }

        $connectionName ??= array_key_first($connections) ?? 'default';

        if (! isset($connections[$connectionName])) {
            throw new InvalidArgumentException(
                "DB connection configuration [{$connectionName}] not found. Available: ".implode(', ', array_keys($connections))
            );
        }

        return ConnectionConfig::fromArray($connectionName, $connections[$connectionName]);
    }

    /**
     * Get all configured DB connection config names.
     *
     * @return string[]
     */
    public static function allConnectionNames(): array
    {
        $connections = config('schema-craft.db_connections');

        if ($connections === null || count($connections) === 0) {
            return ['default'];
        }

        return array_keys($connections);
    }

    /**
     * Return the hardcoded default ConnectionConfig.
     */
    public static function connectionDefaults(): ConnectionConfig
    {
        return ConnectionConfig::fromArray('default', []);
    }

    /**
     * Resolve the ConnectionConfig for a given Laravel database connection name.
     *
     * Searches all configured db_connections for one whose `connection` value
     * matches the given database connection name. Returns defaults when not found.
     *
     * @deprecated Use resolveForSchema() instead — multiple connection configs can
     *             share the same database connection, making this method unreliable.
     */
    public static function resolveByDatabaseConnection(?string $databaseConnection): ConnectionConfig
    {
        if ($databaseConnection === null) {
            return self::connectionDefaults();
        }

        $connections = config('schema-craft.db_connections');

        if ($connections !== null) {
            foreach ($connections as $name => $config) {
                $connConfig = ConnectionConfig::fromArray($name, $config);

                if ($connConfig->connection === $databaseConnection) {
                    return $connConfig;
                }
            }
        }

        return self::connectionDefaults();
    }

    /**
     * Resolve the ApiConfig for a given API name.
     *
     * When $apiName is null, uses the default API from config.
     * When no config file exists at all, returns hardcoded defaults.
     *
     * Schema/model/service namespaces are merged from the default ConnectionConfig
     * as fallback values, so they don't need to be duplicated in the API config.
     */
    public static function resolve(?string $apiName = null): ApiConfig
    {
        $apis = config('schema-craft.apis');

        // No config file at all — return hardcoded defaults
        if ($apis === null) {
            return self::defaults();
        }

        $apiName ??= array_key_first($apis) ?? 'default';

        if (! isset($apis[$apiName])) {
            throw new InvalidArgumentException(
                "API configuration [{$apiName}] not found. Available: ".implode(', ', array_keys($apis))
            );
        }

        $apiArray = $apis[$apiName];

        // Merge default connection namespaces as fallbacks for schema/model/service
        $defaultConnection = self::connectionDefaults();
        $apiArray['namespaces'] = array_merge([
            'schema' => $defaultConnection->schemaNamespace,
            'model' => $defaultConnection->modelNamespace,
            'service' => $defaultConnection->serviceNamespace,
        ], $apiArray['namespaces'] ?? []);

        return ApiConfig::fromArray($apiName, $apiArray);
    }

    /**
     * Get all configured API names.
     *
     * @return string[]
     */
    public static function allApiNames(): array
    {
        $apis = config('schema-craft.apis');

        if ($apis === null) {
            return ['default'];
        }

        return array_keys($apis);
    }

    /**
     * Return the hardcoded default ApiConfig matching original command behavior.
     */
    public static function defaults(): ApiConfig
    {
        return ApiConfig::fromArray('default', []);
    }

    /**
     * Resolve the connection config name for a given schema class.
     *
     * Matches the schema class's namespace against all configured db_connections.
     * Returns the first matching connection config name, or 'default' if none match.
     */
    public static function resolveConnectionNameForSchema(string $schemaClass): string
    {
        $schemaNamespace = substr($schemaClass, 0, (int) strrpos($schemaClass, '\\'));
        $connections = config('schema-craft.db_connections');

        if ($connections !== null) {
            foreach ($connections as $name => $config) {
                $connConfig = ConnectionConfig::fromArray($name, $config);

                if ($connConfig->schemaNamespace === $schemaNamespace) {
                    return $name;
                }
            }
        }

        return 'default';
    }

    /**
     * Resolve the ConnectionConfig for a given schema class.
     *
     * Uses the schema's namespace to find the correct connection config.
     * This is the preferred method over resolveByDatabaseConnection() because
     * multiple connection configs can share the same database connection.
     */
    public static function resolveForSchema(string $schemaClass): ConnectionConfig
    {
        $connectionName = self::resolveConnectionNameForSchema($schemaClass);

        return self::resolveConnection($connectionName);
    }

    /**
     * Get the configured schema directories.
     *
     * Derives schema directories from each db_connections entry's schema
     * namespace. Falls back to app/Schemas when no connections are configured.
     *
     * @return string[]
     */
    public static function schemaDirectories(): array
    {
        $paths = [];
        $connections = config('schema-craft.db_connections');

        if ($connections !== null && count($connections) > 0) {
            foreach ($connections as $name => $config) {
                $connConfig = ConnectionConfig::fromArray($name, $config);
                $dir = self::namespaceToPath($connConfig->schemaNamespace);

                if (! in_array($dir, $paths, true)) {
                    $paths[] = $dir;
                }
            }
        }

        // Fallback when no db_connections are configured
        if (count($paths) === 0) {
            $paths[] = app_path('Schemas');
        }

        return $paths;
    }

    /**
     * Convert a namespace to an absolute filesystem path.
     */
    private static function namespaceToPath(string $namespace): string
    {
        // Consult Composer's PSR-4 map first — this lets non-App\\ namespaces (custom
        // packages, dev-loaded fixture namespaces like SchemaCraft\\Tests\\Fixtures\\,
        // path-repository packages mounted under packages/, etc.) resolve to their
        // actual on-disk locations rather than guessing from base_path() + namespace.
        // Longest-prefix-match so e.g. 'SchemaCraft\\Tests\\Fixtures\\Schemas' picks
        // a PSR-4 entry for 'SchemaCraft\\Tests\\' before any shorter prefix.
        $resolved = self::resolveViaComposerAutoload($namespace);
        if ($resolved !== null) {
            return $resolved;
        }

        $path = str_replace('\\', '/', $namespace);

        if (str_starts_with($path, 'App/')) {
            return app_path(substr($path, 4));
        }

        return base_path($path);
    }

    private static function resolveViaComposerAutoload(string $namespace): ?string
    {
        $autoloadPath = base_path('vendor/autoload.php');
        if (! file_exists($autoloadPath)) {
            return null;
        }

        $loader = require $autoloadPath;
        if (! is_object($loader) || ! method_exists($loader, 'getPrefixesPsr4')) {
            return null;
        }

        // Composer's loader returns map<prefix-with-trailing-backslash, string[] of dirs>.
        // Normalize the lookup namespace to also end with a backslash for stable matching.
        $lookup = rtrim($namespace, '\\').'\\';
        $prefixes = $loader->getPrefixesPsr4();

        // Sort prefixes by length descending so "SchemaCraft\Tests\\" wins over "SchemaCraft\\".
        $sortedPrefixes = array_keys($prefixes);
        usort($sortedPrefixes, fn ($a, $b) => strlen($b) - strlen($a));

        foreach ($sortedPrefixes as $prefix) {
            if (! str_starts_with($lookup, $prefix)) {
                continue;
            }

            $remaining = substr($lookup, strlen($prefix));
            $relative = rtrim(str_replace('\\', '/', $remaining), '/');
            $dirs = $prefixes[$prefix];
            if (empty($dirs)) {
                continue;
            }
            // First registered directory wins for the prefix; Composer would search them
            // in order at class-load time, so the first is the canonical "primary" path.
            return rtrim($dirs[0], '/').($relative === '' ? '' : '/'.$relative);
        }

        return null;
    }
}
