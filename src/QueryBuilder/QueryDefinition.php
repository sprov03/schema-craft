<?php

namespace SchemaCraft\QueryBuilder;

class QueryDefinition
{
    /**
     * @param  JoinDefinition[]  $joins
     * @param  ConditionNode[]  $conditions  Recursive tree of condition/group/whereHas nodes
     * @param  SortDefinition[]  $sorts
     * @param  string[]  $selects
     * @param  array{scopeOnModel: bool, apiEndpoint: bool, inlineController: bool}  $output
     * @param  array<int, array{schema: string, column: string, reason: string}>  $indexSuggestions
     */
    public function __construct(
        public string $name,
        public string $baseTable,
        public string $baseModel,
        public array $joins = [],
        public array $conditions = [],
        public array $sorts = [],
        public array $selects = [],
        public array $output = ['scopeOnModel' => true, 'apiEndpoint' => false, 'inlineController' => false],
        public array $indexSuggestions = [],
        public ?string $baseSchema = null,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $joins = array_map(
            fn (array $join) => JoinDefinition::fromArray($join),
            $data['joins'] ?? [],
        );

        $conditions = self::parseConditionsTree($data);

        $sorts = array_map(
            fn (array $sort) => SortDefinition::fromArray($sort),
            $data['sorts'] ?? [],
        );

        return new self(
            name: $data['name'],
            baseTable: $data['baseTable'],
            baseModel: $data['baseModel'],
            joins: $joins,
            conditions: $conditions,
            sorts: $sorts,
            selects: $data['selects'] ?? [],
            output: $data['output'] ?? ['scopeOnModel' => true, 'apiEndpoint' => false, 'inlineController' => false],
            indexSuggestions: $data['indexSuggestions'] ?? [],
            baseSchema: $data['baseSchema'] ?? null,
            createdAt: $data['createdAt'] ?? null,
            updatedAt: $data['updatedAt'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'baseTable' => $this->baseTable,
            'baseSchema' => $this->baseSchema,
            'baseModel' => $this->baseModel,
            'joins' => array_map(fn (JoinDefinition $j) => $j->toArray(), $this->joins),
            'conditions' => array_map(fn (ConditionNode $n) => $n->toArray(), $this->conditions),
            'sorts' => array_map(fn (SortDefinition $s) => $s->toArray(), $this->sorts),
            'selects' => $this->selects,
            'output' => $this->output,
            'indexSuggestions' => $this->indexSuggestions,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    /**
     * Get the studly-cased query name for use in class/method names.
     *
     * e.g., "publishedPostsByAuthor" → "PublishedPostsByAuthor"
     */
    public function studlyName(): string
    {
        return ucfirst($this->name);
    }

    /**
     * Get the base model class name (without namespace).
     */
    public function modelClass(): string
    {
        return class_basename($this->baseModel);
    }

    /**
     * Get all leaf condition nodes from the tree recursively.
     * Includes conditions inside groups and whereHas children.
     *
     * @return ConditionNode[]
     */
    public function allConditionNodes(): array
    {
        $result = [];
        $this->walkNodes($this->conditions, function (ConditionNode $node) use (&$result): void {
            if ($node->type === 'condition') {
                $result[] = $node;
            }
        });

        return $result;
    }

    /**
     * Get conditions that are parameterized (dynamic).
     *
     * Includes dynamic conditions inside whereHas closures — these
     * become scope/method parameters and are passed into the closure via use().
     *
     * @return ConditionNode[]
     */
    public function parameterizedConditions(): array
    {
        $result = [];
        $this->walkNodes($this->conditions, function (ConditionNode $node) use (&$result): void {
            if ($node->type === 'condition' && $node->isDynamic()) {
                $result[] = $node;
            }
        });

        return $result;
    }

    /**
     * Get conditions that are hardcoded (static).
     *
     * Skips conditions inside whereHas children.
     *
     * @return ConditionNode[]
     */
    public function hardcodedConditions(): array
    {
        $result = [];
        $this->walkNodesExcludingWhereHas($this->conditions, function (ConditionNode $node) use (&$result): void {
            if ($node->type === 'condition' && $node->isHardcoded()) {
                $result[] = $node;
            }
        });

        return $result;
    }

    /**
     * Get conditions that reference another column.
     *
     * Skips conditions inside whereHas children.
     *
     * @return ConditionNode[]
     */
    public function referenceConditions(): array
    {
        $result = [];
        $this->walkNodesExcludingWhereHas($this->conditions, function (ConditionNode $node) use (&$result): void {
            if ($node->type === 'condition' && $node->isReference()) {
                $result[] = $node;
            }
        });

        return $result;
    }

    /**
     * Whether this query definition wants a scope on the model.
     */
    public function wantsScope(): bool
    {
        return ($this->output['scopeOnModel'] ?? false) === true;
    }

    /**
     * Whether this query definition wants an API endpoint.
     */
    public function wantsApiEndpoint(): bool
    {
        return ($this->output['apiEndpoint'] ?? false) === true;
    }

    /**
     * Whether this query definition wants inline controller code (no scope).
     */
    public function wantsInlineController(): bool
    {
        return ($this->output['inlineController'] ?? false) === true;
    }

    // ── Private helpers ──────────────────────────────────────────

    /**
     * Parse conditions from input data, handling both tree and legacy flat formats.
     *
     * @param  array<string, mixed>  $data
     * @return ConditionNode[]
     */
    private static function parseConditionsTree(array $data): array
    {
        $raw = $data['conditions'] ?? [];
        $groups = $data['conditionGroups'] ?? [];
        $whereHas = $data['whereHas'] ?? [];

        // Empty conditions
        if (empty($raw) && empty($groups) && empty($whereHas)) {
            return [];
        }

        // New tree format: conditions have 'type' key
        if (! empty($raw) && isset($raw[0]['type'])) {
            return array_map(fn (array $n) => ConditionNode::fromArray($n), $raw);
        }

        // Legacy flat format: conditions + conditionGroups + whereHas as separate arrays
        return self::unflattenLegacyFormat($raw, $groups, $whereHas);
    }

    /**
     * Convert legacy flat format (conditions + conditionGroups + whereHas) into a tree.
     *
     * @param  array<int, array<string, mixed>>  $conditions
     * @param  array<int, array{id: int, boolean: string, parentGroupId: int|null}>  $groups
     * @param  array<int, array<string, mixed>>  $whereHas
     * @return ConditionNode[]
     */
    private static function unflattenLegacyFormat(array $conditions, array $groups, array $whereHas): array
    {
        // No groups — flat conditions + whereHas
        if (empty($groups)) {
            $result = array_map(
                fn (array $c) => ConditionNode::fromArray($c),
                $conditions,
            );

            foreach ($whereHas as $wh) {
                $result[] = self::legacyWhereHasToNode($wh);
            }

            return $result;
        }

        // Build group map
        $groupMap = [];
        foreach ($groups as $g) {
            $groupMap[$g['id']] = [
                'boolean' => $g['boolean'] ?? 'and',
                'parentGroupId' => $g['parentGroupId'] ?? null,
                'children' => [],
            ];
        }

        // Assign conditions to their groups (or top-level)
        $topLevel = [];
        foreach ($conditions as $c) {
            $groupId = $c['groupId'] ?? null;
            $node = ConditionNode::fromArray($c);

            if ($groupId !== null && isset($groupMap[$groupId])) {
                $groupMap[$groupId]['children'][] = $node;
            } else {
                $topLevel[] = $node;
            }
        }

        // Nest child groups into parent groups (bottom-up)
        $groupNodes = [];
        foreach ($groupMap as $gid => $g) {
            $groupNodes[$gid] = new ConditionNode(
                type: 'group',
                boolean: $g['boolean'],
                children: $g['children'],
            );
        }

        // Link children to parents
        foreach ($groups as $g) {
            $parentId = $g['parentGroupId'] ?? null;
            if ($parentId !== null && isset($groupNodes[$parentId])) {
                $groupNodes[$parentId]->children[] = $groupNodes[$g['id']];
            }
        }

        // Add top-level groups to result
        foreach ($groups as $g) {
            if (($g['parentGroupId'] ?? null) === null) {
                $topLevel[] = $groupNodes[$g['id']];
            }
        }

        // Add whereHas nodes
        foreach ($whereHas as $wh) {
            $topLevel[] = self::legacyWhereHasToNode($wh);
        }

        return $topLevel;
    }

    /**
     * Convert a legacy whereHas array into a ConditionNode.
     *
     * @param  array<string, mixed>  $wh
     */
    private static function legacyWhereHasToNode(array $wh): ConditionNode
    {
        $children = array_map(
            fn (array $c) => ConditionNode::fromArray($c),
            $wh['conditions'] ?? [],
        );

        return new ConditionNode(
            type: 'whereHas',
            boolean: $wh['boolean'] ?? 'and',
            children: $children,
            relationship: $wh['relationship'] ?? null,
            sourceModel: $wh['sourceModel'] ?? null,
            hasType: $wh['hasType'] ?? 'has',
            countOperator: $wh['countOperator'] ?? null,
            countValue: isset($wh['countValue']) ? (int) $wh['countValue'] : null,
        );
    }

    /**
     * Walk all nodes in the tree recursively.
     *
     * @param  ConditionNode[]  $nodes
     */
    private function walkNodes(array $nodes, callable $callback): void
    {
        foreach ($nodes as $node) {
            $callback($node);
            if (! empty($node->children)) {
                $this->walkNodes($node->children, $callback);
            }
        }
    }

    /**
     * Walk nodes recursively but skip into whereHas children.
     *
     * Used for collecting scope parameters — dynamic conditions inside
     * a whereHas closure are bound to that closure, not the outer scope.
     *
     * @param  ConditionNode[]  $nodes
     */
    private function walkNodesExcludingWhereHas(array $nodes, callable $callback): void
    {
        foreach ($nodes as $node) {
            $callback($node);
            if ($node->type !== 'whereHas' && ! empty($node->children)) {
                $this->walkNodesExcludingWhereHas($node->children, $callback);
            }
        }
    }
}
