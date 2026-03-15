<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Generator\ActionCodeGenerator;
use SchemaCraft\Scanner\ActionDefinition;
use SchemaCraft\Scanner\ActionParameter;
use SchemaCraft\Scanner\NestedFieldDefinition;
use SchemaCraft\Scanner\NestedRelationshipParameter;
use SchemaCraft\Tests\Fixtures\Actions\Post\CreatePostAction;
use SchemaCraft\Tests\Fixtures\Actions\Post\DeletePostAction;
use SchemaCraft\Tests\Fixtures\Actions\Post\UpdatePostAction;
use SchemaCraft\Tests\Fixtures\Actions\Post\UpdatePostWithRelationsAction;

class ActionCodeGeneratorTest extends TestCase
{
    private ActionCodeGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new ActionCodeGenerator;
        CreatePostAction::clearScanCache();
        UpdatePostAction::clearScanCache();
        DeletePostAction::clearScanCache();
        UpdatePostWithRelationsAction::clearScanCache();
    }

    // ─── renderServiceMethod() for POST (create) ─────────────────

    public function test_create_action_renders_static_method(): void
    {
        $definition = CreatePostAction::definition();
        $result = $this->generator->renderServiceMethod($definition, 'Post');

        $this->assertStringContainsString('public static function createPost(', $result);
        $this->assertStringContainsString('): Post', $result);
        $this->assertStringContainsString('$post = new Post();', $result);
        $this->assertStringContainsString('$post->save();', $result);
        $this->assertStringContainsString('return $post;', $result);
    }

    public function test_create_action_has_scalar_assignments(): void
    {
        $definition = CreatePostAction::definition();
        $result = $this->generator->renderServiceMethod($definition, 'Post');

        $this->assertStringContainsString('$post->title = $title;', $result);
        $this->assertStringContainsString('$post->slug = $slug;', $result);
        $this->assertStringContainsString('$post->body = $body;', $result);
        $this->assertStringContainsString('$post->is_feature = $isFeature;', $result);
    }

    public function test_create_action_has_fk_associate(): void
    {
        $definition = CreatePostAction::definition();
        $result = $this->generator->renderServiceMethod($definition, 'Post');

        $this->assertStringContainsString('$post->author()->associate($author);', $result);
    }

    public function test_create_action_nullable_fk_uses_conditional_associate(): void
    {
        $definition = CreatePostAction::definition();
        $result = $this->generator->renderServiceMethod($definition, 'Post');

        $this->assertStringContainsString('if ($category !== null) {', $result);
        $this->assertStringContainsString('$post->category()->associate($category);', $result);
    }

    // ─── renderServiceMethod() for PUT (update) ──────────────────

    public function test_update_action_renders_instance_method(): void
    {
        $definition = UpdatePostAction::definition();
        $result = $this->generator->renderServiceMethod($definition, 'Post');

        $this->assertStringContainsString('public function updatePost(', $result);
        $this->assertStringContainsString('): Post', $result);
        $this->assertStringContainsString('$this->post->save();', $result);
        $this->assertStringContainsString('return $this->post;', $result);
    }

    public function test_update_action_uses_this_model(): void
    {
        $definition = UpdatePostAction::definition();
        $result = $this->generator->renderServiceMethod($definition, 'Post');

        $this->assertStringContainsString('$this->post->title = $title;', $result);
        $this->assertStringContainsString('$this->post->slug = $slug;', $result);
    }

    public function test_update_action_nullable_fk_has_dissociate(): void
    {
        $definition = UpdatePostAction::definition();
        $result = $this->generator->renderServiceMethod($definition, 'Post');

        $this->assertStringContainsString('if ($category !== null) {', $result);
        $this->assertStringContainsString('$this->post->category()->associate($category);', $result);
        $this->assertStringContainsString('} else {', $result);
        $this->assertStringContainsString('$this->post->category()->dissociate();', $result);
    }

    public function test_update_action_non_nullable_fk_uses_associate(): void
    {
        $definition = UpdatePostAction::definition();
        $result = $this->generator->renderServiceMethod($definition, 'Post');

        $this->assertStringContainsString('$this->post->author()->associate($author);', $result);
        $this->assertStringNotContainsString('$this->post->author()->dissociate();', $result);
    }

    // ─── renderServiceMethod() for DELETE ─────────────────────────

    public function test_delete_action_renders_void_method(): void
    {
        $definition = DeletePostAction::definition();
        $result = $this->generator->renderServiceMethod($definition, 'Post');

        $this->assertStringContainsString('public function deletePost(): void', $result);
        $this->assertStringContainsString('$this->post->delete();', $result);
    }

    // ─── renderServiceMethod() for GET ────────────────────────────

    public function test_get_action_renders_return_method(): void
    {
        $definition = new ActionDefinition(
            actionClass: 'App\\Actions\\GetPostAction',
            name: 'getPost',
            httpMethod: 'get',
            serviceMethod: 'getPost',
            schemaClass: 'App\\Schemas\\PostSchema',
        );

        $result = $this->generator->renderServiceMethod($definition, 'Post');

        $this->assertStringContainsString('public function getPost(): Post', $result);
        $this->assertStringContainsString('return $this->post;', $result);
    }

    // ─── buildMethodParams() ──────────────────────────────────────

    public function test_build_method_params_with_model_typed_fk(): void
    {
        $definition = CreatePostAction::definition();
        $result = $this->generator->buildMethodParams($definition);

        $this->assertStringContainsString('string $title', $result);
        $this->assertStringContainsString('User $author', $result);
        $this->assertStringContainsString('?Category $category = null', $result);
    }

    public function test_build_method_params_nullable_scalars(): void
    {
        $definition = CreatePostAction::definition();
        $result = $this->generator->buildMethodParams($definition);

        $this->assertStringContainsString('?string $body = null', $result);
    }

    public function test_build_method_params_empty_for_no_params(): void
    {
        $definition = DeletePostAction::definition();
        $result = $this->generator->buildMethodParams($definition);

        $this->assertSame('', $result);
    }

    public function test_build_method_params_multi_line_for_many_params(): void
    {
        $definition = CreatePostAction::definition();
        $result = $this->generator->buildMethodParams($definition);

        // CreatePostAction has 6 params, should be multi-line
        $this->assertStringContainsString("\n", $result);
    }

    public function test_build_method_params_single_line_for_few_params(): void
    {
        $definition = new ActionDefinition(
            actionClass: 'Test',
            name: 'test',
            serviceMethod: 'test',
            schemaClass: 'Test',
            parameters: [
                new ActionParameter(name: 'title', type: 'string'),
                new ActionParameter(name: 'slug', type: 'string'),
            ],
        );

        $result = $this->generator->buildMethodParams($definition);

        $this->assertStringNotContainsString("\n", $result);
        $this->assertSame('string $title, string $slug', $result);
    }

    // ─── buildRelatedModelImports() ───────────────────────────────

    public function test_related_model_imports(): void
    {
        $definition = CreatePostAction::definition();
        $result = $this->generator->buildRelatedModelImports($definition);

        $this->assertStringContainsString('use SchemaCraft\\Tests\\Fixtures\\Models\\User;', $result);
        $this->assertStringContainsString('use SchemaCraft\\Tests\\Fixtures\\Models\\Category;', $result);
    }

    public function test_related_model_imports_empty_for_no_fk(): void
    {
        $definition = DeletePostAction::definition();
        $result = $this->generator->buildRelatedModelImports($definition);

        $this->assertSame('', $result);
    }

    public function test_related_model_imports_deduplicates(): void
    {
        $definition = new ActionDefinition(
            actionClass: 'Test',
            name: 'test',
            serviceMethod: 'test',
            schemaClass: 'Test',
            parameters: [
                new ActionParameter(name: 'author', type: 'User', isModel: true, modelClass: 'App\\Models\\User', foreignKeyColumn: 'author_id', relationship: 'author'),
                new ActionParameter(name: 'reviewer', type: 'User', isModel: true, modelClass: 'App\\Models\\User', foreignKeyColumn: 'reviewer_id', relationship: 'reviewer'),
            ],
        );

        $result = $this->generator->buildRelatedModelImports($definition);

        $this->assertSame(1, substr_count($result, 'use App\\Models\\User;'));
    }

    // ─── buildUpdateAssignments() directly ────────────────────────

    public function test_update_assignments_scalar(): void
    {
        $definition = new ActionDefinition(
            actionClass: 'Test',
            name: 'test',
            serviceMethod: 'test',
            schemaClass: 'Test',
            parameters: [
                new ActionParameter(name: 'title', type: 'string', columnName: 'title'),
            ],
        );

        $result = $this->generator->buildUpdateAssignments($definition, 'post');

        $this->assertStringContainsString('$this->post->title = $title;', $result);
    }

    // ─── buildCreateAssignments() directly ────────────────────────

    public function test_create_assignments_scalar(): void
    {
        $definition = new ActionDefinition(
            actionClass: 'Test',
            name: 'test',
            serviceMethod: 'test',
            schemaClass: 'Test',
            parameters: [
                new ActionParameter(name: 'title', type: 'string', columnName: 'title'),
            ],
        );

        $result = $this->generator->buildCreateAssignments($definition, 'post');

        $this->assertStringContainsString('$post->title = $title;', $result);
    }

    // ─── Nested Relationship: buildMethodParams() ─────────────────

    public function test_nested_has_many_param_is_array_with_default(): void
    {
        $definition = $this->buildDefinitionWithNestedHasMany();
        $result = $this->generator->buildMethodParams($definition);

        $this->assertStringContainsString('array $comments = []', $result);
    }

    public function test_nested_nullable_singular_param_is_nullable_array(): void
    {
        $definition = $this->buildDefinitionWithNestedHasOne(nullable: true);
        $result = $this->generator->buildMethodParams($definition);

        $this->assertStringContainsString('?array $contact = null', $result);
    }

    public function test_nested_non_nullable_singular_param_is_array(): void
    {
        $definition = $this->buildDefinitionWithNestedHasOne(nullable: false);
        $result = $this->generator->buildMethodParams($definition);

        $this->assertStringContainsString('array $contact', $result);
        $this->assertStringNotContainsString('?array $contact', $result);
    }

    // ─── Nested Relationship: buildNestedRelationshipAssignments() ──

    public function test_nested_has_many_update_generates_create_many(): void
    {
        $definition = $this->buildDefinitionWithNestedHasMany();
        $result = $this->generator->buildNestedRelationshipAssignments($definition, 'post', 'update');

        $this->assertStringContainsString('if (! empty($comments)) {', $result);
        $this->assertStringContainsString('$this->post->comments()->createMany(', $result);
        $this->assertStringContainsString("collect(\$comments)->map(fn (\$item) => ['body' => \$item['body']])->all()", $result);
    }

    public function test_nested_has_many_create_generates_create_many(): void
    {
        $definition = $this->buildDefinitionWithNestedHasMany(httpMethod: 'post', serviceMethod: 'createPost');
        $result = $this->generator->buildNestedRelationshipAssignments($definition, 'post', 'create');

        $this->assertStringContainsString('if (! empty($comments)) {', $result);
        $this->assertStringContainsString('$post->comments()->createMany(', $result);
    }

    public function test_nested_has_many_sync_deletes_before_create(): void
    {
        $definition = $this->buildDefinitionWithNestedHasMany(sync: true);
        $result = $this->generator->buildNestedRelationshipAssignments($definition, 'post', 'update');

        $this->assertStringContainsString('$this->post->comments()->delete();', $result);
        $this->assertStringContainsString('$this->post->comments()->createMany(', $result);
    }

    public function test_nested_has_many_sync_not_on_create(): void
    {
        $definition = $this->buildDefinitionWithNestedHasMany(sync: true, httpMethod: 'post', serviceMethod: 'createPost');
        $result = $this->generator->buildNestedRelationshipAssignments($definition, 'post', 'create');

        $this->assertStringNotContainsString('->delete()', $result);
    }

    public function test_nested_has_one_update_generates_update_or_create(): void
    {
        $definition = $this->buildDefinitionWithNestedHasOne(nullable: true);
        $result = $this->generator->buildNestedRelationshipAssignments($definition, 'post', 'update');

        $this->assertStringContainsString('if ($contact !== null) {', $result);
        $this->assertStringContainsString('$this->post->contact()->updateOrCreate([], ', $result);
    }

    public function test_nested_has_one_create_generates_create(): void
    {
        $definition = $this->buildDefinitionWithNestedHasOne(nullable: false, httpMethod: 'post', serviceMethod: 'createPost');
        $result = $this->generator->buildNestedRelationshipAssignments($definition, 'post', 'create');

        $this->assertStringContainsString('$post->contact()->create(', $result);
        $this->assertStringNotContainsString('updateOrCreate', $result);
    }

    public function test_nested_belongs_to_many_generates_sync(): void
    {
        $definition = $this->buildDefinitionWithNestedBelongsToMany();
        $result = $this->generator->buildNestedRelationshipAssignments($definition, 'post', 'update');

        $this->assertStringContainsString('$this->post->tags()->sync(', $result);
        $this->assertStringContainsString("collect(\$tags)->pluck('id')->all()", $result);
    }

    public function test_nested_belongs_to_many_with_pivot_generates_mapped_sync(): void
    {
        $definition = $this->buildDefinitionWithNestedBelongsToMany(pivotFields: ['sort_order']);
        $result = $this->generator->buildNestedRelationshipAssignments($definition, 'post', 'update');

        $this->assertStringContainsString('$this->post->tags()->sync(', $result);
        $this->assertStringContainsString("mapWithKeys(fn (\$item) => [\$item['id'] => ['sort_order' => \$item['sort_order'] ?? null]])->all()", $result);
    }

    public function test_nested_skipped_in_flat_assignments(): void
    {
        $definition = $this->buildDefinitionWithNestedHasMany();
        $updateResult = $this->generator->buildUpdateAssignments($definition, 'post');
        $createResult = $this->generator->buildCreateAssignments($definition, 'post');

        $this->assertStringContainsString('$this->post->title = $title;', $updateResult);
        $this->assertStringNotContainsString('comments', $updateResult);

        $this->assertStringContainsString('$post->title = $title;', $createResult);
        $this->assertStringNotContainsString('comments', $createResult);
    }

    // ─── Nested Relationship: renderServiceMethod() integration ───

    public function test_update_with_nested_has_code_after_save(): void
    {
        $definition = $this->buildDefinitionWithNestedHasMany();
        $result = $this->generator->renderServiceMethod($definition, 'Post');

        // Save happens before nested
        $savePos = strpos($result, '$this->post->save()');
        $nestedPos = strpos($result, '$this->post->comments()->createMany(');
        $this->assertNotFalse($savePos);
        $this->assertNotFalse($nestedPos);
        $this->assertGreaterThan($savePos, $nestedPos);
    }

    public function test_create_with_nested_has_code_after_save(): void
    {
        $definition = $this->buildDefinitionWithNestedHasMany(httpMethod: 'post', serviceMethod: 'createPost');
        $result = $this->generator->renderServiceMethod($definition, 'Post');

        $savePos = strpos($result, '$post->save()');
        $nestedPos = strpos($result, '$post->comments()->createMany(');
        $this->assertNotFalse($savePos);
        $this->assertNotFalse($nestedPos);
        $this->assertGreaterThan($savePos, $nestedPos);
    }

    public function test_scanned_nested_action_renders_service_method(): void
    {
        $definition = UpdatePostWithRelationsAction::definition();
        $result = $this->generator->renderServiceMethod($definition, 'Post');

        // Has flat assignments
        $this->assertStringContainsString('$this->post->title = $title;', $result);
        $this->assertStringContainsString('$this->post->author()->associate($author);', $result);

        // Has nested after save
        $this->assertStringContainsString('$this->post->comments()->createMany(', $result);
        $this->assertStringContainsString('$this->post->tags()->sync(', $result);
    }

    // ─── Helpers ──────────────────────────────────────────────────

    private function buildDefinitionWithNestedHasMany(
        bool $sync = false,
        string $httpMethod = 'put',
        string $serviceMethod = 'updatePost',
    ): ActionDefinition {
        return new ActionDefinition(
            actionClass: 'Test\\UpdatePostAction',
            name: 'updatePost',
            httpMethod: $httpMethod,
            serviceMethod: $serviceMethod,
            schemaClass: 'Test\\PostSchema',
            parameters: [
                new ActionParameter(name: 'title', type: 'string', columnName: 'title'),
                new ActionParameter(
                    name: 'comments',
                    type: 'array',
                    isNestedRelationship: true,
                    nestedRelationship: new NestedRelationshipParameter(
                        name: 'comments',
                        relationshipType: 'hasMany',
                        relatedModel: 'App\\Models\\Comment',
                        isCollection: true,
                        fields: [
                            new NestedFieldDefinition(name: 'body', dotPath: 'comments.body', type: 'string'),
                        ],
                        sync: $sync,
                    ),
                ),
            ],
        );
    }

    private function buildDefinitionWithNestedHasOne(
        bool $nullable = true,
        string $httpMethod = 'put',
        string $serviceMethod = 'updatePost',
    ): ActionDefinition {
        return new ActionDefinition(
            actionClass: 'Test\\UpdatePostAction',
            name: 'updatePost',
            httpMethod: $httpMethod,
            serviceMethod: $serviceMethod,
            schemaClass: 'Test\\PostSchema',
            parameters: [
                new ActionParameter(
                    name: 'contact',
                    type: 'array',
                    nullable: $nullable,
                    isNestedRelationship: true,
                    nestedRelationship: new NestedRelationshipParameter(
                        name: 'contact',
                        relationshipType: 'hasOne',
                        relatedModel: 'App\\Models\\Contact',
                        nullable: $nullable,
                        fields: [
                            new NestedFieldDefinition(name: 'first_name', dotPath: 'contact.first_name', type: 'string'),
                            new NestedFieldDefinition(name: 'last_name', dotPath: 'contact.last_name', type: 'string'),
                        ],
                    ),
                ),
            ],
        );
    }

    private function buildDefinitionWithNestedBelongsToMany(array $pivotFields = []): ActionDefinition
    {
        return new ActionDefinition(
            actionClass: 'Test\\UpdatePostAction',
            name: 'updatePost',
            httpMethod: 'put',
            serviceMethod: 'updatePost',
            schemaClass: 'Test\\PostSchema',
            parameters: [
                new ActionParameter(
                    name: 'tags',
                    type: 'array',
                    isNestedRelationship: true,
                    nestedRelationship: new NestedRelationshipParameter(
                        name: 'tags',
                        relationshipType: 'belongsToMany',
                        relatedModel: 'App\\Models\\Tag',
                        isCollection: true,
                        fields: [
                            new NestedFieldDefinition(name: 'name', dotPath: 'tags.name', type: 'string'),
                        ],
                        pivotFields: $pivotFields,
                    ),
                ),
            ],
        );
    }
}
