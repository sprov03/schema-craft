<?php

namespace SchemaCraft\Generator\Sdk;

/**
 * Scans a generated API controller file to detect custom actions
 * beyond the standard CRUD methods.
 */
class ControllerActionScanner
{
    private const STANDARD_METHODS = [
        'apiRoutes',
        'getCollection',
        'get',
        'create',
        'update',
        'delete',
    ];

    /**
     * Scan a controller file's content and return custom action names.
     *
     * @return string[]
     */
    public function scan(string $controllerContent): array
    {
        $actions = [];

        // Match all public function declarations
        preg_match_all(
            '/public\s+function\s+(\w+)\s*\(/m',
            $controllerContent,
            $matches,
        );

        foreach ($matches[1] as $methodName) {
            if (in_array($methodName, self::STANDARD_METHODS, true)) {
                continue;
            }

            if ($methodName === '__construct') {
                continue;
            }

            $actions[] = $methodName;
        }

        return $actions;
    }

    /**
     * Scan a controller file at the given path and return custom action names.
     *
     * @return string[]
     */
    public function scanFile(string $controllerPath): array
    {
        if (! file_exists($controllerPath)) {
            return [];
        }

        $content = file_get_contents($controllerPath);

        if ($content === false) {
            return [];
        }

        return $this->scan($content);
    }
}
