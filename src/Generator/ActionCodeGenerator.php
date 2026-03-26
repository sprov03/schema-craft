<?php

namespace SchemaCraft\Generator;

use Illuminate\Support\Str;
use SchemaCraft\Scanner\ActionDefinition;
use SchemaCraft\Scanner\ActionParameter;
use SchemaCraft\Scanner\NestedRelationshipParameter;

/**
 * Generates service method code from an ActionDefinition.
 *
 * Reads ActionParameter[] directly — no cross-referencing ColumnDefinition
 * with RelationshipDefinition needed, since ActionParameter already knows
 * isModel, modelClass, nullable, relationship, foreignKeyColumn, and columnName.
 */
class ActionCodeGenerator
{
    /**
     * Render a complete service method from an ActionDefinition.
     *
     * The HTTP method determines the method shape:
     * - post: static method that creates a new model instance
     * - put: instance method that updates $this->model
     * - delete: instance method with no params, calls delete()
     * - get: instance method with no params, returns model
     */
    public function renderServiceMethod(ActionDefinition $action, string $modelName): string
    {
        $modelVariable = Str::camel($modelName);
        $methodName = $action->serviceMethod;
        $httpMethod = strtolower($action->httpMethod);

        return match ($httpMethod) {
            'post' => $this->renderCreateMethod($action, $modelName, $modelVariable, $methodName),
            'delete' => $this->renderDeleteMethod($modelVariable, $methodName),
            'get' => $this->renderGetMethod($modelName, $modelVariable, $methodName),
            default => $this->renderUpdateMethod($action, $modelName, $modelVariable, $methodName),
        };
    }

    /**
     * Build typed PHP method parameter string from ActionParameter[].
     *
     * FK params become Model-typed (e.g., User $author), scalars remain
     * primitive-typed (e.g., string $title). Multi-line when > 3 params.
     */
    public function buildMethodParams(ActionDefinition $action): string
    {
        $params = [];

        foreach ($action->parameters as $param) {
            $params[] = $this->formatParam($param);
        }

        if (empty($params)) {
            return '';
        }

        if (count($params) <= 3) {
            return implode(', ', $params);
        }

        return "\n        ".implode(",\n        ", $params).",\n    ";
    }

