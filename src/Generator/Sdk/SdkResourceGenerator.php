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
        $pluralSlug = Str::plural(Str::kebab($modelName));

        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = "namespace {$resourceNamespace};";
        $lines[] = '';
        $lines[] = "use {$dataNamespace}\\{$dataClassName};";
        $lines[] = 'use Illuminate\\Support\\Collection;';
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
            // Fallback: generate standard CRUD scaffold from table definition
            $bodyParams = $this->resolveBodyParams($table);

            $lines = array_merge($lines, $this->buildListMethod($pluralSlug, $dataClassName));
            $lines = array_merge($lines, $this->buildGetMethod($pluralSlug, $dataClassName));
            $lines = array_merge($lines, $this->buildCreateMethod($pluralSlug, $bodyParams));
            $lines = array_merge($lines, $this->buildUpdateMethod($pluralSlug, $bodyParams));
            $lines = array_merge($lines, $this->buildDeleteMethod($pluralSlug));

            foreach ($customActions as $action) {
                $lines[] = '';
                $actionSlug = Str::kebab($action->name);
                $httpMethod = $action->httpMethod;
                $lines[] = '    /**';
                $lines[] = '     * @param int|string $id';
                $lines[] = '     * @return void';
                $lines[] = '     */';
                $lines[] = "    public function {$action->name}(\$id)";
                $lines[] = '    {';
                $lines[] = "        \$this->connector->{$httpMethod}(\"{$pluralSlug}/{\$id}/{$actionSlug}\", []);";
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
     * Every {placeholder} in the path becomes a leading method param named after it
     * (e.g. catalog/{catalog} → $catalog), so route binding works for non-{id} params too.
     * Body params come from the canonical $endpoint['parameters'] produced by enrichEndpoint().
     *
     * Return type is derived from the endpoint's responseModelName when present:
     * - GET with a path {param} → DataClass (single)
     * - GET without a path {param} → Collection<int, DataClass>
     * - Any other method with declared resource → DataClass
     * - No declared resource → void
     *
     * @param  array{method: string, path: string, action: string, parameters: ?array, responseModelName?: string}  $endpoint
     * @return string[]
     */
    private function buildEndpointMethod(array $endpoint, string $dataClassName): array
    {
        $methodName = $endpoint['action'];
        $httpMethods = array_map('strtoupper', explode('|', $endpoint['method']));
        $httpMethod = strtolower($httpMethods[0]);
        $path = ltrim($endpoint['path'], '/');

        // Use the per-endpoint DTO class when the resource has been declared; fall back to schema DTO
        $endpointDataClass = isset($endpoint['responseModelName'])
            ? $endpoint['responseModelName'].'Data'
            : $dataClassName;

        $hasResponse = isset($endpoint['responseModelName']);
        // Manual JsonResource: known to return a body but its shape is opaque (no DTO generated).
        // We surface the raw decoded data array rather than referencing a phantom XManualData class.
        $hasManualResponse = ! $hasResponse && isset($endpoint['responseManualResource']);
        $parameters = $endpoint['parameters'] ?? $endpoint['actionParameters'] ?? null;

        // Bind every {placeholder} in the path to a leading method param named after it
        // (e.g. catalog/{catalog} -> $catalog). buildConnectorPath interpolates {$placeholder},
        // so the names must line up or we'd emit an undefined variable (invalid PHP).
        preg_match_all('/\{(\w+)\}/', $path, $pathParamMatches);
        $pathParams = $pathParamMatches[1];
        $isGet = $httpMethod === 'get';
        $bodyParams = $this->resolveBodyParamsFromAction($httpMethod, $parameters);
        $connectorPath = $this->buildConnectorPath($path);

        $allParams = [];
        foreach ($pathParams as $pathParam) {
            $allParams[] = "\${$pathParam}";
        }
        foreach ($bodyParams as $param) {
            $allParams[] = $param['optional'] ? "\${$param['name']} = null" : "\${$param['name']}";
        }

        $paramList = $this->formatParamList($allParams);

        $lines = [];

        // PHPDoc
        $lines[] = '    /**';
        foreach ($pathParams as $pathParam) {
            $lines[] = "     * @param int|string \${$pathParam}";
        }
        foreach ($bodyParams as $param) {
            $nullSuffix = $param['optional'] ? '|null' : '';
            $lines[] = "     * @param {$param['type']}{$nullSuffix} \${$param['name']}";
        }

        if ($isGet && $hasResponse) {
            $lines[] = str_contains($path, '{')
                ? "     * @return {$endpointDataClass}"
                : "     * @return Collection<int, {$endpointDataClass}>";
        } elseif ($hasResponse) {
            $lines[] = "     * @return {$endpointDataClass}";
        } elseif ($hasManualResponse) {
            $lines[] = '     * @return array';
        } else {
            $lines[] = '     * @return void';
        }
        $lines[] = '     */';

        $lines[] = "    public function {$methodName}({$paramList})";
        $lines[] = '    {';

        if ($isGet && $hasResponse) {
            $lines[] = "        \$response = \$this->connector->get({$connectorPath});";
            $lines[] = '';
            if (str_contains($path, '{')) {
                $lines[] = "        return {$endpointDataClass}::fromArray(\$response['data']);";
            } else {
                $lines[] = "        return collect(\$response['data'])->map(function (array \$item) {";
                $lines[] = "            return {$endpointDataClass}::fromArray(\$item);";
                $lines[] = '        });';
            }
        } elseif ($hasResponse && ! empty($bodyParams)) {
            $lines[] = "        \$response = \$this->connector->{$httpMethod}({$connectorPath}, [";
            foreach ($bodyParams as $param) {
                $lines[] = "            '{$param['key']}' => \${$param['name']},";
            }
            $lines[] = '        ]);';
            $lines[] = '';
            $lines[] = "        return {$endpointDataClass}::fromArray(\$response['data']);";
        } elseif ($hasResponse) {
            $lines[] = "        \$response = \$this->connector->{$httpMethod}({$connectorPath}, []);";
            $lines[] = '';
            $lines[] = "        return {$endpointDataClass}::fromArray(\$response['data']);";
        } elseif ($hasManualResponse) {
            // Opaque resource — return the decoded data array verbatim, no DTO hydration.
            if ($isGet) {
                $lines[] = "        \$response = \$this->connector->get({$connectorPath});";
            } elseif (! empty($bodyParams)) {
                $lines[] = "        \$response = \$this->connector->{$httpMethod}({$connectorPath}, [";
                foreach ($bodyParams as $param) {
                    $lines[] = "            '{$param['key']}' => \${$param['name']},";
                }
                $lines[] = '        ]);';
            } else {
                $lines[] = "        \$response = \$this->connector->{$httpMethod}({$connectorPath}, []);";
            }
            $lines[] = '';
            $lines[] = "        return \$response['data'];";
        } elseif (! empty($bodyParams)) {
            $lines[] = "        \$this->connector->{$httpMethod}({$connectorPath}, [";
            foreach ($bodyParams as $param) {
                $lines[] = "            '{$param['key']}' => \${$param['name']},";
            }
            $lines[] = '        ]);';
        } else {
            $lines[] = "        \$this->connector->{$httpMethod}({$connectorPath}, []);";
        }

        $lines[] = '    }';

        return $lines;
    }

    /**
     * Derive SDK body params from serialized ActionDefinition parameters.
     *
     * Uses the action's typed properties directly — independent of schema columns.
     * Model params become their FK column (e.g. user → user_id as int).
     * Scalar params use their column name and PHP type.
     * Nested relationships are skipped (too complex for a flat SDK signature).
     *
     * @param  array<int, array{name: string, type: string, isModel: bool, foreignKeyColumn: ?string, columnName: ?string, nullable: bool, hasDefault: bool, isNestedRelationship: bool, morphPairName: ?string}>|null  $parameters
     * @return array<int, array{name: string, key: string, type: string, optional: bool}>
     */
    private function resolveBodyParamsFromAction(string $httpMethod, ?array $parameters): array
    {
        if (! in_array($httpMethod, ['post', 'put', 'patch']) || empty($parameters)) {
            return [];
        }

        $params = [];

        foreach ($parameters as $param) {
            if ($param['isNestedRelationship']) {
                // Nested relationship — represent as array in the SDK method signature.
                // Param name == wire key (snake_case) so method args and body keys match verbatim.
                $key = $param['columnName'] ?? Str::snake($param['name']);
                $params[] = [
                    'name' => $key,
                    'key' => $key,
                    'type' => 'array',
                    'optional' => $param['nullable'] || $param['hasDefault'],
                ];

                continue;
            }

            if ($param['isModel']) {
                $key = $param['foreignKeyColumn'] ?? (Str::snake($param['name']).'_id');
                $params[] = [
                    'name' => $key,
                    'key' => $key,
                    'type' => 'int',
                    'optional' => $param['nullable'] || $param['hasDefault'],
                ];
            } else {
                $key = $param['columnName'] ?? Str::snake($param['name']);
                $params[] = [
                    'name' => $key,
                    'key' => $key,
                    'type' => $this->phpTypeFromString($param['type']),
                    'optional' => $param['nullable'] || $param['hasDefault'],
                ];
            }
        }

        return $this->sortRequiredBeforeOptional($params);
    }

    /**
     * Resolve body params from table column definitions for the fallback CRUD scaffold.
     *
     * Excludes primary keys, auto-increment columns, and timestamp columns.
     *
     * @return array<int, array{name: string, key: string, type: string, optional: bool}>
     */
    private function resolveBodyParams(TableDefinition $table): array
    {
        $timestampColumns = $table->hasTimestamps ? ['created_at', 'updated_at', 'deleted_at'] : [];

        // Collect column names generated by MorphTo relationships so they can be skipped
        $morphColumns = [];
        foreach ($table->relationships as $rel) {
            if ($rel->type === 'morphTo' && $rel->morphName !== null) {
                $morphColumns[] = $rel->morphName.'_type';
                $morphColumns[] = $rel->morphName.'_id';
            }
        }

        $params = [];

        foreach ($table->columns as $column) {
            if ($column->primary || $column->autoIncrement) {
                continue;
            }

            if (in_array($column->name, $timestampColumns, true)) {
                continue;
            }

            if (in_array($column->name, $morphColumns, true)) {
                continue;
            }

            $params[] = [
                // Param name == column/wire key (snake_case); no casing translation.
                'name' => $column->name,
                'key' => $column->name,
                'type' => $this->columnTypeToPhpType($column->columnType),
                'optional' => $column->nullable,
            ];
        }

        return $this->sortRequiredBeforeOptional($params);
    }

    /**
     * Stable-partition body params so required ones precede optional ones.
     *
     * PHP forbids a required param after an optional ($x = null) one, but FormRequest
     * rules (and column order) can interleave them. We keep relative order within each
     * group; the body-key mapping uses each param's explicit 'key', so reordering the
     * signature does not change the request payload.
     *
     * @param  array<int, array{name: string, key: string, type: string, optional: bool}>  $params
     * @return array<int, array{name: string, key: string, type: string, optional: bool}>
     */
    private function sortRequiredBeforeOptional(array $params): array
    {
        $required = array_filter($params, fn ($p) => $p['optional'] === false);
        $optional = array_filter($params, fn ($p) => $p['optional'] === true);

        return array_merge(array_values($required), array_values($optional));
    }

    /**
     * @return string[]
     */
    private function buildListMethod(string $pluralSlug, string $dataClassName): array
    {
        return [
            '',
            '    /**',
            "     * @return Collection<int, {$dataClassName}>",
            '     */',
            '    public function list()',
            '    {',
            "        \$response = \$this->connector->get('{$pluralSlug}');",
            '',
            "        return collect(\$response['data'])->map(function (array \$item) {",
            "            return {$dataClassName}::fromArray(\$item);",
            '        });',
            '    }',
        ];
    }

    /**
     * @return string[]
     */
    private function buildGetMethod(string $pluralSlug, string $dataClassName): array
    {
        return [
            '',
            '    /**',
            '     * @param int|string $id',
            "     * @return {$dataClassName}",
            '     */',
            '    public function get($id)',
            '    {',
            "        \$response = \$this->connector->get(\"{$pluralSlug}/{\$id}\");",
            '',
            "        return {$dataClassName}::fromArray(\$response['data']);",
            '    }',
        ];
    }

    /**
     * @param  array<int, array{name: string, key: string, type: string, optional: bool}>  $bodyParams
     * @return string[]
     */
    private function buildCreateMethod(string $pluralSlug, array $bodyParams): array
    {
        $allParams = [];
        foreach ($bodyParams as $param) {
            $allParams[] = $param['optional'] ? "\${$param['name']} = null" : "\${$param['name']}";
        }

        $paramList = $this->formatParamList($allParams);

        $lines = [];
        $lines[] = '';
        $lines[] = '    /**';
        foreach ($bodyParams as $param) {
            $nullSuffix = $param['optional'] ? '|null' : '';
            $lines[] = "     * @param {$param['type']}{$nullSuffix} \${$param['name']}";
        }
        $lines[] = '     * @return void';
        $lines[] = '     */';
        $lines[] = "    public function create({$paramList})";
        $lines[] = '    {';

        if (! empty($bodyParams)) {
            $lines[] = "        \$this->connector->post('{$pluralSlug}', [";
            foreach ($bodyParams as $param) {
                $lines[] = "            '{$param['key']}' => \${$param['name']},";
            }
            $lines[] = '        ]);';
        } else {
            $lines[] = "        \$this->connector->post('{$pluralSlug}', []);";
        }

        $lines[] = '    }';

        return $lines;
    }

    /**
     * @param  array<int, array{name: string, key: string, type: string, optional: bool}>  $bodyParams
     * @return string[]
     */
    private function buildUpdateMethod(string $pluralSlug, array $bodyParams): array
    {
        $allParams = ['$id'];
        foreach ($bodyParams as $param) {
            $allParams[] = $param['optional'] ? "\${$param['name']} = null" : "\${$param['name']}";
        }

        $paramList = $this->formatParamList($allParams);

        $lines = [];
        $lines[] = '';
        $lines[] = '    /**';
        $lines[] = '     * @param int|string $id';
        foreach ($bodyParams as $param) {
            $nullSuffix = $param['optional'] ? '|null' : '';
            $lines[] = "     * @param {$param['type']}{$nullSuffix} \${$param['name']}";
        }
        $lines[] = '     * @return void';
        $lines[] = '     */';
        $lines[] = "    public function update({$paramList})";
        $lines[] = '    {';

        if (! empty($bodyParams)) {
            $lines[] = "        \$this->connector->put(\"{$pluralSlug}/{\$id}\", [";
            foreach ($bodyParams as $param) {
                $lines[] = "            '{$param['key']}' => \${$param['name']},";
            }
            $lines[] = '        ]);';
        } else {
            $lines[] = "        \$this->connector->put(\"{$pluralSlug}/{\$id}\", []);";
        }

        $lines[] = '    }';

        return $lines;
    }

    /**
     * @return string[]
     */
    private function buildDeleteMethod(string $pluralSlug): array
    {
        return [
            '',
            '    /**',
            '     * @param int|string $id',
            '     * @return void',
            '     */',
            '    public function delete($id)',
            '    {',
            "        \$this->connector->delete(\"{$pluralSlug}/{\$id}\");",
            '    }',
        ];
    }

    /**
     * Convert a PHP type string to a PHPDoc-safe type hint.
     */
    private function phpTypeFromString(string $type): string
    {
        return match ($type) {
            'int', 'float', 'bool', 'array', 'string' => $type,
            default => 'mixed',
        };
    }

    /**
     * Map a database column type to a PHP type for PHPDoc.
     */
    private function columnTypeToPhpType(string $columnType): string
    {
        return match (true) {
            in_array($columnType, ['string', 'text', 'char', 'varchar', 'tinyText', 'mediumText', 'longText'], true) => 'string',
            in_array($columnType, ['integer', 'bigInteger', 'unsignedBigInteger', 'unsignedInteger', 'smallInteger', 'tinyInteger', 'mediumInteger', 'unsignedSmallInteger', 'unsignedTinyInteger', 'unsignedMediumInteger'], true) => 'int',
            in_array($columnType, ['decimal', 'float', 'double'], true) => 'float',
            $columnType === 'boolean' => 'bool',
            default => 'mixed',
        };
    }

    /**
     * Convert a route path to a PHP connector call argument.
     *
     * Single-quoted when no {params}; double-quoted with $interpolation when params present.
     */
    private function buildConnectorPath(string $path): string
    {
        if (! str_contains($path, '{')) {
            return "'{$path}'";
        }

        $interpolated = preg_replace_callback('/\{(\w+)\}/', fn ($m) => '{'.'$'.$m[1].'}', $path);

        return "\"{$interpolated}\"";
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
