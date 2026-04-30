<?php

namespace SchemaCraft\Tests\Unit\Generators;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Generators\GeneratorRelationship;
use SchemaCraft\Generators\NameChain;
use SchemaCraft\Scanner\RelationshipDefinition;
use SchemaCraft\Tests\Fixtures\Models\TitleProperty;
use SchemaCraft\Tests\Fixtures\Models\User;

class GeneratorRelationshipTest extends TestCase
{
    // ─── Name chains ──────────────────────────────────────────────

    public function test_name_is_name_chain(): void
    {
        $rel = new GeneratorRelationship(new RelationshipDefinition(
            name: 'author',
            type: 'belongsTo',
            relatedModel: 'App\\Models\\User',
        ));

        $this->assertInstanceOf(NameChain::class, $rel->name);
        $this->assertSame('author', (string) $rel->name);
        $this->assertSame('Author', (string) $rel->name->title);
    }

    public function test_related_model_is_name_chain(): void
    {
        $rel = new GeneratorRelationship(new RelationshipDefinition(
            name: 'author',
            type: 'belongsTo',
            relatedModel: 'App\\Models\\User',
        ));

        $this->assertInstanceOf(NameChain::class, $rel->relatedModel);
        $this->assertSame('user', (string) $rel->relatedModel);
        $this->assertSame('User', (string) $rel->relatedModel->title);
        $this->assertSame('Users', (string) $rel->relatedModel->plural->title);
    }

    public function test_compound_related_model_name(): void
    {
        $rel = new GeneratorRelationship(new RelationshipDefinition(
            name: 'userProfiles',
            type: 'hasMany',
            relatedModel: 'App\\Models\\UserProfile',
        ));

        $this->assertSame('user_profile', (string) $rel->relatedModel);
        $this->assertSame('UserProfile', (string) $rel->relatedModel->title);
        $this->assertSame('UserProfiles', (string) $rel->relatedModel->plural->title);
    }

    public function test_related_model_class_is_full_fqcn(): void
    {
        $rel = new GeneratorRelationship(new RelationshipDefinition(
            name: 'author',
            type: 'belongsTo',
            relatedModel: 'App\\Models\\User',
        ));

        $this->assertSame('App\\Models\\User', $rel->relatedModelClass);
    }

    public function test_related_model_class_preserves_nested_namespace(): void
    {
        $rel = new GeneratorRelationship(new RelationshipDefinition(
            name: 'profile',
            type: 'hasOne',
            relatedModel: 'App\\Models\\Users\\Profile',
        ));

        $this->assertSame('App\\Models\\Users\\Profile', $rel->relatedModelClass);
        $this->assertSame('Profile', (string) $rel->relatedModel->title);
    }

    // ─── Cardinality ──────────────────────────────────────────────

    public function test_belongs_to_is_singular(): void
    {
        $rel = new GeneratorRelationship(new RelationshipDefinition(
            name: 'author',
            type: 'belongsTo',
            relatedModel: 'App\\Models\\User',
        ));

        $this->assertTrue($rel->isSingular());
        $this->assertFalse($rel->isCollection());
    }

    public function test_has_one_is_singular(): void
    {
        $rel = new GeneratorRelationship(new RelationshipDefinition(
            name: 'profile',
            type: 'hasOne',
            relatedModel: 'App\\Models\\Profile',
        ));

        $this->assertTrue($rel->isSingular());
        $this->assertFalse($rel->isCollection());
    }

    public function test_morph_to_is_singular(): void
    {
        $rel = new GeneratorRelationship(new RelationshipDefinition(
            name: 'commentable',
            type: 'morphTo',
            relatedModel: 'App\\Models\\Post',
        ));

        $this->assertTrue($rel->isSingular());
    }

    public function test_morph_one_is_singular(): void
    {
        $rel = new GeneratorRelationship(new RelationshipDefinition(
            name: 'image',
            type: 'morphOne',
            relatedModel: 'App\\Models\\Image',
        ));

        $this->assertTrue($rel->isSingular());
    }

    public function test_has_one_through_is_singular(): void
    {
        $rel = new GeneratorRelationship(new RelationshipDefinition(
            name: 'ownerPhone',
            type: 'hasOneThrough',
            relatedModel: 'App\\Models\\Phone',
        ));

        $this->assertTrue($rel->isSingular());
    }

    public function test_has_many_is_collection(): void
    {
        $rel = new GeneratorRelationship(new RelationshipDefinition(
            name: 'posts',
            type: 'hasMany',
            relatedModel: 'App\\Models\\Post',
        ));

        $this->assertTrue($rel->isCollection());
        $this->assertFalse($rel->isSingular());
    }

