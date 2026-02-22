<?php

namespace SchemaCraft\Generator\Api;

use Illuminate\Support\Str;

/**
 * Inserts a new action into an existing controller and service.
 *
 * Adds the route, controller method, and service method for a custom action.
 */
class ApiFileWriter
{
    /**
     * Add a route line to the controller's static apiRoutes() method.
     */
    public function addRoute(
        string $controllerContent,
        string $httpMethod,
        string $routePrefix,
        string $actionName,
        string $controllerClass,
        string $routeParam,
    ): string {
        $actionSlug = Str::snake($actionName, '-');
        $routeLine = "        Route::{$httpMethod}('{$routePrefix}/{".$routeParam."}/{$actionSlug}', [{$controllerClass}::class, '{$actionName}']);";

        // Find the last route line in apiRoutes() and insert after it
        $pattern = '/^(        Route::.+;\s*)$/m';
        preg_match_all($pattern, $controllerContent, $matches, PREG_OFFSET_CAPTURE);

        if (empty($matches[0])) {
            return $controllerContent;
        }

        $lastMatch = end($matches[0]);
        $insertPos = $lastMatch[1] + strlen($lastMatch[0]);

        return substr($controllerContent, 0, $insertPos)
            ."\n".$routeLine
            .substr($controllerContent, $insertPos);
    }

    /**
     * Add a controller method for the new action.
     */
    public function addControllerMethod(
        string $controllerContent,
        string $actionName,
        string $modelClass,
        string $modelVariable,
        string $requestClass,
    ): string {
        $method = <<<PHP

    public function {$actionName}({$requestClass} \$request, {$modelClass} \${$modelVariable}): \Illuminate\Http\JsonResponse
    {
        \${$modelVariable}->Service()->{$actionName}(
            ...\$request->validated()
        );

        return response()->json(null, 200);
    }
PHP;

        // Insert before the final closing brace
        $lastBrace = strrpos($controllerContent, '}');

        return substr($controllerContent, 0, $lastBrace).$method."\n}\n";
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
     * Add a service method stub for the new action.
     */
    public function addServiceMethod(
        string $serviceContent,
        string $actionName,
        string $modelClass,
        string $modelVariable,
    ): string {
        $method = <<<PHP

    public function {$actionName}(): {$modelClass}
    {
        // TODO: Implement {$actionName} logic

        \$this->{$modelVariable}->save();

        return \$this->{$modelVariable};
    }
PHP;

        // Insert before the final closing brace
        $lastBrace = strrpos($serviceContent, '}');

        return substr($serviceContent, 0, $lastBrace).$method."\n}\n";
    }
}
