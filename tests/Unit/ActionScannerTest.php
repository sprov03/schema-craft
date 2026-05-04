<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Attributes\Rules;
use SchemaCraft\Scanner\ActionScanner;
use SchemaCraft\Tests\Fixtures\Actions\Comment\CreateCommentAction;
use SchemaCraft\Tests\Fixtures\Actions\Comment\CreateNullableCommentAction;
use SchemaCraft\Tests\Fixtures\Actions\Post\AnnotatedPostAction;
use SchemaCraft\Tests\Fixtures\Actions\Post\CreatePostAction;
use SchemaCraft\Tests\Fixtures\Actions\Post\DeletePostAction;
use SchemaCraft\Tests\Fixtures\Actions\Post\UpdatePostAction;
use SchemaCraft\Tests\Fixtures\Actions\Post\UpdatePostWithDataSchemaAction;
use SchemaCraft\Tests\Fixtures\Models\Category;
use SchemaCraft\Tests\Fixtures\Models\Comment;
use SchemaCraft\Tests\Fixtures\Models\User;
use SchemaCraft\Tests\Fixtures\Schemas\PostSchema;

class ActionScannerTest extends TestCase
{
    public function test_scan_discovers_action_name_from_class(): void
    {
        $scanner = new ActionScanner(CreatePostAction::class);
        $definition = $scanner->scan();

        $this->assertSame('createPost', $definition->name);
    }

    public function test_scan_reads_action_meta_attribute(): void
    {
        $scanner = new ActionScanner(CreatePostAction::class);
        $definition = $scanner->scan();

        $this->assertSame('post', $definition->httpMethod);
        $this->assertSame('Create Post', $definition->label);
    }

    public function test_scan_reads_schema_class(): void
    {
        $scanner = new ActionScanner(CreatePostAction::class);
        $definition = $scanner->scan();

        $this->assertSame(PostSchema::class, $definition->schemaClass);
    }

    public function test_scan_reads_service_method(): void
    {
        $scanner = new ActionScanner(CreatePostAction::class);
        $definition = $scanner->scan();

        $this->assertSame('createPost', $definition->serviceMethod);
    }

    public function test_scan_discovers_scalar_properties(): void
    {
        $scanner = new ActionScanner(CreatePostAction::class);
        $definition = $scanner->scan();

        $titleParam = $this->findParam($definition->parameters, 'title');

        $this->assertNotNull($titleParam);
        $this->assertSame('string', $titleParam->type);
        $this->assertFalse($titleParam->nullable);
        $this->assertFalse($titleParam->isModel);
        $this->assertSame('title', $titleParam->columnName);
    }

    public function test_scan_discovers_nullable_scalar_properties(): void
    {
        $scanner = new ActionScanner(CreatePostAction::class);
        $definition = $scanner->scan();

        $bodyParam = $this->findParam($definition->parameters, 'body');

        $this->assertNotNull($bodyParam);
        $this->assertSame('string', $bodyParam->type);
        $this->assertTrue($bodyParam->nullable);
        $this->assertFalse($bodyParam->isModel);
    }

    public function test_scan_discovers_scalar_with_default(): void
    {
        $scanner = new ActionScanner(CreatePostAction::class);
        $definition = $scanner->scan();

        $featuredParam = $this->findParam($definition->parameters, 'isFeature');

        $this->assertNotNull($featuredParam);
        $this->assertSame('bool', $featuredParam->type);
        $this->assertTrue($featuredParam->hasDefault);
        $this->assertFalse($featuredParam->default);
    }

    public function test_scan_discovers_belongs_to_fk_properties(): void
    {
        $scanner = new ActionScanner(CreatePostAction::class);
        $definition = $scanner->scan();

        $authorParam = $this->findParam($definition->parameters, 'author');

        $this->assertNotNull($authorParam);
        $this->assertTrue($authorParam->isModel);
        $this->assertSame(User::class, $authorParam->modelClass);
        $this->assertSame('User', $authorParam->type);
        $this->assertFalse($authorParam->nullable);
        $this->assertSame('author_id', $authorParam->foreignKeyColumn);
        $this->assertSame('author', $authorParam->relationship);
    }