    public function test_belongs_to_many_is_collection(): void
    {
        $rel = new GeneratorRelationship(new RelationshipDefinition(
            name: 'tags',
            type: 'belongsToMany',
            relatedModel: 'App\\Models\\Tag',
        ));

        $this->assertTrue($rel->isCollection());
    }

    public function test_morph_many_is_collection(): void
    {
        $rel = new GeneratorRelationship(new RelationshipDefinition(
            name: 'comments',
            type: 'morphMany',
            relatedModel: 'App\\Models\\Comment',
        ));

        $this->assertTrue($rel->isCollection());
    }

    public function test_morph_to_many_is_collection(): void
    {
        $rel = new GeneratorRelationship(new RelationshipDefinition(
            name: 'tags',
            type: 'morphToMany',
            relatedModel: 'App\\Models\\Tag',
        ));

        $this->assertTrue($rel->isCollection());
    }

    public function test_morphed_by_many_is_collection(): void
    {
        $rel = new GeneratorRelationship(new RelationshipDefinition(
            name: 'posts',
            type: 'morphedByMany',
            relatedModel: 'App\\Models\\Post',
        ));

        $this->assertTrue($rel->isCollection());
    }

    public function test_has_many_through_is_collection(): void
    {
        $rel = new GeneratorRelationship(new RelationshipDefinition(
            name: 'deployments',
            type: 'hasManyThrough',
            relatedModel: 'App\\Models\\Deployment',
        ));

        $this->assertTrue($rel->isCollection());
    }

    // ─── Delegation ───────────────────────────────────────────────

    public function test_delegates_type_to_definition(): void
    {
        $rel = new GeneratorRelationship(new RelationshipDefinition(
            name: 'author',
            type: 'belongsTo',
            relatedModel: 'App\\Models\\User',
        ));

        $this->assertSame('belongsTo', $rel->type);
    }

    public function test_delegates_nullable_to_definition(): void
    {
        $rel = new GeneratorRelationship(new RelationshipDefinition(
            name: 'category',
            type: 'belongsTo',
            relatedModel: 'App\\Models\\Category',
            nullable: true,
        ));

        $this->assertTrue($rel->nullable);
    }

    public function test_delegates_pivot_table_to_definition(): void
    {
        $rel = new GeneratorRelationship(new RelationshipDefinition(
            name: 'tags',
            type: 'belongsToMany',
            relatedModel: 'App\\Models\\Tag',
            pivotTable: 'post_tag',
        ));

        $this->assertSame('post_tag', $rel->pivotTable);
    }

    public function test_delegates_morph_name_to_definition(): void
    {
        $rel = new GeneratorRelationship(new RelationshipDefinition(
            name: 'commentable',
            type: 'morphTo',
            relatedModel: 'App\\Models\\Post',
            morphName: 'commentable',
        ));

        $this->assertSame('commentable', $rel->morphName);
    }

    public function test_isset_delegates_to_definition(): void
    {
        $rel = new GeneratorRelationship(new RelationshipDefinition(
            name: 'author',
            type: 'belongsTo',
            relatedModel: 'App\\Models\\User',
            pivotTable: null,
        ));

        $this->assertTrue(isset($rel->type));
    }

    // ─── relatedTitleColumn() ─────────────────────────────────────

    public function test_related_title_column_returns_title_when_schema_has_title_attribute(): void
    {
        $rel = new GeneratorRelationship(new RelationshipDefinition(
            name: 'titleProperty',
            type: 'belongsTo',
            relatedModel: TitleProperty::class,
        ));

        // TitlePropertySchema is annotated with #[Title('company_name')]
        $this->assertSame('company_name', $rel->relatedTitleColumn());
    }

    public function test_related_title_column_returns_null_when_schema_has_no_title_attribute(): void
    {
        $rel = new GeneratorRelationship(new RelationshipDefinition(
            name: 'author',
            type: 'belongsTo',
            relatedModel: User::class,
        ));

        // UserSchema has no #[Title] attribute
        $this->assertNull($rel->relatedTitleColumn());
    }

    public function test_related_title_column_returns_null_when_no_schema_found(): void
    {
        $rel = new GeneratorRelationship(new RelationshipDefinition(
            name: 'external',
            type: 'belongsTo',
            relatedModel: 'App\\External\\NoSchemaModel',
        ));

        $this->assertNull($rel->relatedTitleColumn());
    }
}
