<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Generator\ModelTestGenerator;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Scanner\RelationshipDefinition;
use SchemaCraft\Scanner\TableDefinition;

class ModelTestGeneratorTest extends TestCase
{
    private ModelTestGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new ModelTestGenerator;
    }

    // ─── Basic structure ─────────────────────────────────────────

    public function test_generates_correct_namespace_and_class(): void
    {
        $table = $this->simpleTable();

        $output = $this->generator->generate($table, 'Post');

        $this->assertStringContainsString('namespace Tests\Unit;', $output);
        $this->assertStringContainsString('class PostModelTest extends TestCase', $output);
    }

    public function test_uses_refresh_database_trait(): void
    {
        $table = $this->simpleTable();

        $output = $this->generator->generate($table, 'Post');

        $this->assertStringContainsString('use RefreshDatabase;', $output);
        $this->assertStringContainsString('use Illuminate\Foundation\Testing\RefreshDatabase;', $output);
    }

    public function test_imports_model_and_factory(): void
    {
        $table = $this->simpleTable();

        $output = $this->generator->generate($table, 'Post');

        $this->assertStringContainsString('use App\Models\Post;', $output);
        $this->assertStringContainsString('use Database\Factories\PostFactory;', $output);
        $this->assertStringContainsString('use Tests\TestCase;', $output);
    }

    // ─── Can create model test ───────────────────────────────────

    public function test_generates_can_create_model_test(): void
    {
        $table = $this->simpleTable();

        $output = $this->generator->generate($table, 'Post');

        $this->assertStringContainsString('public function test_can_create_model(): void', $output);
        $this->assertStringContainsString('$post = PostFactory::createDefault();', $output);
        $this->assertStringContainsString('$this->assertInstanceOf(Post::class, $post);', $output);
        $this->assertStringContainsString('$this->assertTrue($post->exists);', $output);
    }

    // ─── BelongsTo relationship tests ────────────────────────────

    public function test_generates_belongs_to_relationship_test(): void
    {
        $table = $this->tableWithBelongsTo();

        $output = $this->generator->generate($table, 'Post');

        $this->assertStringContainsString('public function test_author_relationship_returns_correct_model(): void', $output);
        $this->assertStringContainsString('$this->assertInstanceOf(User::class, $post->author);', $output);
    }

    public function test_imports_related_models(): void
    {
        $table = $this->tableWithBelongsTo();

        $output = $this->generator->generate($table, 'Post');

        $this->assertStringContainsString('use App\Models\User;', $output);
    }

    public function test_nullable_belongs_to_generates_nullable_assertion(): void
    {
        $table = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'bigInteger', primary: true, autoIncrement: true),
                new ColumnDefinition(name: 'reviewer_id', columnType: 'unsignedBigInteger', nullable: true),
            ],
            relationships: [
                new RelationshipDefinition(
                    name: 'reviewer',
                    type: 'belongsTo',
                    relatedModel: 'App\\Models\\User',
                    foreignColumn: 'reviewer_id',
                    nullable: true,
                ),
            ],
        );

        $output = $this->generator->generate($table, 'Post');

        $this->assertStringContainsString('test_reviewer_relationship_returns_correct_model_or_null', $output);
        $this->assertStringContainsString('$post->reviewer === null || $post->reviewer instanceof User', $output);
    }

    public function test_multiple_belongs_to_generates_multiple_tests(): void
    {
        $table = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'bigInteger', primary: true, autoIncrement: true),
                new ColumnDefinition(name: 'author_id', columnType: 'unsignedBigInteger'),
                new ColumnDefinition(name: 'category_id', columnType: 'unsignedBigInteger'),
            ],
            relationships: [
                new RelationshipDefinition(
                    name: 'author',
                    type: 'belongsTo',
                    relatedModel: 'App\\Models\\User',
                    foreignColumn: 'author_id',
                ),
                new RelationshipDefinition(
                    name: 'category',
                    type: 'belongsTo',
                    relatedModel: 'App\\Models\\Category',
                    foreignColumn: 'category_id',
                ),
            ],
        );

        $output = $this->generator->generate($table, 'Post');

        $this->assertStringContainsString('test_author_relationship_returns_correct_model', $output);
        $this->assertStringContainsString('test_category_relationship_returns_correct_model', $output);
        $this->assertStringContainsString('use App\Models\User;', $output);
        $this->assertStringContainsString('use App\Models\Category;', $output);
    }

    // ─── No relationships ────────────────────────────────────────

    public function test_no_relationships_still_generates_valid_test(): void
    {
        $table = $this->simpleTable();

        $output = $this->generator->generate($table, 'Post');

        // Should still have the can_create_model test
        $this->assertStringContainsString('test_can_create_model', $output);
        // Should NOT have any relationship tests
        $this->assertStringNotContainsString('test_author', $output);
    }

    // ─── HasMany/BelongsToMany ignored ───────────────────────────

    public function test_has_many_does_not_generate_test(): void
    {
        $table = new TableDefinition(
            tableName: 'users',
            schemaClass: 'App\\Schemas\\UserSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'bigInteger', primary: true, autoIncrement: true),
                new ColumnDefinition(name: 'name', columnType: 'string'),
            ],
            relationships: [
                new RelationshipDefinition(
                    name: 'posts',
                    type: 'hasMany',
                    relatedModel: 'App\\Models\\Post',
                ),
            ],
        );

        $output = $this->generator->generate($table, 'User');

        $this->assertStringNotContainsString('test_posts_relationship', $output);
        $this->assertStringNotContainsString('PostFactory', $output);
    }

    // ─── Valid PHP ───────────────────────────────────────────────

    public function test_output_is_valid_php(): void
    {
        $table = $this->tableWithBelongsTo();

        $output = $this->generator->generate($table, 'Post');

        $tmpFile = tempnam(sys_get_temp_dir(), 'model_test_').'.php';
        file_put_contents($tmpFile, $output);

        exec("php -l {$tmpFile} 2>&1", $lintOutput, $exitCode);
        unlink($tmpFile);

        $this->assertEquals(0, $exitCode, "Generated model test has syntax errors:\n".implode("\n", $lintOutput));
    }

    // ─── Model variable casing ───────────────────────────────────

    public function test_model_variable_uses_camel_case(): void
    {
        $table = new TableDefinition(
            tableName: 'blog_posts',
            schemaClass: 'App\\Schemas\\BlogPostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'bigInteger', primary: true, autoIncrement: true),
            ],
        );

        $output = $this->generator->generate($table, 'BlogPost');

        $this->assertStringContainsString('$blogPost = BlogPostFactory::createDefault();', $output);
    }

    // ─── Helpers ─────────────────────────────────────────────────

    private function simpleTable(): TableDefinition
    {
        return new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'bigInteger', primary: true, autoIncrement: true),
                new ColumnDefinition(name: 'title', columnType: 'string'),
            ],
        );
    }

    private function tableWithBelongsTo(): TableDefinition
    {
        return new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'bigInteger', primary: true, autoIncrement: true),
                new ColumnDefinition(name: 'title', columnType: 'string'),
                new ColumnDefinition(name: 'author_id', columnType: 'unsignedBigInteger'),
            ],
            relationships: [
                new RelationshipDefinition(
                    name: 'author',
                    type: 'belongsTo',
                    relatedModel: 'App\\Models\\User',
                    foreignColumn: 'author_id',
                ),
            ],
        );
    }
}
