<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\QueryBuilder\ConditionNode;
use SchemaCraft\QueryBuilder\JoinDefinition;
use SchemaCraft\QueryBuilder\QueryDefinition;
use SchemaCraft\QueryBuilder\SortDefinition;

class QueryDefinitionTest extends TestCase
{
    // ─── fromArray parsing ──────────────────────────────────────

    public function test_from_array_parses_basic_fields(): void
    {
        $query = QueryDefinition::fromArray([
            'name' => 'publishedPosts',
            'baseTable' => 'posts',
            'baseModel' => 'App\\Models\\Post',
            'baseSchema' => 'App\\Schemas\\PostSchema',
        ]);

        $this->assertEquals('publishedPosts', $query->name);
        $this->assertEquals('posts', $query->baseTable);
        $this->assertEquals('App\\Models\\Post', $query->baseModel);
        $this->assertEquals('App\\Schemas\\PostSchema', $query->baseSchema);
    }

    public function test_from_array_parses_joins(): void
    {
        $query = QueryDefinition::fromArray([
            'name' => 'postsByAuthor',
            'baseTable' => 'posts',
            'baseModel' => 'App\\Models\\Post',
            'joins' => [
                [
                    'type' => 'inner',
                    'table' => 'users',
                    'localColumn' => 'author_id',
                    'foreignColumn' => 'id',
                    'model' => 'App\\Models\\User',
                ],
            ],
        ]);

        $this->assertCount(1, $query->joins);
        $this->assertInstanceOf(JoinDefinition::class, $query->joins[0]);
        $this->assertEquals('inner', $query->joins[0]->type);
        $this->assertEquals('users', $query->joins[0]->table);
        $this->assertEquals('author_id', $query->joins[0]->localColumn);
    }

    public function test_from_array_parses_tree_conditions(): void
    {
        $query = QueryDefinition::fromArray([
            'name' => 'publishedPosts',
            'baseTable' => 'posts',
            'baseModel' => 'App\\Models\\Post',
            'conditions' => [
                ['type' => 'condition', 'column' => 'posts.status', 'operator' => '=', 'value' => 'published', 'valueType' => 'hardcoded', 'boolean' => 'and'],
                ['type' => 'condition', 'column' => 'posts.title', 'operator' => 'like', 'valueType' => 'dynamic', 'boolean' => 'and'],
            ],
        ]);

        $this->assertCount(2, $query->conditions);
        $this->assertInstanceOf(ConditionNode::class, $query->conditions[0]);
        $this->assertEquals('condition', $query->conditions[0]->type);
        $this->assertTrue($query->conditions[0]->isHardcoded());
        $this->assertTrue($query->conditions[1]->isDynamic());
    }

    public function test_from_array_parses_legacy_flat_conditions(): void
    {
        $query = QueryDefinition::fromArray([
            'name' => 'publishedPosts',
            'baseTable' => 'posts',
            'baseModel' => 'App\\Models\\Post',
            'conditions' => [
                ['column' => 'posts.status', 'operator' => '=', 'value' => 'published', 'valueType' => 'hardcoded'],
                ['column' => 'posts.title', 'operator' => 'like', 'valueType' => 'dynamic'],
            ],
        ]);

        $this->assertCount(2, $query->conditions);
        $this->assertInstanceOf(ConditionNode::class, $query->conditions[0]);
        $this->assertEquals('condition', $query->conditions[0]->type);
        $this->assertTrue($query->conditions[0]->isHardcoded());
        $this->assertTrue($query->conditions[1]->isDynamic());
    }

    public function test_from_array_backward_compat_parameter_bool(): void
    {
        $query = QueryDefinition::fromArray([
            'name' => 'test',
            'baseTable' => 'posts',
            'baseModel' => 'App\\Models\\Post',
            'conditions' => [
                ['column' => 'posts.status', 'operator' => '=', 'value' => 'published', 'parameter' => false],
                ['column' => 'posts.title', 'operator' => 'like', 'parameter' => true],
            ],
        ]);

        $this->assertCount(2, $query->conditions);
        $this->assertTrue($query->conditions[0]->isHardcoded());
        $this->assertEquals('hardcoded', $query->conditions[0]->valueType);
        $this->assertTrue($query->conditions[1]->isDynamic());
        $this->assertEquals('dynamic', $query->conditions[1]->valueType);
    }