    public function test_scan_discovers_nullable_belongs_to(): void
    {
        $scanner = new ActionScanner(CreatePostAction::class);
        $definition = $scanner->scan();

        $categoryParam = $this->findParam($definition->parameters, 'category');

        $this->assertNotNull($categoryParam);
        $this->assertTrue($categoryParam->isModel);
        $this->assertSame(Category::class, $categoryParam->modelClass);
        $this->assertTrue($categoryParam->nullable);
        $this->assertSame('category_id', $categoryParam->foreignKeyColumn);
    }

    public function test_scan_counts_all_parameters(): void
    {
        $scanner = new ActionScanner(CreatePostAction::class);
        $definition = $scanner->scan();

        // title, slug, body, isFeature, author, category = 6
        $this->assertCount(6, $definition->parameters);
    }

    public function test_scan_action_with_no_properties(): void
    {
        $scanner = new ActionScanner(DeletePostAction::class);
        $definition = $scanner->scan();

        $this->assertSame('deletePost', $definition->name);
        $this->assertSame('delete', $definition->httpMethod);
        $this->assertSame('Delete Post', $definition->label);
        $this->assertCount(0, $definition->parameters);
    }

    public function test_scan_update_action(): void
    {
        $scanner = new ActionScanner(UpdatePostAction::class);
        $definition = $scanner->scan();

        $this->assertSame('updatePost', $definition->name);
        $this->assertSame('put', $definition->httpMethod);
        $this->assertSame('Update Post', $definition->label);
        $this->assertCount(5, $definition->parameters); // title, slug, body, author, category
    }

    public function test_scalar_column_name_is_snake_case(): void
    {
        $scanner = new ActionScanner(CreatePostAction::class);
        $definition = $scanner->scan();

        $featureParam = $this->findParam($definition->parameters, 'isFeature');

        $this->assertSame('is_feature', $featureParam->columnName);
    }

    public function test_fk_column_name_uses_snake_case_id(): void
    {
        $scanner = new ActionScanner(CreatePostAction::class);
        $definition = $scanner->scan();

        $authorParam = $this->findParam($definition->parameters, 'author');

        $this->assertSame('author_id', $authorParam->foreignKeyColumn);
    }

    // --- Nested Relationship Scanning Tests ---

    public function test_resolve_related_schema_class(): void
    {
        $result = ActionScanner::resolveRelatedSchemaClass(Comment::class);

        $this->assertNotNull($result);
        $this->assertSame(\SchemaCraft\Tests\Fixtures\Schemas\CommentSchema::class, $result);
    }

    public function test_resolve_related_schema_class_returns_null_for_unknown(): void
    {
        $result = ActionScanner::resolveRelatedSchemaClass('App\\Models\\NonexistentModel');

        $this->assertNull($result);
    }

    // --- DataSchema-based Scanning Tests ---

    public function test_scan_discovers_has_many_with_action_schema(): void
    {
        $scanner = new ActionScanner(UpdatePostWithDataSchemaAction::class);
        $definition = $scanner->scan();

        $commentsParam = $this->findParam($definition->parameters, 'comments');

        $this->assertNotNull($commentsParam);
        $this->assertTrue($commentsParam->isNestedRelationship);
        $this->assertFalse($commentsParam->isModel);
        $this->assertNotNull($commentsParam->nestedRelationship);
        $this->assertSame('hasMany', $commentsParam->nestedRelationship->relationshipType);
        $this->assertTrue($commentsParam->nestedRelationship->isCollection);
    }

    public function test_scan_action_schema_resolves_fields_from_properties(): void
    {
        $scanner = new ActionScanner(UpdatePostWithDataSchemaAction::class);
        $definition = $scanner->scan();

        $commentsParam = $this->findParam($definition->parameters, 'comments');
        $nested = $commentsParam->nestedRelationship;

        $this->assertCount(1, $nested->fields);
        $this->assertSame('body', $nested->fields[0]->name);
        $this->assertSame('comments.body', $nested->fields[0]->dotPath);
        $this->assertSame('string', $nested->fields[0]->type);
    }

