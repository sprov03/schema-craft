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

        $connectionName ??= config('schema-craft.default_connection', 'default');

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

        $apiName ??= config('schema-craft.default', 'default');

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
     * Get the configured schema directories.
     *
     * @return string[]
     */
    public static function schemaDirectories(): array
    {
        $paths = config('schema-craft.schema_paths');

        if ($paths === null) {
            return [app_path('Schemas')];
        }

        return $paths;
    }
}
