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
     * Resolve the ApiConfig for a given API name.
     *
     * When $apiName is null, uses the default API from config.
     * When no config file exists at all, returns hardcoded defaults.
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

        return ApiConfig::fromArray($apiName, $apis[$apiName]);
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
