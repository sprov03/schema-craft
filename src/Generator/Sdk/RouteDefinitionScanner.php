<?php

namespace SchemaCraft\Generator\Sdk;

/**
 * Scans a generated API controller file to extract actual Route:: definitions
 * from the apiRoutes() method. Returns structured endpoint data instead of
 * relying on hardcoded assumptions.
 */
class RouteDefinitionScanner
{
    private const STANDARD_ACTIONS = [
        'getCollection',
        'get',
        'create',
        'update',
        'delete',
    ];

    /**
     * Scan controller content and return all Route:: definitions.
     *
     * @return array<int, array{method: string, path: string, action: string, type: string, description: ?string}>
     */
    public function scan(string $controllerContent): array
    {
        $endpoints = [];

        // Match Route::method('path', [Controller::class, 'action'])
        preg_match_all(
            '/Route::(\w+)\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*\[.*?[\'"](\w+)[\'"]\s*\]\s*\)/m',
            $controllerContent,
            $matches,
            PREG_SET_ORDER,
        );

        $descriptions = $this->extractMethodDescriptions($controllerContent);

        foreach ($matches as $match) {
            $httpMethod = strtoupper($match[1]);
            $path = '/'.$match[2];
            $action = $match[3];

            $endpoints[] = [
                'method' => $httpMethod,
                'path' => $path,
                'action' => $action,
                'type' => in_array($action, self::STANDARD_ACTIONS, true) ? 'standard' : 'custom',
                'description' => $descriptions[$action] ?? null,
            ];
        }

        return $endpoints;
    }

    /**
     * Scan a controller file at the given path and return Route:: definitions.
     *
     * @return array<int, array{method: string, path: string, action: string, type: string, description: ?string}>
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

    /**
     * Extract the first line of PHPDoc blocks above public methods.
     *
     * @return array<string, string>
     */
    private function extractMethodDescriptions(string $content): array
    {
        $descriptions = [];

        preg_match_all(
            '/\/\*\*\s*\n\s*\*\s*(.+?)\n.*?\*\/\s*\n\s*public function (\w+)\s*\(/s',
            $content,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $descriptions[$match[2]] = trim($match[1]);
        }

        return $descriptions;
    }
}
