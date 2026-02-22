<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Generator\DependencyResolver;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Scanner\RelationshipDefinition;
use SchemaCraft\Scanner\SchemaScanner;
use SchemaCraft\Scanner\TableDefinition;
use SchemaCraft\Tests\Fixtures\Models\Comment;
use SchemaCraft\Tests\Fixtures\Models\Tag;
use SchemaCraft\Tests\Fixtures\Schemas\CategorySchema;
use SchemaCraft\Tests\Fixtures\Schemas\CommentSchema;
use SchemaCraft\Tests\Fixtures\Schemas\PostSchema;
use SchemaCraft\Tests\Fixtures\Schemas\UserSchema;

class DependencyResolverTest extends TestCase
{
    private DependencyResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new DependencyResolver;
    }

    // ─── resolveSchemaClass ──────────────────────────────────────

    public function test_resolves_schema_by_convention(): void
    {
        $result = $this->resolver->resolveSchemaClass(Comment::class);

        $this->assertSame(CommentSchema::class, $result);
    }

    public function test_resolves_schema_by_reflection(): void
    {
        // The Comment model has protected static string $schema = CommentSchema::class
        // Both convention and reflection should work for it, but this tests the mechanism exists
        $result = $this->resolver->resolveSchemaClass(Comment::class);

        $this->assertNotNull($result);
        $this->assertSame(CommentSchema::class, $result);
    }

    public function test_returns_null_for_nonexistent_model(): void
    {
        $result = $this->resolver->resolveSchemaClass('App\\Models\\NonExistentModel');

        $this->assertNull($result);
    }

    public function test_returns_null_for_non_schema_model(): void
    {
        // Illuminate\Database\Eloquent\Model has no schema (used by MorphTo)
        $result = $this->resolver->resolveSchemaClass(\Illuminate\Database\Eloquent\Model::class);

        $this->assertNull($result);
    }

    // ─── resolveDependencies ─────────────────────────────────────

    public function test_resolves_single_level_dependencies(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();

        $deps = $this->resolver->resolveDependencies($table);

        // Post has HasMany(Comment), BelongsToMany(Tag), MorphMany(Comment)
        // BelongsTo(User) and BelongsTo(Category) are skipped
        $this->assertArrayHasKey('Comment', $deps);
        $this->assertArrayHasKey('Tag', $deps);
        $this->assertSame('comments', $deps['Comment']->tableName);
        $this->assertSame('tags', $deps['Tag']->tableName);
    }

    public function test_skips_belongs_to_relationships(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();

        $deps = $this->resolver->resolveDependencies($table);

        // User and Category are BelongsTo — should NOT appear as dependencies
        $this->assertArrayNotHasKey('User', $deps);
        $this->assertArrayNotHasKey('Category', $deps);
    }

    public function test_skips_morph_to_relationships(): void
    {
        // CommentSchema has a MorphTo('commentable') — should not generate a dependency
        $table = (new SchemaScanner(CommentSchema::class))->scan();

        $deps = $this->resolver->resolveDependencies($table);

        // Comment has BelongsTo(User) and MorphTo(commentable) — both skipped
        $this->assertEmpty($deps);
    }

    public function test_handles_cycles_without_infinite_loop(): void
    {
        // Post → HasMany(Comment), BelongsToMany(Tag)
        // Tag → BelongsToMany(Post) — cycle back to Post
        // User → HasMany(Post) — cycle back to Post
        $table = (new SchemaScanner(PostSchema::class))->scan();

        $deps = $this->resolver->resolveDependencies($table);

        // Should resolve without infinite loop
        $this->assertArrayHasKey('Comment', $deps);
        $this->assertArrayHasKey('Tag', $deps);
        // Post itself should NOT be in the dependencies (it's the root)
        $this->assertArrayNotHasKey('Post', $deps);
    }

    public function test_returns_empty_for_schema_with_no_child_relationships(): void
    {
        $table = (new SchemaScanner(CategorySchema::class))->scan();

        $deps = $this->resolver->resolveDependencies($table);

        $this->assertEmpty($deps);
    }

    public function test_collects_warnings_for_unresolvable_models(): void
    {
        // Create a fake table with a relationship to a non-existent model
        $table = new TableDefinition(
            tableName: 'things',
            schemaClass: 'App\\Schemas\\ThingSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'bigInteger', primary: true, autoIncrement: true),
            ],
            relationships: [
                new RelationshipDefinition(
                    name: 'widgets',
                    type: 'hasMany',
                    relatedModel: 'App\\Models\\NonExistentWidget',
                ),
            ],
        );

        $deps = $this->resolver->resolveDependencies($table);

        $this->assertEmpty($deps);
        $this->assertNotEmpty($this->resolver->getWarnings());
        $this->assertStringContainsString('NonExistentWidget', $this->resolver->getWarnings()[0]);
    }

    public function test_deduplicates_same_model_referenced_multiple_times(): void
    {
        // PostSchema references Comment via both HasMany and MorphMany
        $table = (new SchemaScanner(PostSchema::class))->scan();

        $deps = $this->resolver->resolveDependencies($table);

        // Comment should appear only once
        $commentCount = 0;
        foreach ($deps as $modelName => $depTable) {
            if ($modelName === 'Comment') {
                $commentCount++;
            }
        }

        $this->assertSame(1, $commentCount);
    }

    public function test_resolves_transitive_dependencies(): void
    {
        // UserSchema → HasMany(Post)
        // Post → HasMany(Comment), BelongsToMany(Tag)
        // So User's deps should include Post, Comment, and Tag
        $table = (new SchemaScanner(UserSchema::class))->scan();

        $deps = $this->resolver->resolveDependencies($table);

        $this->assertArrayHasKey('Post', $deps);
        $this->assertArrayHasKey('Comment', $deps);
        $this->assertArrayHasKey('Tag', $deps);
    }

    public function test_dependency_table_definitions_are_valid(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();

        $deps = $this->resolver->resolveDependencies($table);

        foreach ($deps as $modelName => $depTable) {
            $this->assertInstanceOf(TableDefinition::class, $depTable);
            $this->assertNotEmpty($depTable->tableName);
            $this->assertNotEmpty($depTable->schemaClass);
            $this->assertNotEmpty($depTable->columns);
        }
    }
}
