<?php

namespace SchemaCraft\QueryBuilder;

/**
 * A recursive tree node for query conditions.
 *
 * Three node types:
 * - `condition`: A leaf WHERE clause (column, operator, value)
 * - `group`: A nested group of conditions wrapped in a closure
 * - `whereHas`: A relationship existence constraint (has/doesntHave/whereHas)
 *
 * Groups and whereHas nodes contain `children` arrays that recurse into more ConditionNodes.
 *
 * @property ConditionNode[] $children
 */
class ConditionNode
{
    /**
     * @param  'condition'|'group'|'whereHas'  $type
     * @param  ConditionNode[]  $children
     */
    public function __construct(
        public string $type,
        // Shared
        public string $boolean = 'and',
        public array $children = [],
        // Condition-specific
        public ?string $column = null,
        public string $operator = '=',
        public mixed $value = null,
        public string $valueType = 'hardcoded',
        public ?string $referenceColumn = null,
        // WhereHas-specific
        public ?string $relationship = null,
        public ?string $sourceModel = null,
        public string $hasType = 'has',
        public ?string $countOperator = null,
        public ?int $countValue = null,
    ) {}

    /**
     * Create a ConditionNode from an array.
     *
     * Handles both new tree format (has 'type' key) and legacy flat condition
     * format (no 'type' key, has 'column') for backward compatibility.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        // New tree format: has explicit 'type' key
        if (isset($data['type'])) {
            $children = array_map(
                fn (array $child) => self::fromArray($child),
                $data['children'] ?? [],
            );

            return new self(
                type: $data['type'],
                boolean: $data['boolean'] ?? 'and',
                children: $children,
                column: $data['column'] ?? null,
                operator: $data['operator'] ?? '=',
                value: $data['value'] ?? null,
                valueType: $data['valueType'] ?? (($data['parameter'] ?? false) ? 'dynamic' : 'hardcoded'),
                referenceColumn: $data['referenceColumn'] ?? null,
                relationship: $data['relationship'] ?? null,
                sourceModel: $data['sourceModel'] ?? null,
                hasType: $data['hasType'] ?? 'has',
                countOperator: $data['countOperator'] ?? null,
                countValue: isset($data['countValue']) ? (int) $data['countValue'] : null,
            );
        }

        // Legacy flat condition format (no 'type' key)
        return new self(
            type: 'condition',
            boolean: $data['boolean'] ?? 'and',
            column: $data['column'] ?? null,
            operator: $data['operator'] ?? '=',
            value: $data['value'] ?? null,
            valueType: $data['valueType'] ?? (($data['parameter'] ?? false) ? 'dynamic' : 'hardcoded'),
            referenceColumn: $data['referenceColumn'] ?? null,
        );
    }

    /**
     * Serialize to array, including only relevant fields per type.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->type === 'condition') {
            $data = [
                'type' => 'condition',
                'column' => $this->column,
                'operator' => $this->operator,
                'value' => $this->value,
                'boolean' => $this->boolean,
                'valueType' => $this->valueType,
            ];

            if ($this->referenceColumn !== null) {
                $data['referenceColumn'] = $this->referenceColumn;
            }

            return $data;
        }

        if ($this->type === 'group') {
            return [
                'type' => 'group',
                'boolean' => $this->boolean,
                'children' => array_map(fn (self $n) => $n->toArray(), $this->children),
            ];
        }

        // whereHas
        $data = [
            'type' => 'whereHas',
            'relationship' => $this->relationship,
            'sourceModel' => $this->sourceModel,
            'boolean' => $this->boolean,
            'hasType' => $this->hasType,
            'children' => array_map(fn (self $n) => $n->toArray(), $this->children),
        ];

        if ($this->countOperator !== null) {
            $data['countOperator'] = $this->countOperator;
            $data['countValue'] = $this->countValue;
        }

        return $data;
    }

    // ── Condition helpers (from ConditionDefinition) ─────────────

    /**
     * Whether this condition is a dynamic parameter (runtime value).
     */
    public function isDynamic(): bool
    {
        return $this->valueType === 'dynamic';
    }

    /**
     * Whether this condition is a hardcoded literal value.
     */
    public function isHardcoded(): bool
    {
        return $this->valueType === 'hardcoded';
    }

    /**
     * Whether this condition references another column.
     */
    public function isReference(): bool
    {
        return $this->valueType === 'reference';
    }

    /**
     * Get a camelCase parameter name derived from the column name.
     *
     * e.g., "posts.status" → "status", "users.first_name" → "firstName"
     */
    public function parameterName(): string
    {
        $column = str_contains($this->column, '.') ? substr($this->column, strpos($this->column, '.') + 1) : $this->column;

        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $column))));
    }

    /**
     * Determine if this operator requires no value (unary operator).
     */
    public function isUnary(): bool
    {
        return in_array($this->operator, ['is_null', 'is_not_null']);
    }

    /**
     * Determine the PHP type hint for this condition's parameter.
     */
    public function phpType(): string
    {
        return match ($this->operator) {
            'in', 'not_in' => 'array',
            'between' => 'array',
            default => 'string',
        };
    }

    // ── WhereHas helpers (from WhereHasDefinition) ──────────────

    /**
     * Whether this is a "doesntHave" (negative) constraint.
     */
    public function isNegated(): bool
    {
        return $this->hasType === 'doesntHave';
    }

    /**
     * Whether this has a count constraint (e.g., >= 5).
     */
    public function hasCountConstraint(): bool
    {
        return $this->countOperator !== null && $this->countValue !== null;
    }

    /**
     * Whether this whereHas node has nested conditions.
     */
    public function hasConditions(): bool
    {
        return ! empty($this->children);
    }

    /**
     * Get the Eloquent method name for this whereHas node.
     */
    public function eloquentMethod(): string
    {
        $isOr = $this->boolean === 'or';

        if ($this->isNegated()) {
            return $isOr ? 'orWhereDoesntHave' : 'whereDoesntHave';
        }

        // No conditions + no count = simple has()
        if (! $this->hasConditions() && ! $this->hasCountConstraint()) {
            return $isOr ? 'orHas' : 'has';
        }

        // No conditions but has count constraint = has() with count
        if (! $this->hasConditions() && $this->hasCountConstraint()) {
            return $isOr ? 'orHas' : 'has';
        }

        return $isOr ? 'orWhereHas' : 'whereHas';
    }
}
