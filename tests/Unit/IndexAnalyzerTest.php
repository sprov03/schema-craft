<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\QueryBuilder\IndexAnalyzer;
use SchemaCraft\QueryBuilder\QueryDefinition;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Scanner\TableDefinition;

class IndexAnalyzerTest extends TestCase
{
    private IndexAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new IndexAnalyzer;
    }

    // ─── WHERE clause analysis ──────────────────────────────────

    public function test_detects_unindexed_where_column(): void
    {
        $postsTable = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'bigInteger', primary: true),
                new ColumnDefinition(name: 'status', columnType: 'string'),
            ],
        );

        $this->analyzer->registerTable('App\\Schemas\\PostSchema', $postsTable);

        $query = QueryDefinition::fromArray([
            'name' => 'publishedPosts',
            'baseTable' => 'posts',
            'baseModel' => 'App\\Models\\Post',
            'baseSchema' => 'App\\Schemas\\PostSchema',
            'conditions' => [
                ['column' => 'posts.status', 'operator' => '=', 'value' => 'published', 'parameter' => false],
            ],
        ]);

        $suggestions = $this->analyzer->analyze($query);

        $this->assertCount(1, $suggestions);
        $this->assertEquals('status', $suggestions[0]['column']);
        $this->assertStringContainsString('WHERE clause', $suggestions[0]['reason']);
    }

    public function test_skips_already_indexed_column(): void
    {
        $postsTable = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'bigInteger', primary: true),
                new ColumnDefinition(name: 'status', columnType: 'string', index: true),
            ],
        );

        $this->analyzer->registerTable('App\\Schemas\\PostSchema', $postsTable);

        $query = QueryDefinition::fromArray([
            'name' => 'publishedPosts',
            'baseTable' => 'posts',
            'baseModel' => 'App\\Models\\Post',
            'baseSchema' => 'App\\Schemas\\PostSchema',
            'conditions' => [
                ['column' => 'posts.status', 'operator' => '=', 'value' => 'published', 'parameter' => false],
            ],
        ]);

        $suggestions = $this->analyzer->analyze($query);

        $this->assertEmpty($suggestions);
    }

    public function test_skips_unique_column(): void
    {
        $postsTable = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'bigInteger', primary: true),
                new ColumnDefinition(name: 'slug', columnType: 'string', unique: true),
            ],
        );

        $this->analyzer->registerTable('App\\Schemas\\PostSchema', $postsTable);

        $query = QueryDefinition::fromArray([
            'name' => 'postBySlug',
            'baseTable' => 'posts',
            'baseModel' => 'App\\Models\\Post',
            'baseSchema' => 'App\\Schemas\\PostSchema',
            'conditions' => [
                ['column' => 'posts.slug', 'operator' => '=', 'parameter' => true],
            ],
        ]);

        $suggestions = $this->analyzer->analyze($query);

        $this->assertEmpty($suggestions);
    }

    public function test_skips_primary_key_column(): void
    {
        $usersTable = new TableDefinition(
            tableName: 'users',
            schemaClass: 'App\\Schemas\\UserSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'bigInteger', primary: true),
                new ColumnDefinition(name: 'name', columnType: 'string'),
            ],
        );

        $this->analyzer->registerTable('App\\Schemas\\UserSchema', $usersTable);

        $query = QueryDefinition::fromArray([
            'name' => 'test',
            'baseTable' => 'posts',
            'baseModel' => 'App\\Models\\Post',
            'joins' => [
                [
                    'type' => 'inner',
                    'table' => 'users',
                    'schema' => 'App\\Schemas\\UserSchema',
                    'localColumn' => 'author_id',
                    'foreignColumn' => 'id',
                ],
            ],
        ]);

        $suggestions = $this->analyzer->analyze($query);

        // Should not suggest index for users.id (primary key)
        $foreignSuggestions = array_filter($suggestions, fn ($s) => $s['column'] === 'id');
        $this->assertEmpty($foreignSuggestions);
    }

    // ─── JOIN column analysis ───────────────────────────────────

    public function test_detects_unindexed_join_column(): void
    {
        $postsTable = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'bigInteger', primary: true),
                new ColumnDefinition(name: 'author_id', columnType: 'unsignedBigInteger'),
            ],
        );

        $this->analyzer->registerTable('App\\Schemas\\PostSchema', $postsTable);

        $query = QueryDefinition::fromArray([
            'name' => 'postsByAuthor',
            'baseTable' => 'posts',
            'baseModel' => 'App\\Models\\Post',
            'baseSchema' => 'App\\Schemas\\PostSchema',
            'joins' => [
                ['type' => 'inner', 'table' => 'users', 'localColumn' => 'author_id', 'foreignColumn' => 'id'],
            ],
        ]);

        $suggestions = $this->analyzer->analyze($query);

        $this->assertCount(1, $suggestions);
        $this->assertEquals('author_id', $suggestions[0]['column']);
        $this->assertStringContainsString('JOIN', $suggestions[0]['reason']);
    }

    public function test_skips_indexed_join_column(): void
    {
        $postsTable = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'bigInteger', primary: true),
                new ColumnDefinition(name: 'author_id', columnType: 'unsignedBigInteger', index: true),
            ],
        );

        $this->analyzer->registerTable('App\\Schemas\\PostSchema', $postsTable);

        $query = QueryDefinition::fromArray([
            'name' => 'postsByAuthor',
            'baseTable' => 'posts',
            'baseModel' => 'App\\Models\\Post',
            'baseSchema' => 'App\\Schemas\\PostSchema',
            'joins' => [
                ['type' => 'inner', 'table' => 'users', 'localColumn' => 'author_id', 'foreignColumn' => 'id'],
            ],
        ]);

        $suggestions = $this->analyzer->analyze($query);

        $this->assertEmpty($suggestions);
    }

    // ─── Schema not found ───────────────────────────────────────

    public function test_skips_when_schema_not_found(): void
    {
        $query = QueryDefinition::fromArray([
            'name' => 'test',
            'baseTable' => 'posts',
            'baseModel' => 'App\\Models\\Post',
            'baseSchema' => 'NonExistent\\Schema',
            'conditions' => [
                ['column' => 'posts.status', 'operator' => '=', 'parameter' => true],
            ],
        ]);

        $suggestions = $this->analyzer->analyze($query);

        // Should not crash — just returns no suggestions since schema class doesn't exist
        $this->assertEmpty($suggestions);
    }

    public function test_skips_condition_without_schema(): void
    {
        $query = QueryDefinition::fromArray([
            'name' => 'test',
            'baseTable' => 'posts',
            'baseModel' => 'App\\Models\\Post',
            // No baseSchema
            'conditions' => [
                ['column' => 'posts.status', 'operator' => '=', 'parameter' => false],
            ],
        ]);

        $suggestions = $this->analyzer->analyze($query);

        $this->assertEmpty($suggestions);
    }

    // ─── Combined analysis ──────────────────────────────────────

    public function test_detects_multiple_missing_indexes(): void
    {
        $postsTable = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'bigInteger', primary: true),
                new ColumnDefinition(name: 'status', columnType: 'string'),
                new ColumnDefinition(name: 'author_id', columnType: 'unsignedBigInteger'),
            ],
        );

        $this->analyzer->registerTable('App\\Schemas\\PostSchema', $postsTable);

        $query = QueryDefinition::fromArray([
            'name' => 'filteredPosts',
            'baseTable' => 'posts',
            'baseModel' => 'App\\Models\\Post',
            'baseSchema' => 'App\\Schemas\\PostSchema',
            'joins' => [
                ['type' => 'inner', 'table' => 'users', 'localColumn' => 'author_id', 'foreignColumn' => 'id'],
            ],
            'conditions' => [
                ['column' => 'posts.status', 'operator' => '=', 'value' => 'published', 'parameter' => false],
            ],
        ]);

        $suggestions = $this->analyzer->analyze($query);

        $this->assertCount(2, $suggestions);
        $columns = array_column($suggestions, 'column');
        $this->assertContains('author_id', $columns);
        $this->assertContains('status', $columns);
    }
}
