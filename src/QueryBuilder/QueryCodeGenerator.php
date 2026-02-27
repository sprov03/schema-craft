<?php

namespace SchemaCraft\QueryBuilder;

use Illuminate\Support\Str;

/**
 * Generates PHP code (scopes, controller methods, form requests) from a QueryDefinition.
 *
 * Converts the JSON intermediary format into production-ready Eloquent query code
 * optimized for large datasets using ->when() for optional parameters.
 */
class QueryCodeGenerator
{
    /**
     * Generate a scope method for the model.
     */
    public function generateScope(QueryDefinition $query): string
    {
        $methodName = 'scope'.ucfirst($query->name);
        $params = $this->buildScopeParameters($query);
        $phpDoc = $this->buildScopePhpDoc($query);
        $body = $this->buildEloquentQuery($query, 'scope');

        $lines = [];
        $lines[] = $phpDoc;

        // Build parameter list — no trailing comma on last param
        $allParams = array_merge(['Builder $query'], $params);
        if (count($allParams) === 1) {
            $lines[] = "    public function {$methodName}({$allParams[0]}): Builder";
        } else {
            $lines[] = "    public function {$methodName}(";
            foreach ($allParams as $i => $p) {
                $isLast = $i === count($allParams) - 1;
                $lines[] = "        {$p}".($isLast ? '' : ',');
            }
            $lines[] = '    ): Builder';
        }

        $lines[] = '    {';
        $lines[] = '        return $query';
        $lines[] = $body.';';
        $lines[] = '    }';

        return implode("\n", $lines);
    }

    /**
     * Generate a controller method that uses the scope.
     */
    public function generateControllerMethod(QueryDefinition $query): string
    {
        $methodName = $query->name;
        $requestClass = ucfirst($query->name).'Request';
        $modelClass = $query->modelClass();
        $resourceClass = $modelClass.'Resource';
        $useScope = $query->wantsScope() && ! $query->wantsInlineController();

        $lines = [];
        $lines[] = $this->buildControllerPhpDoc($query);
        $lines[] = "    public function {$methodName}({$requestClass} \$request): \\Illuminate\\Http\\Resources\\Json\\AnonymousResourceCollection";
        $lines[] = '    {';

        if ($useScope) {
            $scopeMethod = $query->name;
            $scopeArgs = $this->buildScopeCallArguments($query);
            $lines[] = "        \$results = {$modelClass}::{$scopeMethod}({$scopeArgs})";
            $lines[] = '            ->paginate($request->per_page ?? 25);';
        } else {
            $body = $this->buildEloquentQuery($query, 'controller');
            $lines[] = "        \$results = {$modelClass}::query()";
            $lines[] = $body;
            $lines[] = '            ->paginate($request->per_page ?? 25);';
        }

        $lines[] = '';
        $lines[] = "        return {$resourceClass}::collection(\$results);";
        $lines[] = '    }';

        return implode("\n", $lines);
    }