    public function test_scan_action_schema_stores_class_reference(): void
    {
        $scanner = new ActionScanner(UpdatePostWithDataSchemaAction::class);
        $definition = $scanner->scan();

        $commentsParam = $this->findParam($definition->parameters, 'comments');

        $this->assertSame(
            \SchemaCraft\Tests\Fixtures\Actions\Post\UpdatePostCommentsDataSchema::class,
            $commentsParam->nestedRelationship->dataSchemaClass,
        );
    }

    public function test_scan_action_schema_resolves_related_model_from_schema(): void
    {
        $scanner = new ActionScanner(UpdatePostWithDataSchemaAction::class);
        $definition = $scanner->scan();

        $commentsParam = $this->findParam($definition->parameters, 'comments');

        $this->assertSame(Comment::class, $commentsParam->nestedRelationship->relatedModel);
    }

    public function test_scan_action_schema_detects_fields(): void
    {
        $scanner = new ActionScanner(UpdatePostWithDataSchemaAction::class);
        $definition = $scanner->scan();

        $tagsParam = $this->findParam($definition->parameters, 'tags');
        $nested = $tagsParam->nestedRelationship;

        // id, name, and sortOrder are all regular fields now (#[Pivot] is removed)
        $this->assertCount(3, $nested->fields);
        $this->assertSame('id', $nested->fields[0]->name);
        $this->assertSame('name', $nested->fields[1]->name);
        $this->assertSame('sort_order', $nested->fields[2]->name);

        $this->assertCount(0, $nested->pivotFields);
    }

    public function test_scan_action_schema_belongs_to_many(): void
    {
        $scanner = new ActionScanner(UpdatePostWithDataSchemaAction::class);
        $definition = $scanner->scan();

        $tagsParam = $this->findParam($definition->parameters, 'tags');

        $this->assertNotNull($tagsParam);
        $this->assertTrue($tagsParam->isNestedRelationship);
        $this->assertSame('belongsToMany', $tagsParam->nestedRelationship->relationshipType);
        $this->assertTrue($tagsParam->nestedRelationship->isCollection);
    }

    public function test_scan_action_schema_counts_all_parameters(): void
    {
        $scanner = new ActionScanner(UpdatePostWithDataSchemaAction::class);
        $definition = $scanner->scan();

        // title, author, comments (nested), tags (nested) = 4
        $this->assertCount(4, $definition->parameters);
    }

    // --- MorphTo Scanning Tests ---

    public function test_morph_to_produces_two_parameters(): void
    {
        $scanner = new ActionScanner(CreateCommentAction::class);
        $definition = $scanner->scan();

        $morphParams = $this->findMorphParams($definition->parameters, 'commentable');

        $this->assertCount(2, $morphParams);
    }

    public function test_morph_to_type_parameter(): void
    {
        $scanner = new ActionScanner(CreateCommentAction::class);
        $definition = $scanner->scan();

        $morphParams = $this->findMorphParams($definition->parameters, 'commentable');
        $typeParam = $this->findMorphTypeParam($morphParams);

        $this->assertNotNull($typeParam);
        $this->assertSame('string', $typeParam->type);
        $this->assertSame('commentable_type', $typeParam->columnName);
        $this->assertTrue($typeParam->isMorphType);
        $this->assertSame('commentable', $typeParam->morphPairName);
        $this->assertFalse($typeParam->isModel);
        $this->assertFalse($typeParam->nullable);
    }

    public function test_morph_to_id_parameter(): void
    {
        $scanner = new ActionScanner(CreateCommentAction::class);
        $definition = $scanner->scan();

        $morphParams = $this->findMorphParams($definition->parameters, 'commentable');
        $idParam = $this->findMorphIdParam($morphParams);

        $this->assertNotNull($idParam);
        $this->assertSame('integer', $idParam->type);
        $this->assertSame('commentable_id', $idParam->columnName);
        $this->assertFalse($idParam->isMorphType);
        $this->assertSame('commentable', $idParam->morphPairName);
        $this->assertFalse($idParam->isModel);
        $this->assertFalse($idParam->nullable);
    }