    /**
     * Build assignment lines for an update-style service method.
     *
     * Scalars: `$this->model->column = $param;`
     * FK (non-nullable): `$this->model->relationship()->associate($param);`
     * FK (nullable): conditional associate + dissociate
     */
    public function buildUpdateAssignments(ActionDefinition $action, string $modelVariable): string
    {
        $lines = [];

        foreach ($action->parameters as $param) {
            if ($param->isNestedRelationship) {
                continue; // Handled after save by buildNestedRelationshipAssignments()
            }

            if ($param->isModel) {
                $this->addFkUpdateAssignment($lines, $param, $modelVariable);
            } else {
                $columnName = $param->columnName ?? Str::snake($param->name);
                $lines[] = "        \$this->{$modelVariable}->{$columnName} = \${$param->name};";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Build assignment lines for a create-style (static) service method.
     *
     * Scalars: `$model->column = $param;`
     * FK (non-nullable): `$model->relationship()->associate($param);`
     * FK (nullable): conditional associate
     */
    public function buildCreateAssignments(ActionDefinition $action, string $modelVariable): string
    {
        $lines = [];

        foreach ($action->parameters as $param) {
            if ($param->isNestedRelationship) {
                continue; // Handled after save by buildNestedRelationshipAssignments()
            }

            if ($param->isModel) {
                $this->addFkCreateAssignment($lines, $param, $modelVariable);
            } else {
                $columnName = $param->columnName ?? Str::snake($param->name);
                $lines[] = "        \${$modelVariable}->{$columnName} = \${$param->name};";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Build use-import lines for related models referenced by FK parameters.
     */
    public function buildRelatedModelImports(ActionDefinition $action): string
    {
        $imports = [];

        foreach ($action->parameters as $param) {
            if ($param->isModel && $param->modelClass !== null) {
                $imports[] = "use {$param->modelClass};";
            }
        }

        $imports = array_unique($imports);
        sort($imports);

        return empty($imports) ? '' : "\n".implode("\n", $imports);
    }

    private function renderCreateMethod(ActionDefinition $action, string $modelName, string $modelVariable, string $methodName): string
    {
        $params = $this->buildMethodParams($action);
        $assignments = $this->buildCreateAssignments($action, $modelVariable);

        $lines = [];
        $lines[] = "    public static function {$methodName}({$params}): {$modelName}";
        $lines[] = '    {';
        $lines[] = "        \${$modelVariable} = new {$modelName}();";

        if ($assignments !== '') {
            $lines[] = '';
            $lines[] = $assignments;
        }

        $lines[] = '';
        $lines[] = "        \${$modelVariable}->save();";

        $nestedCode = $this->buildNestedRelationshipAssignments($action, $modelVariable, 'create');
        if ($nestedCode !== '') {
            $lines[] = $nestedCode;
        }

        $lines[] = '';
        $lines[] = "        return \${$modelVariable};";
        $lines[] = '    }';

        return implode("\n", $lines);
    }

    private function renderUpdateMethod(ActionDefinition $action, string $modelName, string $modelVariable, string $methodName): string
    {
        $params = $this->buildMethodParams($action);
        $assignments = $this->buildUpdateAssignments($action, $modelVariable);

        $lines = [];
        $lines[] = "    public function {$methodName}({$params}): {$modelName}";
        $lines[] = '    {';

        if ($assignments !== '') {
            $lines[] = $assignments;
            $lines[] = '';
        }

        $lines[] = "        \$this->{$modelVariable}->save();";

        $nestedCode = $this->buildNestedRelationshipAssignments($action, $modelVariable, 'update');
        if ($nestedCode !== '') {
            $lines[] = $nestedCode;
        }

        $lines[] = '';
        $lines[] = "        return \$this->{$modelVariable};";
        $lines[] = '    }';

        return implode("\n", $lines);
    }

    private function renderDeleteMethod(string $modelVariable, string $methodName): string
    {
        $lines = [];
        $lines[] = "    public function {$methodName}(): void";
        $lines[] = '    {';
        $lines[] = "        \$this->{$modelVariable}->delete();";
        $lines[] = '    }';

        return implode("\n", $lines);
    }

    private function renderGetMethod(string $modelName, string $modelVariable, string $methodName): string
    {
        $lines = [];
        $lines[] = "    public function {$methodName}(): {$modelName}";
        $lines[] = '    {';
        $lines[] = "        return \$this->{$modelVariable};";
        $lines[] = '    }';

        return implode("\n", $lines);
    }

    private function formatParam(ActionParameter $param): string
    {
        if ($param->isNestedRelationship) {
            $nested = $param->nestedRelationship;
            if ($nested !== null && $nested->isCollection) {
                return "array \${$param->name} = []";
            }
            $nullablePrefix = $param->nullable ? '?' : '';
            $default = $param->nullable ? ' = null' : '';

            return "{$nullablePrefix}array \${$param->name}{$default}";
        }

        if ($param->isModel && $param->modelClass !== null) {
            $modelBaseName = class_basename($param->modelClass);
            $nullablePrefix = $param->nullable ? '?' : '';
            $default = $param->nullable ? ' = null' : '';

            return "{$nullablePrefix}{$modelBaseName} \${$param->name}{$default}";
        }

        $nullablePrefix = $param->nullable ? '?' : '';
        $default = $param->nullable ? ' = null' : '';

        return "{$nullablePrefix}{$param->type} \${$param->name}{$default}";
    }

    /**
     * Build nested relationship handling code (runs after primary model save).
     */
    public function buildNestedRelationshipAssignments(ActionDefinition $action, string $modelVariable, string $context = 'update'): string
    {
        $lines = [];

        foreach ($action->parameters as $param) {
            if (! $param->isNestedRelationship || $param->nestedRelationship === null) {
                continue;
            }

            $nested = $param->nestedRelationship;
            $parentVar = $context === 'create' ? "\${$modelVariable}" : "\$this->{$modelVariable}";

            $nestedLines = $this->renderNestedRelationship($nested, $parentVar, $context);
            if (! empty($nestedLines)) {
                $lines[] = '';
                $lines = array_merge($lines, $nestedLines);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Render code for a single nested relationship.
     *
     * @return string[]
     */
    private function renderNestedRelationship(NestedRelationshipParameter $nested, string $parentVar, string $context): array
    {
        return match ($nested->relationshipType) {
            'hasOne', 'morphOne' => $this->renderSingularRelationship($nested, $parentVar, $context),
            'hasMany', 'morphMany' => $this->renderCollectionRelationship($nested, $parentVar, $context),
            'belongsToMany', 'morphToMany' => $this->renderPivotRelationship($nested, $parentVar),
            default => [],
        };
    }

    /**
     * Render code for HasOne/MorphOne nested data.
     *
     * @return string[]
     */
    private function renderSingularRelationship(NestedRelationshipParameter $nested, string $parentVar, string $context): array
    {
        $lines = [];
        $dataArray = $this->buildFieldDataArray($nested, "\${$nested->name}");
        $method = $context === 'create' ? 'create' : 'updateOrCreate';
        $firstArg = $context === 'create' ? '' : '[], ';

        if ($nested->nullable) {
            $lines[] = "        if (\${$nested->name} !== null) {";
            $lines[] = "            {$parentVar}->{$nested->name}()->{$method}({$firstArg}{$dataArray});";
            $lines[] = '        }';
        } else {
            $lines[] = "        {$parentVar}->{$nested->name}()->{$method}({$firstArg}{$dataArray});";
        }

        return $lines;
    }

    /**
     * Render code for HasMany/MorphMany nested data.
     *
     * @return string[]
     */
    private function renderCollectionRelationship(NestedRelationshipParameter $nested, string $parentVar, string $context): array
    {
        $lines = [];
        $mapArray = $this->buildCollectionMapArray($nested);

        $lines[] = "        if (! empty(\${$nested->name})) {";

        $lines[] = "            {$parentVar}->{$nested->name}()->createMany(";
        $lines[] = "                collect(\${$nested->name})->map(fn (\$item) => {$mapArray})->all()";
        $lines[] = '            );';
        $lines[] = '        }';

        return $lines;
    }

    /**
     * Render code for BelongsToMany/MorphToMany nested data.
     *
     * @return string[]
     */
    private function renderPivotRelationship(NestedRelationshipParameter $nested, string $parentVar): array
    {
        $lines = [];

        if (empty($nested->pivotFields)) {
            $lines[] = "        {$parentVar}->{$nested->name}()->sync(";
            $lines[] = "            collect(\${$nested->name})->pluck('id')->all()";
            $lines[] = '        );';
        } else {
            $pivotMapping = $this->buildPivotMappingArray($nested->pivotFields);
            $lines[] = "        {$parentVar}->{$nested->name}()->sync(";
            $lines[] = "            collect(\${$nested->name})->mapWithKeys(fn (\$item) => [\$item['id'] => {$pivotMapping}])->all()";
            $lines[] = '        );';
        }

        return $lines;
    }

    /**
     * Build a PHP array literal mapping nested fields for singular relationships.
     */
    private function buildFieldDataArray(NestedRelationshipParameter $nested, string $varName): string
    {
        $entries = [];
        foreach ($nested->fields as $field) {
            $entries[] = "'{$field->name}' => {$varName}['{$field->name}']";
        }

        if (count($entries) <= 3) {
            return '['.implode(', ', $entries).']';
        }

        return "[\n                ".implode(",\n                ", $entries).",\n            ]";
    }

    /**
     * Build a PHP array literal for collection map callback.
     */
    private function buildCollectionMapArray(NestedRelationshipParameter $nested): string
    {
        $entries = [];
        foreach ($nested->fields as $field) {
            $entries[] = "'{$field->name}' => \$item['{$field->name}']";
        }

        if (count($entries) <= 3) {
            return '['.implode(', ', $entries).']';
        }

        return "[\n                    ".implode(",\n                    ", $entries).",\n                ]";
    }

    /**
     * Build a PHP array literal for pivot field mapping.
     *
     * @param  string[]  $pivotFields
     */
    private function buildPivotMappingArray(array $pivotFields): string
    {
        $entries = [];
        foreach ($pivotFields as $field) {
            $entries[] = "'{$field}' => \$item['{$field}'] ?? null";
        }

        return '['.implode(', ', $entries).']';
    }

    private function addFkUpdateAssignment(array &$lines, ActionParameter $param, string $modelVariable): void
    {
        $relationMethod = $param->relationship ?? $param->name;

        if ($param->nullable) {
            $lines[] = "        if (\${$param->name} !== null) {";
            $lines[] = "            \$this->{$modelVariable}->{$relationMethod}()->associate(\${$param->name});";
            $lines[] = '        } else {';
            $lines[] = "            \$this->{$modelVariable}->{$relationMethod}()->dissociate();";
            $lines[] = '        }';
        } else {
            $lines[] = "        \$this->{$modelVariable}->{$relationMethod}()->associate(\${$param->name});";
        }
    }

    private function addFkCreateAssignment(array &$lines, ActionParameter $param, string $modelVariable): void
    {
        $relationMethod = $param->relationship ?? $param->name;

        if ($param->nullable) {
            $lines[] = "        if (\${$param->name} !== null) {";
            $lines[] = "            \${$modelVariable}->{$relationMethod}()->associate(\${$param->name});";
            $lines[] = '        }';
        } else {
            $lines[] = "        \${$modelVariable}->{$relationMethod}()->associate(\${$param->name});";
        }
    }
}
