<?php

namespace SchemaCraft\Generator\Sdk;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use ReflectionMethod;
use ReflectionNamedType;
use SchemaCraft\Action;
use SchemaCraft\Scanner\ActionScanner;

/**
 * Discovers API routes at runtime via Route::getRoutes() and maps them
 * to schemas using controller class names and action route defaults.
 *
 * Replaces the regex-based RouteDefinitionScanner with a reliable,
 * runtime approach that catches all routes regardless of registration pattern.
 */
class RuntimeRouteScanner
{
    private const STANDARD_ACTIONS = [
        'getCollection',
        'get',
        'create',
        'update',
        'delete',
    ];

    /**
     * Get all API routes grouped by schema class.
     *
     * @param  string  $controllerNamespace  The namespace where API controllers live
     * @param  string  $schemaNamespace  The namespace where schema classes live
     * @param  string  $apiMiddleware  Middleware to filter routes by (e.g., 'api')
     * @return array<string, array{endpoints: array, unassigned: array}>
     */
    public function scanAll(
        string $controllerNamespace,
        string $schemaNamespace,
        string $apiMiddleware = 'api',
        ?string $routePrefix = null,
    ): array {
        $grouped = [];
        $unassigned = [];

        foreach (RouteFacade::getRoutes() as $route) {
            if (! $this->matchesFilters($route, $apiMiddleware, $routePrefix)) {
                continue;
            }

            // Skip SchemaCraft visualizer routes
            if (str_starts_with($route->uri(), '_schema-craft')) {
                continue;
            }

            $endpoint = $this->buildEndpoint($route, $controllerNamespace, $schemaNamespace);

            if ($endpoint['schema'] !== null) {
                $grouped[$endpoint['schema']][] = $endpoint;
            } else {
                $unassigned[] = $endpoint;
            }
        }

        return [
            'schemas' => $grouped,
            'unassigned' => $unassigned,
        ];
    }

    /**
     * Get API routes for a specific schema class.
     *
     * @return array<int, array{method: string, path: string, action: string, type: string, description: ?string, source: string, actionClass: ?string, rules: ?array}>
     */
    public function scanForSchema(
        string $schemaClass,
        string $controllerNamespace,
        string $schemaNamespace,
        string $apiMiddleware = 'api',
        ?string $routePrefix = null,
    ): array {
        $endpoints = [];

        foreach (RouteFacade::getRoutes() as $route) {
            if (! $this->matchesFilters($route, $apiMiddleware, $routePrefix)) {
                continue;
            }

            if (str_starts_with($route->uri(), '_schema-craft')) {
                continue;
            }

            $endpoint = $this->buildEndpoint($route, $controllerNamespace, $schemaNamespace);

            if ($endpoint['schema'] === $schemaClass) {
                $endpoints[] = $endpoint;
            }
        }

        return $endpoints;
    }