    public function test_from_array_parses_sorts(): void
    {
        $query = QueryDefinition::fromArray([
            'name' => 'recentPosts',
            'baseTable' => 'posts',
            'baseModel' => 'App\\Models\\Post',
            'sorts' => [
                ['column' => 'posts.created_at', 'direction' => 'desc'],
            ],
        ]);

        $this->assertCount(1, $query->sorts);
        $this->assertInstanceOf(SortDefinition::class, $query->sorts[0]);
        $this->assertEquals('desc', $query->sorts[0]->direction);
    }

    public function test_from_array_defaults(): void
    {
        $query = QueryDefinition::fromArray([
            'name' => 'basicQuery',
            'baseTable' => 'posts',
            'baseModel' => 'App\\Models\\Post',
        ]);

        $this->assertEmpty($query->joins);
        $this->assertEmpty($query->conditions);
        $this->assertEmpty($query->sorts);
        $this->assertEmpty($query->selects);
        $this->assertTrue($query->output['scopeOnModel']);
        $this->assertFalse($query->output['apiEndpoint']);
        $this->assertFalse($query->output['inlineController']);
    }

    // ─── toArray roundtrip ──────────────────────────────────────

    public function test_to_array_roundtrip(): void
    {
        $original = [
            'name' => 'postsByAuthor',
            'baseTable' => 'posts',
            'baseModel' => 'App\\Models\\Post',
            'baseSchema' => 'App\\Schemas\\PostSchema',
            'joins' => [
                [
                    'type' => 'left',
                    'table' => 'users',
                    'localColumn' => 'author_id',
                    'foreignColumn' => 'id',
                    'schema' => null,
                    'model' => null,
                    'alias' => null,
                    'relationshipName' => null,
                    'indexed' => false,
                ],
            ],
            'conditions' => [
                ['type' => 'condition', 'column' => 'posts.status', 'operator' => '=', 'value' => 'published', 'boolean' => 'and', 'valueType' => 'hardcoded'],
            ],
            'sorts' => [
                ['column' => 'posts.created_at', 'direction' => 'desc', 'parameter' => false],
            ],
            'selects' => ['posts.*', 'users.name as author_name'],
            'output' => ['scopeOnModel' => true, 'apiEndpoint' => false, 'inlineController' => false],
            'indexSuggestions' => [],
            'createdAt' => null,
            'updatedAt' => null,
        ];

        $query = QueryDefinition::fromArray($original);
        $result = $query->toArray();

        $this->assertEquals($original, $result);
    }

    // ─── Helper methods ─────────────────────────────────────────

    public function test_studly_name(): void
    {
        $query = $this->simpleQuery('publishedPosts');

        $this->assertEquals('PublishedPosts', $query->studlyName());
    }

    public function test_model_class(): void
    {
        $query = $this->simpleQuery('test');

        $this->assertEquals('Post', $query->modelClass());
    }

    public function test_parameterized_conditions(): void
    {
        $query = QueryDefinition::fromArray([
            'name' => 'test',
            'baseTable' => 'posts',
            'baseModel' => 'App\\Models\\Post',
            'conditions' => [
                ['type' => 'condition', 'column' => 'posts.status', 'operator' => '=', 'value' => 'published', 'valueType' => 'hardcoded', 'boolean' => 'and'],
                ['type' => 'condition', 'column' => 'posts.title', 'operator' => 'like', 'valueType' => 'dynamic', 'boolean' => 'and'],
                ['type' => 'condition', 'column' => 'posts.author_id', 'operator' => '=', 'valueType' => 'dynamic', 'boolean' => 'and'],
            ],
        ]);

        $parameterized = $query->parameterizedConditions();
        $hardcoded = $query->hardcodedConditions();

        $this->assertCount(2, $parameterized);
        $this->assertCount(1, $hardcoded);
        $this->assertEquals('posts.title', $parameterized[0]->column);
        $this->assertEquals('posts.status', $hardcoded[0]->column);
    }

