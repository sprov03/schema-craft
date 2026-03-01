<?php

namespace SchemaCraft\Generator\Api;

/**
 * Inserts a new action into an existing controller and service.
 *
 * Adds the route, controller method, and service method for a custom action.
 * All content is pre-rendered from stubs by ApiCodeGenerator.
 */
class ApiFileWriter
{
    /**
     * Add a pre-rendered route line to the controller's static apiRoutes() method.
     */
    public function addRoute(string $controllerContent, string $renderedRouteLine): string
    {
        $routeLine = trim($renderedRouteLine);

        // Find the last route line in apiRoutes() and insert after it
        $pattern = '/^(        Route::.+;\s*)$/m';
        preg_match_all($pattern, $controllerContent, $matches, PREG_OFFSET_CAPTURE);

        if (empty($matches[0])) {
            return $controllerContent;
        }

        $lastMatch = end($matches[0]);
        $insertPos = $lastMatch[1] + strlen($lastMatch[0]);

        return substr($controllerContent, 0, $insertPos)
            ."\n        ".$routeLine
            .substr($controllerContent, $insertPos);
    }

    /**
     * Add a pre-rendered controller method for the new action.
     */
    public function addControllerMethod(string $controllerContent, string $renderedMethod): string
    {
        // Insert before the final closing brace
        $lastBrace = strrpos($controllerContent, '}');

        return substr($controllerContent, 0, $lastBrace).$renderedMethod."\n}\n";
    }

    /**
     * Add an import statement to the file if not already present.
     */
    public function addImport(string $content, string $fqcn): string
    {
        $useStatement = "use {$fqcn};";

        if (str_contains($content, $useStatement)) {
            return $content;
        }

        // Find the last `use` statement and insert after it
        $pattern = '/^use .+;$/m';
        preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE);

        if (empty($matches[0])) {
            return $content;
        }

        $lastMatch = end($matches[0]);
        $insertPos = $lastMatch[1] + strlen($lastMatch[0]);

        return substr($content, 0, $insertPos)
            ."\n".$useStatement
            .substr($content, $insertPos);
    }

    /**
     * Add a pre-rendered service method for the new action.
     */
    public function addServiceMethod(string $serviceContent, string $renderedMethod): string
    {
        // Insert before the final closing brace
        $lastBrace = strrpos($serviceContent, '}');

        return substr($serviceContent, 0, $lastBrace).$renderedMethod."\n}\n";
    }

    /**
     * Add a pre-rendered test method for the new action.
     */
    public function addTestMethod(string $testContent, string $renderedMethod): string
    {
        // Insert before the final closing brace
        $lastBrace = strrpos($testContent, '}');

        return substr($testContent, 0, $lastBrace).$renderedMethod."\n}\n";
    }

    /**
     * Register a controller's apiRoutes() call in the route file.
     */
    public function addControllerRegistration(
        string $routeFileContent,
        string $controllerFqcn,
        string $controllerClass,
    ): string {
        $registration = "\\{$controllerFqcn}::apiRoutes();";

        // Already registered?
        if (str_contains($routeFileContent, $controllerClass.'::apiRoutes()')) {
            return $routeFileContent;
        }

        // Append to the end of the file
        return rtrim($routeFileContent)."\n\n".$registration."\n";
    }
}