    /**
     * Count routes per schema class.
     *
     * @return array<string, int>
     */
    public function countBySchema(
        string $controllerNamespace,
        string $schemaNamespace,
        string $apiMiddleware = 'api',
        ?string $routePrefix = null,
    ): array {
        $counts = [];

        foreach (RouteFacade::getRoutes() as $route) {
            if (! $this->matchesFilters($route, $apiMiddleware, $routePrefix)) {
                continue;
            }

            if (str_starts_with($route->uri(), '_schema-craft')) {
                continue;
            }

            $endpoint = $this->buildEndpoint($route, $controllerNamespace, $schemaNamespace);

            if ($endpoint['schema'] !== null) {
                $counts[$endpoint['schema']] = ($counts[$endpoint['schema']] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Build a structured endpoint array from a route.
     *
     * @return array{method: string, path: string, action: string, type: string, description: ?string, source: string, schema: ?string, actionClass: ?string, rules: ?array, formRequest: ?string}
     */
    private function buildEndpoint(
        Route $route,
        string $controllerNamespace,
        string $schemaNamespace,
    ): array {
        $methods = array_diff($route->methods(), ['HEAD']);
        $method = implode('|', $methods);
        $path = '/'.$route->uri();
        $controllerClass = $route->getControllerClass();
        $controllerMethod = $route->getActionMethod();

        $schema = null;
        $source = 'unknown';
        $action = $controllerMethod !== 'Closure' ? $controllerMethod : null;
        $description = null;
        $rules = null;
        $formRequest = null;
        $actionClass = null;
        $actionParameters = null;

        // Check for action endpoint (route defaults)
        $schemaCraftAction = $route->defaults['_schema_craft_action'] ?? null;
        $schemaCraftSchema = $route->defaults['_schema_craft_schema'] ?? null;

        if ($schemaCraftSchema !== null && class_exists($schemaCraftSchema)) {
            $schema = $schemaCraftSchema;
            $source = 'action';
            $actionClass = $schemaCraftAction;

            // Get rich data from the action class
            if ($schemaCraftAction !== null && class_exists($schemaCraftAction)) {
                $scanner = new ActionScanner($schemaCraftAction);
                $definition = $scanner->scan();
                $meta = $schemaCraftAction::meta();

                // SDK method name: explicit sdkMethod > camelCase route slug > serviceMethod fallback
                $action = $meta?->sdkMethod ?? ($meta ? \Illuminate\Support\Str::camel($meta->route) : $definition->serviceMethod);
                $description = $definition->description;

                // Serialize parameters for SDK generation — avoids PHP object serialization issues
                $actionParameters = array_map(fn ($p) => [
                    'name' => $p->name,
                    'type' => $p->type,
                    'nullable' => $p->nullable,
                    'hasDefault' => $p->hasDefault,
                    'default' => is_scalar($p->default) ? $p->default : null,
                    'isModel' => $p->isModel,
                    'foreignKeyColumn' => $p->foreignKeyColumn,
                    'columnName' => $p->columnName,
                    'isNestedRelationship' => $p->isNestedRelationship,
                    'morphPairName' => $p->morphPairName,
                ], $definition->parameters);
            }
        } elseif ($controllerClass !== null && str_starts_with($controllerClass, $controllerNamespace)) {
            // Controller route — derive schema from controller name
            $source = 'controller';
            $modelName = str_replace('Controller', '', class_basename($controllerClass));
            $schemaClass = $schemaNamespace.'\\'.$modelName.'Schema';

            if (class_exists($schemaClass)) {
                $schema = $schemaClass;
            }

            // Get PHPDoc description and FormRequest rules via reflection
            if ($controllerMethod !== 'Closure') {
                $description = $this->extractDescription($controllerClass, $controllerMethod);
                $formRequest = $this->extractFormRequest($controllerClass, $controllerMethod);

                if ($formRequest !== null) {
                    $rules = $this->extractRules($formRequest);
                }
            }
        } elseif ($controllerClass !== null) {
            // Controller outside the API namespace — still try to map
            $source = 'controller';
        }

        return [
            'method' => $method,
            'path' => $path,
            'action' => $action ?? basename($path),
            'type' => in_array($action, self::STANDARD_ACTIONS, true) ? 'standard' : 'custom',
            'description' => $description,
            'source' => $source,
            'schema' => $schema,
            'actionClass' => $actionClass,
            'rules' => $rules,
            'formRequest' => $formRequest,
            'actionParameters' => $actionParameters,
        ];
    }

    /**
     * Check if a route matches the configured filters (middleware and/or prefix).
     *
     * When a route prefix is provided, it is used as the primary filter.
     * When only middleware is provided, it filters by middleware alone.
     */
    private function matchesFilters(Route $route, string $middleware, ?string $routePrefix): bool
    {
        // When prefix is provided, use it as the primary filter
        if ($routePrefix !== null && $routePrefix !== '') {
            $normalizedPrefix = ltrim($routePrefix, '/');

            return str_starts_with($route->uri(), $normalizedPrefix);
        }

        // Fall back to middleware filtering
        if ($middleware !== '') {
            return in_array($middleware, $route->gatherMiddleware(), true);
        }

        return true;
    }

    /**
     * Extract PHPDoc description from a controller method.
     */
    private function extractDescription(string $controllerClass, string $method): ?string
    {
        try {
            $ref = new ReflectionMethod($controllerClass, $method);
            $doc = $ref->getDocComment();

            if (! $doc) {
                return null;
            }

            // Extract first non-tag line from PHPDoc
            if (preg_match('/\*\s+([^@\n*][^\n]*)/', $doc, $m)) {
                return trim($m[1]);
            }
        } catch (\Throwable) {
            // Method doesn't exist or reflection failed
        }

        return null;
    }

    /**
     * Extract a FormRequest class from a controller method's parameters.
     *
     * @return class-string<FormRequest>|null
     */
    private function extractFormRequest(string $controllerClass, string $method): ?string
    {
        try {
            $ref = new ReflectionMethod($controllerClass, $method);

            foreach ($ref->getParameters() as $param) {
                $type = $param->getType();

                if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }

                $typeName = $type->getName();

                if (class_exists($typeName) && is_subclass_of($typeName, FormRequest::class)) {
                    return $typeName;
                }
            }
        } catch (\Throwable) {
            // Reflection failed
        }

        return null;
    }

    /**
     * Extract validation rules from a FormRequest class.
     *
     * @return array<string, mixed>|null
     */
    private function extractRules(string $formRequestClass): ?array
    {
        try {
            $request = new $formRequestClass;

            return $request->rules();
        } catch (\Throwable) {
            return null;
        }
    }
}