    public function test_parameterized_conditions_excludes_where_has_children(): void
    {
        $query = QueryDefinition::fromArray([
            'name' => 'test',
            'baseTable' => 'posts',
            'baseModel' => 'App\\Models\\Post',
            'conditions' => [
                ['type' => 'condition', 'column' => 'posts.status', 'operator' => '=', 'valueType' => 'dynamic', 'boolean' => 'and'],
                [
                    'type' => 'whereHas',
                    'relationship' => 'comments',
                    'sourceModel' => 'App\\Models\\Post',
                    'boolean' => 'and',
                    'hasType' => 'has',
                    'children' => [
                        ['type' => 'condition', 'column' => 'comments.author', 'operator' => '=', 'valueType' => 'dynamic', 'boolean' => 'and'],
                    ],
                ],
            ],
        ]);

        // Both the top-level and whereHas child dynamic conditions are included
        $parameterized = $query->parameterizedConditions();
        $this->assertCount(2, $parameterized);
        $this->assertEquals('posts.status', $parameterized[0]->column);
        $this->assertEquals('comments.author', $parameterized[1]->column);
    }

    public function test_output_flags(): void
    {
        $query = QueryDefinition::fromArray([
            'name' => 'test',
            'baseTable' => 'posts',
            'baseModel' => 'App\\Models\\Post',
            'output' => ['scopeOnModel' => true, 'apiEndpoint' => true, 'inlineController' => false],
        ]);

        $this->assertTrue($query->wantsScope());
        $this->assertTrue($query->wantsApiEndpoint());
        $this->assertFalse($query->wantsInlineController());
    }

    // ─── ConditionNode ────────────────────────────────────────────

    public function test_condition_node_parameter_name_strips_table(): void
    {
        $node = new ConditionNode(type: 'condition', column: 'posts.first_name', valueType: 'dynamic');

        $this->assertEquals('firstName', $node->parameterName());
    }

    public function test_condition_node_parameter_name_without_table(): void
    {
        $node = new ConditionNode(type: 'condition', column: 'status', valueType: 'dynamic');

        $this->assertEquals('status', $node->parameterName());
    }

    public function test_condition_node_is_unary(): void
    {
        $this->assertTrue((new ConditionNode(type: 'condition', column: 'a', operator: 'is_null'))->isUnary());
        $this->assertTrue((new ConditionNode(type: 'condition', column: 'a', operator: 'is_not_null'))->isUnary());
        $this->assertFalse((new ConditionNode(type: 'condition', column: 'a', operator: '='))->isUnary());
    }

    public function test_condition_node_php_type(): void
    {
        $this->assertEquals('array', (new ConditionNode(type: 'condition', column: 'a', operator: 'in'))->phpType());
        $this->assertEquals('array', (new ConditionNode(type: 'condition', column: 'a', operator: 'between'))->phpType());
        $this->assertEquals('string', (new ConditionNode(type: 'condition', column: 'a', operator: '='))->phpType());
    }

    public function test_condition_node_value_type_helpers(): void
    {
        $hardcoded = new ConditionNode(type: 'condition', column: 'a', valueType: 'hardcoded');
        $this->assertTrue($hardcoded->isHardcoded());
        $this->assertFalse($hardcoded->isDynamic());
        $this->assertFalse($hardcoded->isReference());

        $dynamic = new ConditionNode(type: 'condition', column: 'a', valueType: 'dynamic');
        $this->assertFalse($dynamic->isHardcoded());
        $this->assertTrue($dynamic->isDynamic());
        $this->assertFalse($dynamic->isReference());

        $reference = new ConditionNode(type: 'condition', column: 'a', valueType: 'reference', referenceColumn: 'b');
        $this->assertFalse($reference->isHardcoded());
        $this->assertFalse($reference->isDynamic());
        $this->assertTrue($reference->isReference());
    }

    public function test_condition_node_reference_serialization(): void
    {
        $node = new ConditionNode(
            type: 'condition',
            column: 'tasks.completed_at',
            operator: '>=',
            valueType: 'reference',
            referenceColumn: 'tasks.due_at',
        );

        $array = $node->toArray();
        $this->assertEquals('reference', $array['valueType']);
        $this->assertEquals('tasks.due_at', $array['referenceColumn']);
        $this->assertEquals('condition', $array['type']);

        $restored = ConditionNode::fromArray($array);
        $this->assertTrue($restored->isReference());
        $this->assertEquals('tasks.due_at', $restored->referenceColumn);
    }

    public function test_condition_node_reference_column_omitted_when_null(): void
    {
        $node = new ConditionNode(type: 'condition', column: 'a', valueType: 'hardcoded');

        $array = $node->toArray();
        $this->assertArrayNotHasKey('referenceColumn', $array);
    }

    // ─── Condition Groups (tree format) ──────────────────────────