    /**
     * Generate a FormRequest class for the query endpoint.
     */
    public function generateFormRequest(QueryDefinition $query, string $requestNamespace = 'App\\Http\\Requests'): string
    {
        $className = ucfirst($query->name).'Request';
        $rules = $this->buildValidationRules($query);

        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = "namespace {$requestNamespace};";
        $lines[] = '';
        $lines[] = 'use Illuminate\\Foundation\\Http\\FormRequest;';
        $lines[] = '';
        $lines[] = "class {$className} extends FormRequest";
        $lines[] = '{';
        $lines[] = '    public function authorize(): bool';
        $lines[] = '    {';
        $lines[] = '        return true;';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    /**';
        $lines[] = '     * @return array<string, mixed>';
        $lines[] = '     */';
        $lines[] = '    public function rules(): array';
        $lines[] = '    {';
        $lines[] = '        return [';

        foreach ($rules as $field => $rule) {
            $lines[] = "            '{$field}' => '{$rule}',";
        }

        $lines[] = "            'per_page' => 'sometimes|integer|min:1|max:100',";
        $lines[] = '        ];';
        $lines[] = '    }';
        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Generate the @method PHPDoc line for the model's class docblock.
     *
     * Uses the scope's call form (without 'scope' prefix) so IDE autocompletion works.
     * e.g., scopeWhereActive → @method static Builder whereActive()
     */
    public function generateScopePhpDocLine(QueryDefinition $query): string
    {
        $scopeCallName = $query->name;
        $params = [];

        foreach ($query->parameterizedConditions() as $condition) {
            $type = $condition->phpType();
            $paramName = $condition->parameterName();
            $params[] = "?{$type} \${$paramName} = null";
        }

        $paramStr = implode(', ', $params);

        return "@method static \\Illuminate\\Database\\Eloquent\\Builder {$scopeCallName}({$paramStr})";
    }

    /**
     * Generate a route registration line.
     */
    public function generateRouteRegistration(QueryDefinition $query, string $controllerClass, string $routePrefix = 'api'): string
    {
        $routeName = Str::snake($query->name, '-');
        $baseRoute = Str::snake(Str::pluralStudly($query->modelClass()), '-');

        return "        Route::get('{$baseRoute}/{$routeName}', [{$controllerClass}::class, '{$query->name}']);";
    }

    /**
     * Build the raw SQL preview string.
     */
    public function buildSqlPreview(QueryDefinition $query): string
    {
        $lines = [];
        $lines[] = 'SELECT '.($query->selects ? implode(', ', $query->selects) : "{$query->baseTable}.*");

        $lines[] = "FROM {$query->baseTable}";

        foreach ($query->joins as $join) {
            $joinType = strtoupper($join->type).' JOIN';
            $lines[] = "{$joinType} {$join->table} ON {$query->baseTable}.{$join->localColumn} = {$join->table}.{$join->foreignColumn}";
        }

        $whereParts = [];
        foreach ($query->conditions as $node) {
            $whereParts = array_merge($whereParts, $this->buildSqlNodeParts($node));
        }

        if (! empty($whereParts)) {
            $whereStr = $this->joinWhereParts($whereParts);
            $lines[] = 'WHERE '.$whereStr;
        }

        if (! empty($query->sorts)) {
            $sortParts = array_map(
                fn (SortDefinition $s) => "{$s->column} ".strtoupper($s->direction),
                $query->sorts,
            );
            $lines[] = 'ORDER BY '.implode(', ', $sortParts);
        }

        return implode("\n", $lines);
    }

    /**
     * Build SQL parts for a single tree node (recursive for groups).
     *
     * @return string[]
     */
    private function buildSqlNodeParts(ConditionNode $node): array
    {
        if ($node->type === 'condition') {
            return [$this->sqlConditionString($node)];
        }

        if ($node->type === 'group') {
            $parts = [];
            foreach ($node->children as $child) {
                $parts = array_merge($parts, $this->buildSqlNodeParts($child));
            }

            if (empty($parts)) {
                return [];
            }

            $connector = strtoupper($node->boolean);

            return [$connector.' ('.$this->joinWhereParts($parts).')'];
        }

        // whereHas
        return [$this->sqlWhereHasString($node)];
    }

    /**
     * Build a SQL condition string for a single condition.
     */
    private function sqlConditionString(ConditionNode $condition): string
    {
        $operator = $this->sqlOperator($condition->operator);

        if ($condition->isReference()) {
            return "{$condition->column} {$operator} {$condition->referenceColumn}";
        }

        if ($condition->isDynamic()) {
            $paramName = ':'.$condition->parameterName();
            if ($condition->isUnary()) {
                return "{$condition->column} {$operator}";
            }

            return "{$condition->column} {$operator} {$paramName}";
        }

        if ($condition->isUnary()) {
            return "{$condition->column} {$operator}";
        }

        return "{$condition->column} {$operator} '{$condition->value}'";
    }

    /**
     * Build a SQL preview string for a whereHas constraint.
     */
    private function sqlWhereHasString(ConditionNode $whereHas): string
    {
        $keyword = $whereHas->isNegated() ? 'NOT EXISTS' : 'EXISTS';

        $innerParts = [];
        foreach ($whereHas->children as $child) {
            if ($child->type === 'condition') {
                $innerParts[] = $this->sqlConditionString($child);
            }
        }
        $innerStr = ! empty($innerParts) ? ' AND '.$this->joinWhereParts($innerParts) : '';

        $suffix = '';
        if ($whereHas->hasCountConstraint()) {
            $suffix = " /* {$whereHas->countOperator} {$whereHas->countValue} */";
        }

        return "{$keyword} (SELECT * FROM {$whereHas->relationship}{$innerStr}){$suffix}";
    }

    /**
     * Join WHERE parts, using condition booleans as connectors.
     * The first part has no connector prefix.
     */
    private function joinWhereParts(array $parts): string
    {
        if (empty($parts)) {
            return '';
        }

        return implode(' AND ', $parts);
    }

    /**
     * Build the Eloquent query builder chain.
     */
    public function buildEloquentQuery(QueryDefinition $query, string $context = 'scope'): string
    {
        $lines = [];
        $indent = '            ';

        // SELECT
        if (! empty($query->selects)) {
            $selectArgs = implode(', ', array_map(fn (string $s) => "'{$s}'", $query->selects));
            $lines[] = "{$indent}->select({$selectArgs})";
        }

        // JOINs
        foreach ($query->joins as $join) {
            $method = $join->joinMethod();
            $lines[] = "{$indent}->{$method}('{$join->table}', '{$query->baseTable}.{$join->localColumn}', '=', '{$join->table}.{$join->foreignColumn}')";
        }

        // Conditions tree walk
        foreach ($query->conditions as $node) {
            $lines = array_merge($lines, $this->buildNodeLines($node, $indent, $context));
        }

        // Sorts
        foreach ($query->sorts as $sort) {
            $lines[] = "{$indent}->orderBy('{$sort->column}', '{$sort->direction}')";
        }

        return implode("\n", $lines);
    }

    /**
     * Dispatch a tree node to the correct builder.
     *
     * @return string[]
     */
    private function buildNodeLines(ConditionNode $node, string $indent, string $context): array
    {
        return match ($node->type) {
            'condition' => [$this->buildConditionLine($node, $indent, $context)],
            'group' => $this->buildGroupLines($node, $indent, $context),
            'whereHas' => $this->buildWhereHasLines($node, $indent, $context),
            default => [],
        };
    }

    /**
     * Build lines for a condition group (recursive for nested groups).
     *
     * @return string[]
     */
    private function buildGroupLines(ConditionNode $group, string $indent, string $context): array
    {
        $groupMethod = $group->boolean === 'or' ? 'orWhere' : 'where';
        $innerIndent = $indent.'    ';

        $innerLines = [];
        foreach ($group->children as $child) {
            $innerLines = array_merge($innerLines, $this->buildNodeLines($child, $innerIndent, $context));
        }

        if (empty($innerLines)) {
            return [];
        }

        // Collect dynamic parameters that need to be passed into the closure via use()
        $useVars = $this->collectDynamicParams($group->children, $context);
        $useClause = $useVars ? ' use ('.implode(', ', $useVars).')' : '';

        $lines = [];
        $lines[] = "{$indent}->{$groupMethod}(function (\$q){$useClause} {";
        foreach ($innerLines as $innerLine) {
            // Replace the builder variable from $query to $q inside the closure
            $line = str_replace(
                ["{$innerIndent}->", "{$innerIndent}    ->"],
                ["{$innerIndent}\$q->", "{$innerIndent}    \$q->"],
                $innerLine
            );
            // Each $q-> statement inside the closure needs a semicolon
            $lines[] = rtrim($line).';';
        }
        $lines[] = "{$indent}})";

        return $lines;
    }

    /**
     * Build lines for a whereHas relationship constraint.
     *
     * @return string[]
     */
    private function buildWhereHasLines(ConditionNode $whereHas, string $indent, string $context): array
    {
        $method = $whereHas->eloquentMethod();
        $rel = $whereHas->relationship;

        // Simple has() / doesntHave() — no closure needed
        if (in_array($method, ['has', 'orHas', 'doesntHave', 'orDoesntHave'])) {
            if ($whereHas->hasCountConstraint()) {
                return ["{$indent}->{$method}('{$rel}', '{$whereHas->countOperator}', {$whereHas->countValue})"];
            }

            return ["{$indent}->{$method}('{$rel}')"];
        }

        // whereHas / whereDoesntHave with closure — walk children recursively
        $innerIndent = $indent.'    ';
        $innerLines = [];

        foreach ($whereHas->children as $child) {
            $innerLines = array_merge($innerLines, $this->buildNodeLines($child, $innerIndent, $context));
        }

        // Collect dynamic parameters that need to be passed into the closure via use()
        $useVars = $this->collectDynamicParams($whereHas->children, $context);
        $useClause = $useVars ? ' use ('.implode(', ', $useVars).')' : '';

        $lines = [];
        $lines[] = "{$indent}->{$method}('{$rel}', function (\$q){$useClause} {";

        foreach ($innerLines as $innerLine) {
            $line = str_replace(
                ["{$innerIndent}->", "{$innerIndent}    ->"],
                ["{$innerIndent}\$q->", "{$innerIndent}    \$q->"],
                $innerLine
            );
            // Each $q-> statement inside the closure needs a semicolon
            $lines[] = rtrim($line).';';
        }

        if ($whereHas->hasCountConstraint()) {
            $lines[] = "{$indent}}, '{$whereHas->countOperator}', {$whereHas->countValue})";
        } else {
            $lines[] = "{$indent}})";
        }

        return $lines;
    }

    /**
     * Collect dynamic parameter variable names from a set of child nodes (recursive).
     *
     * @param  ConditionNode[]  $children
     * @return string[]
     */
    private function collectDynamicParams(array $children, string $context): array
    {
        $vars = [];
        foreach ($children as $child) {
            if ($child->type === 'condition' && $child->isDynamic()) {
                if ($context === 'controller') {
                    // In controller context, capture $request — property access isn't valid in use()
                    $vars[] = '$request';
                } else {
                    $vars[] = '$'.$child->parameterName();
                }
            }
            if (! empty($child->children)) {
                $vars = array_merge($vars, $this->collectDynamicParams($child->children, $context));
            }
        }

        return array_unique($vars);
    }

    /**
     * Dispatch a condition to the correct builder based on its value type.
     */
    private function buildConditionLine(ConditionNode $condition, string $indent, string $context): string
    {
        return match ($condition->valueType) {
            'dynamic' => $this->buildParameterizedConditionLine($condition, $indent, $context),
            'reference' => $this->buildReferenceConditionLine($condition, $indent),
            default => $this->buildHardcodedConditionLine($condition, $indent),
        };
    }

    /**
     * Build a column reference condition line (compares two columns).
     */
    private function buildReferenceConditionLine(ConditionNode $condition, string $indent): string
    {
        $method = $condition->boolean === 'or' ? 'orWhereColumn' : 'whereColumn';

        return "{$indent}->{$method}('{$condition->column}', '{$condition->operator}', '{$condition->referenceColumn}')";
    }

    /**
     * Build a hardcoded condition line (always applied).
     */
    private function buildHardcodedConditionLine(ConditionNode $condition, string $indent): string
    {
        $method = $condition->boolean === 'or' ? 'orWhere' : 'where';

        return match ($condition->operator) {
            'is_null' => "{$indent}->{$method}Null('{$condition->column}')",
            'is_not_null' => "{$indent}->{$method}NotNull('{$condition->column}')",
            'in' => "{$indent}->{$method}In('{$condition->column}', ".$this->phpValue($condition->value).')',
            'not_in' => "{$indent}->{$method}NotIn('{$condition->column}', ".$this->phpValue($condition->value).')',
            'between' => "{$indent}->{$method}Between('{$condition->column}', ".$this->phpValue($condition->value).')',
            'like' => "{$indent}->{$method}('{$condition->column}', 'like', ".$this->phpValue($condition->value).')',
            default => "{$indent}->{$method}('{$condition->column}', '{$condition->operator}', ".$this->phpValue($condition->value).')',
        };
    }

    /**
     * Build a parameterized condition line wrapped in ->when().
     */
    private function buildParameterizedConditionLine(ConditionNode $condition, string $indent, string $context): string
    {
        $paramName = $condition->parameterName();

        if ($context === 'controller') {
            $checkVar = "\$request->{$paramName}";
        } else {
            $checkVar = "\${$paramName}";
        }

        $innerMethod = $condition->boolean === 'or' ? 'orWhere' : 'where';

        return match ($condition->operator) {
            'is_null' => "{$indent}->when({$checkVar}, fn (\$q) => \$q->{$innerMethod}Null('{$condition->column}'))",
            'is_not_null' => "{$indent}->when({$checkVar}, fn (\$q) => \$q->{$innerMethod}NotNull('{$condition->column}'))",
            'in' => "{$indent}->when({$checkVar} !== null, fn (\$q) => \$q->{$innerMethod}In('{$condition->column}', {$checkVar}))",
            'not_in' => "{$indent}->when({$checkVar} !== null, fn (\$q) => \$q->{$innerMethod}NotIn('{$condition->column}', {$checkVar}))",
            'between' => "{$indent}->when({$checkVar} !== null, fn (\$q) => \$q->{$innerMethod}Between('{$condition->column}', {$checkVar}))",
            'like' => "{$indent}->when({$checkVar} !== null, fn (\$q) => \$q->{$innerMethod}('{$condition->column}', 'like', \"%{{$checkVar}}%\"))",
            default => "{$indent}->when({$checkVar} !== null, fn (\$q) => \$q->{$innerMethod}('{$condition->column}', '{$condition->operator}', {$checkVar}))",
        };
    }

    /**
     * Build scope method parameters from parameterized conditions.
     *
     * @return string[]
     */
    private function buildScopeParameters(QueryDefinition $query): array
    {
        $params = [];

        foreach ($query->parameterizedConditions() as $condition) {
            $type = $condition->phpType();
            $paramName = $condition->parameterName();
            $params[] = "?{$type} \${$paramName} = null";
        }

        return $params;
    }

    /**
     * Build scope call arguments from request properties.
     */
    private function buildScopeCallArguments(QueryDefinition $query): string
    {
        $args = [];

        foreach ($query->parameterizedConditions() as $condition) {
            $paramName = $condition->parameterName();
            $args[] = "\$request->{$paramName}";
        }

        return implode(', ', $args);
    }

    /**
     * Build PHPDoc for a scope method.
     */
    private function buildScopePhpDoc(QueryDefinition $query): string
    {
        $lines = [];
        $lines[] = '    /**';
        $lines[] = "     * @generated by SchemaCraft Query Builder: {$query->name}";
        $lines[] = '     *';
        $lines[] = '     * Re-generating this query from the visual builder will overwrite this code.';
        $lines[] = '     *';
        $lines[] = '     * @param  Builder  $query';

        foreach ($query->parameterizedConditions() as $condition) {
            $type = $condition->phpType();
            $paramName = $condition->parameterName();
            $desc = "Filter by {$condition->column}";
            $lines[] = "     * @param  {$type}|null  \${$paramName}  {$desc}";
        }

        $lines[] = '     */';

        return implode("\n", $lines);
    }

    /**
     * Build PHPDoc for a controller method.
     */
    private function buildControllerPhpDoc(QueryDefinition $query): string
    {
        $lines = [];
        $lines[] = '    /**';
        $lines[] = "     * @generated by SchemaCraft Query Builder: {$query->name}";
        $lines[] = '     *';
        $lines[] = '     * Re-generating this query from the visual builder will overwrite this code.';
        $lines[] = '     */';

        return implode("\n", $lines);
    }

    /**
     * Build validation rules from parameterized conditions.
     *
     * @return array<string, string>
     */
    private function buildValidationRules(QueryDefinition $query): array
    {
        $rules = [];

        foreach ($query->parameterizedConditions() as $condition) {
            $paramName = $condition->parameterName();

            $rule = match ($condition->operator) {
                'in', 'not_in' => 'sometimes|array',
                'between' => 'sometimes|array|size:2',
                'like', '=', '!=' => 'sometimes|string',
                '>', '<', '>=', '<=' => 'sometimes|numeric',
                'is_null', 'is_not_null' => 'sometimes|boolean',
                default => 'sometimes|string',
            };

            $rules[$paramName] = $rule;
        }

        return $rules;
    }

    /**
     * Convert a PHP value to a string representation.
     */
    private function phpValue(mixed $value): string
    {
        if (is_array($value)) {
            $items = array_map(fn ($v) => $this->phpValue($v), $value);

            return '['.implode(', ', $items).']';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        if ($value === null) {
            return 'null';
        }

        return "'{$value}'";
    }

    /**
     * Convert an operator to SQL syntax.
     */
    private function sqlOperator(string $operator): string
    {
        return match ($operator) {
            'like' => 'LIKE',
            'in' => 'IN',
            'not_in' => 'NOT IN',
            'is_null' => 'IS NULL',
            'is_not_null' => 'IS NOT NULL',
            'between' => 'BETWEEN',
            default => $operator,
        };
    }
}
