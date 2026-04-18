<?php

namespace SchemaCraft\Generator\Sdk;

use Illuminate\Support\Str;
use SchemaCraft\Scanner\TableDefinition;

/**
 * Generates a Resource class for the SDK.
 *
 * When route endpoints are provided (from RuntimeRouteScanner), one method is generated
 * per actual registered route using the real action name and path — no convention mapping.
 *
 * Falls back to the legacy CRUD scaffold when no endpoint data is available
 * (e.g., controller-file-only discovery without a running application).
 *
 * Generated code is PHP 7.4 compatible.
 */
class SdkResourceGenerator
{
    /**
     * Generate the resource class PHP code.
     *
     * @param  SdkCustomAction[]  $customActions  Fallback when $endpoints is empty
     * @param  array<int, array{method: string, path: string, action: string, type: string, rules: ?array}>  $endpoints
     */
    public function generate(
        TableDefinition $table,
        string $resourceNamespace,
        string $dataNamespace,
        string $modelName,
        array $customActions = [],
        array $endpoints = [],
    ): string {
        $resourceClassName = $modelName.'Resource';
        $dataClassName = $modelName.'Data';

        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = "namespace {$resourceNamespace};";
        $lines[] = '';
        $lines[] = "use {$dataNamespace}\\{$dataClassName};";
        $lines[] = '';
        $lines[] = "class {$resourceClassName}";
        $lines[] = '{';
        $lines[] = '    /** @var SdkConnector */';
        $lines[] = '    private $connector;';
        $lines[] = '';
        $lines[] = '    /**';
        $lines[] = '     * @param SdkConnector $connector';
        $lines[] = '     */';
        $lines[] = '    public function __construct($connector)';
        $lines[] = '    {';
        $lines[] = '        $this->connector = $connector;';
        $lines[] = '    }';

        if (! empty($endpoints)) {
            foreach ($endpoints as $endpoint) {
                $lines[] = '';
                $lines = array_merge($lines, $this->buildEndpointMethod($endpoint, $dataClassName));
            }
        } else {
            // Fallback: legacy custom actions only (no route data available)
            foreach ($customActions as $action) {
                $lines[] = '';
                $actionSlug = Str::snake($action->name, '-');
                $httpMethod = $action->httpMethod;
                $lines[] = '    /**';
                $lines[] = '     * @param int|string $id';
                $lines[] = '     * @return void';
                $lines[] = '     */';
                $lines[] = "    public function {$action->name}(\$id)";
                $lines[] = '    {';
                $lines[] = "        \$this->connector->{$httpMethod}(\"{$actionSlug}/{\$id}\", []);";
                $lines[] = '    }';
            }
        }

        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Build the method lines for a single scanned endpoint.
     *
     * Uses the actual action name and path from the route — no renaming.
     * Path params (e.g. {id}) become method parameters.
     * Validation rules (when available) drive the request body for POST/PUT/PATCH.
     * GET returns typed Data; everything else returns void.
     *
     * @param  array{method: string, path: string, action: string, type: string, rules: ?array}  $endpoint
     * @return string[]
     */
    private function buildEndpointMethod(array $endpoint, string $dataClassName): array
    {
        $methodName = $endpoint['action'];
        $httpMethods = array_map('strtoupper', explode('|', $endpoint['method']));
        $httpMethod = strtolower($httpMethods[0]);
        $path = ltrim($endpoint['path'], '/');
        $rules = $endpoint['rules'] ?? null;

        $pathParams = $this->extractPathParams($path);
        $bodyParams = $this->resolveBodyParams($httpMethod, $rules);

        $allParams = array_merge(
            array_map(fn ($p) => "\${$p}", $pathParams),
            array_map(fn ($p) => $p['optional'] ? "\${$p['name']} = null" : "\${$p['name']}", $bodyParams),
        );

        $paramList = $this->formatParamList($allParams);
        $isGet = $httpMethod === 'get';
        $hasPathParams = ! empty($pathParams);
        $connectorPath = $this->buildConnectorPath($path, $pathParams);

        $lines = [];

        // PHPDoc
        $lines[] = '    /**';
        foreach ($pathParams as $param) {
            $lines[] = "     * @param mixed \${$param}";
        }
        foreach ($bodyParams as $param) {
            $nullSuffix = $param['optional'] ? '|null' : '';
            $lines[] = "     * @param {$param['type']}{$nullSuffix} \${$param['name']}";
        }
        if ($isGet) {
            $lines[] = $hasPathParams
                ? "     * @return {$dataClassName}"
                : "     * @return {$dataClassName}[]";
        } else {
            $lines[] = '     * @return void';
        }
        $lines[] = '     */';

        $lines[] = "    public function {$methodName}({$paramList})";
        $lines[] = '    {';

        if ($isGet) {
            $lines[] = "        \$response = \$this->connector->get({$connectorPath});";
            $lines[] = '';
            if ($hasPathParams) {
                $lines[] = "        return {$dataClassName}::fromArray(\$response['data']);";
            } else {
                $lines[] = '        return array_map(function (array $item) {';
                $lines[] = "            return {$dataClassName}::fromArray(\$item);";
                $lines[] = '        }, $response[\'data\']);';
            }
        } elseif (! empty($bodyParams)) {
            $bodyEntries = array_map(
                fn ($p) => "            '{$p['key']}' => \${$p['name']},",
                $bodyParams,
            );
            $lines[] = "        \$this->connector->{$httpMethod}({$connectorPath}, [";
            foreach ($bodyEntries as $entry) {
                $lines[] = $entry;
            }
            $lines[] = '        ]);';
        } else {
            $lines[] = "        \$this->connector->{$httpMethod}({$connectorPath}, []);";
        }

        $lines[] = '    }';

        return $lines;
    }

    /**
     * Extract path parameter names from a route path (e.g. "users/{user}/posts/{post}").
     *
     * @return string[]
     */
    private function extractPathParams(string $path): array
    {
        preg_match_all('/\{(\w+)\}/', $path, $matches);

        return $matches[1];
    }

    /**
     * Convert a route path to a PHP connector call argument.
     *
     * Single-quoted string when no params; double-quoted with interpolation when params present.
     *
     * @param  string[]  $pathParams
     */
    private function buildConnectorPath(string $path, array $pathParams): string
    {
        if (empty($pathParams)) {
            return "'{$path}'";
        }

        $interpolated = preg_replace_callback('/\{(\w+)\}/', fn ($m) => '{'.'$'.$m[1].'}', $path);

        return "\"{$interpolated}\"";
    }

    /**
     * Resolve body params from validation rules for POST/PUT/PATCH methods.
     *
     * Each entry: ['name' => camelCase, 'key' => snake_key, 'type' => phptype, 'optional' => bool]
     *
     * @param  array<string, mixed>|null  $rules
     * @return array<int, array{name: string, key: string, type: string, optional: bool}>
     */
    private function resolveBodyParams(string $httpMethod, ?array $rules): array
    {
        if (! in_array($httpMethod, ['post', 'put', 'patch']) || empty($rules)) {
            return [];
        }

        $params = [];

        foreach ($rules as $field => $fieldRules) {
            $ruleString = is_array($fieldRules) ? implode('|', $fieldRules) : (string) $fieldRules;
            $isRequired = str_contains($ruleString, 'required');
            $phpType = $this->ruleToPhpType($ruleString);

            $params[] = [
                'name' => Str::camel($field),
                'key' => $field,
                'type' => $phpType,
                'optional' => ! $isRequired,
            ];
        }

        return $params;
    }

    /**
     * Derive a simple PHP type hint from a validation rule string.
     */
    private function ruleToPhpType(string $rules): string
    {
        if (str_contains($rules, 'integer') || str_contains($rules, 'numeric')) {
            return 'int';
        }
        if (str_contains($rules, 'boolean')) {
            return 'bool';
        }
        if (str_contains($rules, 'array')) {
            return 'array';
        }

        return 'string';
    }

    /**
     * Format a parameter list, expanding to multi-line when there are more than 3 params.
     *
     * @param  string[]  $params
     */
    private function formatParamList(array $params): string
    {
        if (count($params) <= 3) {
            return implode(', ', $params);
        }

        return "\n        ".implode(",\n        ", $params)."\n    ";
    }
}