    public function test_tree_format_with_groups(): void
    {
        $query = QueryDefinition::fromArray([
            'name' => 'test',
            'baseTable' => 'posts',
            'baseModel' => 'App\\Models\\Post',
            'conditions' => [
                ['type' => 'condition', 'column' => 'posts.status', 'operator' => '=', 'value' => 'published', 'valueType' => 'hardcoded', 'boolean' => 'and'],
                [
                    'type' => 'group',
                    'boolean' => 'or',
                    'children' => [
                        ['type' => 'condition', 'column' => 'posts.title', 'operator' => 'like', 'valueType' => 'dynamic', 'boolean' => 'and'],
                        ['type' => 'condition', 'column' => 'posts.body', 'operator' => 'like', 'valueType' => 'dynamic', 'boolean' => 'and'],
                    ],
                ],
            ],
        ]);

        $this->assertCount(2, $query->conditions);
        $this->assertEquals('condition', $query->conditions[0]->type);
        $this->assertEquals('group', $query->conditions[1]->type);
        $this->assertEquals('or', $query->conditions[1]->boolean);
        $this->assertCount(2, $query->conditions[1]->children);
        $this->assertEquals('posts.title', $query->conditions[1]->children[0]->column);
    }

    public function test_tree_format_with_nested_groups(): void
    {
        $query = QueryDefinition::fromArray([
            'name' => 'test',
            'baseTable' => 'posts',
            'baseModel' => 'App\\Models\\Post',
            'conditions' => [
                [
                    'type' => 'group',
                    'boolean' => 'or',
                    'children' => [
                        ['type' => 'condition', 'column' => 'a', 'operator' => '=', 'value' => '1', 'boolean' => 'and', 'valueType' => 'hardcoded'],
                        [
                            'type' => 'group',
                            'boolean' => 'and',
                            'children' => [
                                ['type' => 'condition', 'column' => 'b', 'operator' => '=', 'value' => '2', 'boolean' => 'and', 'valueType' => 'hardcoded'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertCount(1, $query->conditions);
        $group = $query->conditions[0];
        $this->assertEquals('group', $group->type);
        $this->assertCount(2, $group->children);
        $this->assertEquals('group', $group->children[1]->type);
        $this->assertCount(1, $group->children[1]->children);
    }

    public function test_condition_groups_roundtrip(): void
    {
        $original = [
            'name' => 'test',
            'baseTable' => 'posts',
            'baseModel' => 'App\\Models\\Post',
            'baseSchema' => null,
            'joins' => [],
            'conditions' => [
                ['type' => 'condition', 'column' => 'posts.status', 'operator' => '=', 'value' => 'published', 'boolean' => 'and', 'valueType' => 'hardcoded'],
                [
                    'type' => 'group',
                    'boolean' => 'or',
                    'children' => [
                        ['type' => 'condition', 'column' => 'posts.title', 'operator' => 'like', 'value' => null, 'boolean' => 'and', 'valueType' => 'dynamic'],
                    ],
                ],
            ],
            'sorts' => [],
            'selects' => [],
            'output' => ['scopeOnModel' => true, 'apiEndpoint' => false, 'inlineController' => false],
            'indexSuggestions' => [],
            'createdAt' => null,
            'updatedAt' => null,
        ];

        $query = QueryDefinition::fromArray($original);
        $result = $query->toArray();

        $this->assertEquals($original, $result);
    }

    // ─── Legacy format backward compatibility ────────────────────

    public function test_legacy_flat_conditions_with_groups_unflatten_to_tree(): void
    {
        $query = QueryDefinition::fromArray([
            'name' => 'test',
            'baseTable' => 'posts',
            'baseModel' => 'App\\Models\\Post',
            'conditions' => [
                ['column' => 'posts.status', 'operator' => '=', 'value' => 'published', 'valueType' => 'hardcoded'],
                ['column' => 'posts.title', 'operator' => 'like', 'valueType' => 'dynamic', 'groupId' => 1],
                ['column' => 'posts.body', 'operator' => 'like', 'valueType' => 'dynamic', 'groupId' => 1],
            ],
            'conditionGroups' => [
                ['id' => 1, 'boolean' => 'or', 'parentGroupId' => null],
            ],
        ]);

        // Should produce tree: [condition, group(or)[condition, condition]]
        $this->assertCount(2, $query->conditions);
        $this->assertEquals('condition', $query->conditions[0]->type);
        $this->assertEquals('posts.status', $query->conditions[0]->column);
        $this->assertEquals('group', $query->conditions[1]->type);
        $this->assertEquals('or', $query->conditions[1]->boolean);
        $this->assertCount(2, $query->conditions[1]->children);
    }

    public function test_legacy_nested_groups_unflatten_to_tree(): void
    {
        $query = QueryDefinition::fromArray([
            'name' => 'test',
            'baseTable' => 'posts',
            'baseModel' => 'App\\Models\\Post',
            'conditions' => [
                ['column' => 'a', 'operator' => '=', 'value' => '1', 'valueType' => 'hardcoded', 'groupId' => 1],
                ['column' => 'b', 'operator' => '=', 'value' => '2', 'valueType' => 'hardcoded', 'groupId' => 2],
            ],
            'conditionGroups' => [
                ['id' => 1, 'boolean' => 'or', 'parentGroupId' => null],
                ['id' => 2, 'boolean' => 'and', 'parentGroupId' => 1],
            ],
        ]);

        // Should produce tree: [group(or)[ condition(a), group(and)[ condition(b) ] ]]
        $this->assertCount(1, $query->conditions);
        $topGroup = $query->conditions[0];
        $this->assertEquals('group', $topGroup->type);
        $this->assertEquals('or', $topGroup->boolean);
        $this->assertCount(2, $topGroup->children);
        $this->assertEquals('condition', $topGroup->children[0]->type);
        $this->assertEquals('group', $topGroup->children[1]->type);
        $this->assertEquals('and', $topGroup->children[1]->boolean);
    }

    public function test_legacy_where_has_unflatten_to_tree(): void
    {
        $query = QueryDefinition::fromArray([
            'name' => 'postsWithComments',
            'baseTable' => 'posts',
            'baseModel' => 'App\\Models\\Post',
            'whereHas' => [
                [
                    'relationship' => 'comments',
                    'sourceModel' => 'App\\Models\\Post',
                    'boolean' => 'and',
                    'hasType' => 'has',
                    'conditions' => [
                        ['column' => 'comments.approved', 'operator' => '=', 'value' => '1', 'valueType' => 'hardcoded'],
                    ],
                ],
            ],
        ]);

        // WhereHas should be in the conditions tree
        $this->assertCount(1, $query->conditions);
        $wh = $query->conditions[0];
        $this->assertEquals('whereHas', $wh->type);
        $this->assertEquals('comments', $wh->relationship);
        $this->assertEquals('App\\Models\\Post', $wh->sourceModel);
        $this->assertCount(1, $wh->children);
        $this->assertEquals('comments.approved', $wh->children[0]->column);
    }

    // ─── Reference Conditions ───────────────────────────────────

    public function test_reference_conditions_filter(): void
    {
        $query = QueryDefinition::fromArray([
            'name' => 'test',
            'baseTable' => 'tasks',
            'baseModel' => 'App\\Models\\Task',
            'conditions' => [
                ['type' => 'condition', 'column' => 'tasks.status', 'operator' => '=', 'value' => 'done', 'valueType' => 'hardcoded', 'boolean' => 'and'],
                ['type' => 'condition', 'column' => 'tasks.completed_at', 'operator' => '>=', 'valueType' => 'reference', 'referenceColumn' => 'tasks.due_at', 'boolean' => 'and'],
                ['type' => 'condition', 'column' => 'tasks.title', 'operator' => 'like', 'valueType' => 'dynamic', 'boolean' => 'and'],
            ],
        ]);

        $this->assertCount(1, $query->referenceConditions());
        $this->assertCount(1, $query->hardcodedConditions());
        $this->assertCount(1, $query->parameterizedConditions());
        $this->assertEquals('tasks.completed_at', $query->referenceConditions()[0]->column);
        $this->assertEquals('tasks.due_at', $query->referenceConditions()[0]->referenceColumn);
    }

    // ─── WhereHas in tree format ────────────────────────────────

    public function test_where_has_node_from_array(): void
    {
        $node = ConditionNode::fromArray([
            'type' => 'whereHas',
            'relationship' => 'comments',
            'sourceModel' => 'App\\Models\\Post',
            'boolean' => 'and',
            'hasType' => 'has',
            'children' => [
                ['type' => 'condition', 'column' => 'comments.approved', 'operator' => '=', 'value' => '1', 'valueType' => 'hardcoded', 'boolean' => 'and'],
            ],
        ]);

        $this->assertEquals('whereHas', $node->type);
        $this->assertEquals('comments', $node->relationship);
        $this->assertEquals('App\\Models\\Post', $node->sourceModel);
        $this->assertEquals('and', $node->boolean);
        $this->assertEquals('has', $node->hasType);
        $this->assertNull($node->countOperator);
        $this->assertNull($node->countValue);
        $this->assertCount(1, $node->children);
        $this->assertInstanceOf(ConditionNode::class, $node->children[0]);
    }

    public function test_where_has_node_with_count_constraint(): void
    {
        $node = ConditionNode::fromArray([
            'type' => 'whereHas',
            'relationship' => 'comments',
            'sourceModel' => 'App\\Models\\Post',
            'boolean' => 'and',
            'hasType' => 'has',
            'countOperator' => '>=',
            'countValue' => 5,
            'children' => [],
        ]);

        $this->assertTrue($node->hasCountConstraint());
        $this->assertEquals('>=', $node->countOperator);
        $this->assertEquals(5, $node->countValue);
    }

    public function test_where_has_node_doesnt_have(): void
    {
        $node = new ConditionNode(
            type: 'whereHas',
            relationship: 'comments',
            sourceModel: 'App\\Models\\Post',
            hasType: 'doesntHave',
        );

        $this->assertTrue($node->isNegated());
    }

    public function test_where_has_node_eloquent_method(): void
    {
        // Simple has (no children, no count)
        $has = new ConditionNode(type: 'whereHas', relationship: 'comments', sourceModel: 'App\\Models\\Post');
        $this->assertEquals('has', $has->eloquentMethod());

        // orHas
        $orHas = new ConditionNode(type: 'whereHas', relationship: 'comments', sourceModel: 'App\\Models\\Post', boolean: 'or');
        $this->assertEquals('orHas', $orHas->eloquentMethod());

        // doesntHave
        $doesntHave = new ConditionNode(type: 'whereHas', relationship: 'comments', sourceModel: 'App\\Models\\Post', hasType: 'doesntHave');
        $this->assertEquals('whereDoesntHave', $doesntHave->eloquentMethod());

        // orWhereDoesntHave
        $orDoesntHave = new ConditionNode(type: 'whereHas', relationship: 'comments', sourceModel: 'App\\Models\\Post', hasType: 'doesntHave', boolean: 'or');
        $this->assertEquals('orWhereDoesntHave', $orDoesntHave->eloquentMethod());

        // whereHas (with children)
        $whereHas = new ConditionNode(
            type: 'whereHas',
            relationship: 'comments',
            sourceModel: 'App\\Models\\Post',
            children: [new ConditionNode(type: 'condition', column: 'comments.approved', operator: '=', value: '1', valueType: 'hardcoded')],
        );
        $this->assertEquals('whereHas', $whereHas->eloquentMethod());

        // has with count (no children)
        $hasCount = new ConditionNode(
            type: 'whereHas',
            relationship: 'comments',
            sourceModel: 'App\\Models\\Post',
            countOperator: '>=',
            countValue: 5,
        );
        $this->assertEquals('has', $hasCount->eloquentMethod());
    }

    public function test_where_has_node_to_array(): void
    {
        $node = new ConditionNode(
            type: 'whereHas',
            relationship: 'comments',
            sourceModel: 'App\\Models\\Post',
            boolean: 'and',
            hasType: 'has',
            children: [new ConditionNode(type: 'condition', column: 'comments.approved', operator: '=', value: '1', valueType: 'hardcoded', boolean: 'and')],
        );

        $array = $node->toArray();

        $this->assertEquals('whereHas', $array['type']);
        $this->assertEquals('comments', $array['relationship']);
        $this->assertEquals('App\\Models\\Post', $array['sourceModel']);
        $this->assertEquals('and', $array['boolean']);
        $this->assertEquals('has', $array['hasType']);
        $this->assertArrayNotHasKey('countOperator', $array);
        $this->assertArrayNotHasKey('countValue', $array);
        $this->assertCount(1, $array['children']);
    }

    public function test_where_has_node_to_array_with_count(): void
    {
        $node = new ConditionNode(
            type: 'whereHas',
            relationship: 'comments',
            sourceModel: 'App\\Models\\Post',
            countOperator: '>=',
            countValue: 5,
        );

        $array = $node->toArray();

        $this->assertEquals('>=', $array['countOperator']);
        $this->assertEquals(5, $array['countValue']);
    }

    public function test_where_has_node_roundtrip(): void
    {
        $original = [
            'type' => 'whereHas',
            'relationship' => 'comments',
            'sourceModel' => 'App\\Models\\Post',
            'boolean' => 'and',
            'hasType' => 'has',
            'children' => [
                ['type' => 'condition', 'column' => 'comments.approved', 'operator' => '=', 'value' => '1', 'boolean' => 'and', 'valueType' => 'hardcoded'],
            ],
            'countOperator' => '>=',
            'countValue' => 5,
        ];

        $node = ConditionNode::fromArray($original);
        $result = $node->toArray();

        $this->assertEquals($original, $result);
    }

    public function test_query_definition_parses_tree_where_has(): void
    {
        $query = QueryDefinition::fromArray([
            'name' => 'postsWithComments',
            'baseTable' => 'posts',
            'baseModel' => 'App\\Models\\Post',
            'conditions' => [
                [
                    'type' => 'whereHas',
                    'relationship' => 'comments',
                    'sourceModel' => 'App\\Models\\Post',
                    'boolean' => 'and',
                    'hasType' => 'has',
                    'children' => [
                        ['type' => 'condition', 'column' => 'comments.approved', 'operator' => '=', 'value' => '1', 'valueType' => 'hardcoded', 'boolean' => 'and'],
                    ],
                ],
            ],
        ]);

        $this->assertCount(1, $query->conditions);
        $this->assertInstanceOf(ConditionNode::class, $query->conditions[0]);
        $this->assertEquals('whereHas', $query->conditions[0]->type);
        $this->assertEquals('comments', $query->conditions[0]->relationship);
        $this->assertCount(1, $query->conditions[0]->children);
    }

    public function test_query_definition_empty_conditions_default(): void
    {
        $query = QueryDefinition::fromArray([
            'name' => 'test',
            'baseTable' => 'posts',
            'baseModel' => 'App\\Models\\Post',
        ]);

        $this->assertEmpty($query->conditions);
    }

    // ─── allConditionNodes ───────────────────────────────────────

    public function test_all_condition_nodes_collects_from_tree(): void
    {
        $query = QueryDefinition::fromArray([
            'name' => 'test',
            'baseTable' => 'posts',
            'baseModel' => 'App\\Models\\Post',
            'conditions' => [
                ['type' => 'condition', 'column' => 'a', 'operator' => '=', 'value' => '1', 'boolean' => 'and', 'valueType' => 'hardcoded'],
                [
                    'type' => 'group',
                    'boolean' => 'or',
                    'children' => [
                        ['type' => 'condition', 'column' => 'b', 'operator' => '=', 'value' => '2', 'boolean' => 'and', 'valueType' => 'hardcoded'],
                    ],
                ],
                [
                    'type' => 'whereHas',
                    'relationship' => 'comments',
                    'sourceModel' => 'App\\Models\\Post',
                    'boolean' => 'and',
                    'hasType' => 'has',
                    'children' => [
                        ['type' => 'condition', 'column' => 'c', 'operator' => '=', 'value' => '3', 'boolean' => 'and', 'valueType' => 'hardcoded'],
                    ],
                ],
            ],
        ]);

        $all = $query->allConditionNodes();
        $this->assertCount(3, $all);
        $columns = array_map(fn (ConditionNode $n) => $n->column, $all);
        $this->assertEquals(['a', 'b', 'c'], $columns);
    }

    // ─── JoinDefinition ─────────────────────────────────────────

    public function test_join_method(): void
    {
        $this->assertEquals('join', (new JoinDefinition(type: 'inner', table: 't', localColumn: 'a', foreignColumn: 'b'))->joinMethod());
        $this->assertEquals('leftJoin', (new JoinDefinition(type: 'left', table: 't', localColumn: 'a', foreignColumn: 'b'))->joinMethod());
        $this->assertEquals('rightJoin', (new JoinDefinition(type: 'right', table: 't', localColumn: 'a', foreignColumn: 'b'))->joinMethod());
    }

    // ─── Helpers ─────────────────────────────────────────────────

    private function simpleQuery(string $name): QueryDefinition
    {
        return QueryDefinition::fromArray([
            'name' => $name,
            'baseTable' => 'posts',
            'baseModel' => 'App\\Models\\Post',
        ]);
    }
}