    public function test_nullable_morph_to_propagates_nullable_to_both_params(): void
    {
        $scanner = new ActionScanner(CreateNullableCommentAction::class);
        $definition = $scanner->scan();

        $morphParams = $this->findMorphParams($definition->parameters, 'commentable');

        $this->assertCount(2, $morphParams);
        $this->assertTrue($morphParams[0]->nullable);
        $this->assertTrue($morphParams[1]->nullable);
    }

    public function test_morph_to_total_parameter_count(): void
    {
        $scanner = new ActionScanner(CreateCommentAction::class);
        $definition = $scanner->scan();

        // body (scalar) + user (BelongsTo) + commentable_type + commentable_id = 4
        $this->assertCount(4, $definition->parameters);
    }

    /**
     * @return \SchemaCraft\Scanner\ActionParameter[]
     */
    private function findMorphParams(array $parameters, string $morphPairName): array
    {
        return array_values(array_filter(
            $parameters,
            fn ($p) => $p->morphPairName === $morphPairName,
        ));
    }

    private function findMorphTypeParam(array $morphParams): ?\SchemaCraft\Scanner\ActionParameter
    {
        foreach ($morphParams as $p) {
            if ($p->isMorphType) {
                return $p;
            }
        }

        return null;
    }

    private function findMorphIdParam(array $morphParams): ?\SchemaCraft\Scanner\ActionParameter
    {
        foreach ($morphParams as $p) {
            if (! $p->isMorphType) {
                return $p;
            }
        }

        return null;
    }

    // ─── Column-shaping attribute tests ──────────────────────────────────────

    public function test_length_attribute_is_read_from_action_property(): void
    {
        $definition = (new ActionScanner(AnnotatedPostAction::class))->scan();
        $param = $this->findParam($definition->parameters, 'slug');

        $this->assertNotNull($param);
        $this->assertSame(50, $param->length);
    }

    public function test_medium_text_attribute_sets_column_type(): void
    {
        $definition = (new ActionScanner(AnnotatedPostAction::class))->scan();
        $param = $this->findParam($definition->parameters, 'summary');

        $this->assertNotNull($param);
        $this->assertSame('mediumText', $param->columnType);
    }

    public function test_unique_attribute_is_read_from_action_property(): void
    {
        $definition = (new ActionScanner(AnnotatedPostAction::class))->scan();
        $param = $this->findParam($definition->parameters, 'externalId');

        $this->assertNotNull($param);
        $this->assertTrue($param->unique);
    }

    public function test_small_int_attribute_sets_column_type(): void
    {
        $definition = (new ActionScanner(AnnotatedPostAction::class))->scan();
        $param = $this->findParam($definition->parameters, 'priority');

        $this->assertNotNull($param);
        $this->assertSame('smallInteger', $param->columnType);
    }

    public function test_rules_attribute_is_stored_in_attributes_array(): void
    {
        $definition = (new ActionScanner(AnnotatedPostAction::class))->scan();
        $param = $this->findParam($definition->parameters, 'title');

        $this->assertNotNull($param);
        $rulesAttr = null;
        foreach ($param->attributes as $attr) {
            if ($attr instanceof Rules) {
                $rulesAttr = $attr;
                break;
            }
        }

        $this->assertNotNull($rulesAttr);
        $this->assertContains('min:3', $rulesAttr->rules);
    }

    public function test_unannotated_property_has_empty_attributes(): void
    {
        $definition = (new ActionScanner(CreatePostAction::class))->scan();
        $param = $this->findParam($definition->parameters, 'title');

        $this->assertNotNull($param);
        $this->assertSame([], $param->attributes);
        $this->assertNull($param->length);
        $this->assertFalse($param->unique);
    }

    private function findParam(array $parameters, string $name): ?\SchemaCraft\Scanner\ActionParameter
    {
        foreach ($parameters as $param) {
            if ($param->name === $name) {
                return $param;
            }
        }

        return null;
    }
}
