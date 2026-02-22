<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Tests\Fixtures\Schemas\CategorySchema;
use SchemaCraft\Tests\Fixtures\Schemas\CommentSchema;
use SchemaCraft\Tests\Fixtures\Schemas\PostSchema;
use SchemaCraft\Tests\Fixtures\Schemas\TagSchema;
use SchemaCraft\Tests\Fixtures\Schemas\UserSchema;
use SchemaCraft\Visualizer\AnalysisResult;
use SchemaCraft\Visualizer\SchemaAnalyzer;

class SchemaAnalyzerTest extends TestCase
{
    private AnalysisResult $result;

    protected function setUp(): void
    {
        parent::setUp();

        $analyzer = new SchemaAnalyzer([
            PostSchema::class,
            UserSchema::class,
            CommentSchema::class,
            TagSchema::class,
            CategorySchema::class,
        ]);

        $this->result = $analyzer->analyze();
    }

    public function test_counts_all_schemas(): void
    {
        $this->assertEquals(5, $this->result->modelCount);
    }

    public function test_counts_all_relationships(): void
    {
        // PostSchema: author(belongsTo), category(belongsTo), comments(hasMany), tags(belongsToMany), morphComments(morphMany) = 5
        // UserSchema: posts(hasMany) = 1
        // CommentSchema: user(belongsTo), commentable(morphTo) = 2
        // TagSchema: posts(belongsToMany) = 1
        // CategorySchema: 0
        $this->assertEquals(9, $this->result->relationshipCount);
    }

    public function test_all_schemas_present_in_result(): void
    {
        $this->assertArrayHasKey(PostSchema::class, $this->result->schemas);
        $this->assertArrayHasKey(UserSchema::class, $this->result->schemas);
        $this->assertArrayHasKey(CommentSchema::class, $this->result->schemas);
        $this->assertArrayHasKey(TagSchema::class, $this->result->schemas);
        $this->assertArrayHasKey(CategorySchema::class, $this->result->schemas);
    }

    public function test_schema_info_has_correct_table_name(): void
    {
        $post = $this->result->schemas[PostSchema::class];

        $this->assertEquals('posts', $post->tableName);
    }

    public function test_schema_info_contains_columns(): void
    {
        $post = $this->result->schemas[PostSchema::class];
        $columnNames = array_column($post->columns, 'name');

        $this->assertContains('id', $columnNames);
        $this->assertContains('title', $columnNames);
        $this->assertContains('slug', $columnNames);
    }

    public function test_schema_info_contains_relationships_with_resolved_schema(): void
    {
        $post = $this->result->schemas[PostSchema::class];
        $authorRel = $this->findRelationship($post->relationships, 'author');

        $this->assertNotNull($authorRel);
        $this->assertEquals('belongsTo', $authorRel['type']);
        $this->assertEquals(UserSchema::class, $authorRel['relatedSchema']);
    }

    public function test_schema_info_has_timestamps_flag(): void
    {
        $post = $this->result->schemas[PostSchema::class];

        $this->assertTrue($post->hasTimestamps);
    }

    public function test_schema_info_has_soft_deletes_flag(): void
    {
        $post = $this->result->schemas[PostSchema::class];

        $this->assertTrue($post->hasSoftDeletes);
    }

    // ── Missing inverse checks ─────────────────────────────

    public function test_detects_missing_inverse_for_category(): void
    {
        // PostSchema has BelongsTo(Category::class) but CategorySchema has no HasMany(Post::class)
        $issues = $this->findIssuesByCheck('missing_inverse');
        $categoryIssues = array_filter(
            $issues,
            fn ($issue) => in_array(CategorySchema::class, $issue->affectedSchemas, true),
        );

        $this->assertNotEmpty($categoryIssues);
    }

    public function test_no_missing_inverse_for_user_posts(): void
    {
        // UserSchema HasMany(Post::class) and PostSchema BelongsTo(User::class) — both sides defined
        $issues = $this->findIssuesByCheck('missing_inverse');
        $userPostIssues = array_filter(
            $issues,
            fn ($issue) => in_array(UserSchema::class, $issue->affectedSchemas, true)
                && in_array(PostSchema::class, $issue->affectedSchemas, true)
                && str_contains($issue->message, 'hasMany'),
        );

        $this->assertEmpty($userPostIssues);
    }

    public function test_belongs_to_many_pair_not_flagged(): void
    {
        // PostSchema BelongsToMany(Tag::class) and TagSchema BelongsToMany(Post::class) — both sides defined
        $issues = $this->findIssuesByCheck('missing_inverse');
        $btmIssues = array_filter(
            $issues,
            fn ($issue) => in_array(TagSchema::class, $issue->affectedSchemas, true)
                && in_array(PostSchema::class, $issue->affectedSchemas, true)
                && str_contains($issue->message, 'belongsToMany'),
        );

        $this->assertEmpty($btmIssues);
    }

    public function test_morph_pair_not_flagged(): void
    {
        // PostSchema MorphMany(Comment::class, 'commentable') and CommentSchema MorphTo('commentable')
        $issues = $this->findIssuesByCheck('missing_inverse');
        $morphIssues = array_filter(
            $issues,
            fn ($issue) => str_contains($issue->message, 'morphMany')
                && str_contains($issue->message, 'morphComments'),
        );

        $this->assertEmpty($morphIssues);
    }

    // ── Orphaned model checks ──────────────────────────────

    public function test_detects_orphaned_model(): void
    {
        // CategorySchema has zero relationships
        $issues = $this->findIssuesByCheck('orphaned_model');
        $categoryIssues = array_filter(
            $issues,
            fn ($issue) => in_array(CategorySchema::class, $issue->affectedSchemas, true),
        );

        $this->assertNotEmpty($categoryIssues);
    }

    public function test_connected_model_not_flagged_as_orphaned(): void
    {
        $issues = $this->findIssuesByCheck('orphaned_model');
        $postIssues = array_filter(
            $issues,
            fn ($issue) => in_array(PostSchema::class, $issue->affectedSchemas, true),
        );

        $this->assertEmpty($postIssues);
    }

    // ── FK without relationship checks ─────────────────────

    public function test_belongs_to_fk_not_flagged(): void
    {
        // PostSchema has author_id column AND BelongsTo for author
        $issues = $this->findIssuesByCheck('fk_without_relationship');
        $authorIdIssues = array_filter(
            $issues,
            fn ($issue) => str_contains($issue->message, 'author_id'),
        );

        $this->assertEmpty($authorIdIssues);
    }

    public function test_morph_columns_not_flagged(): void
    {
        // CommentSchema has commentable_id from MorphTo — should not be flagged
        $issues = $this->findIssuesByCheck('fk_without_relationship');
        $morphIdIssues = array_filter(
            $issues,
            fn ($issue) => str_contains($issue->message, 'commentable_id'),
        );

        $this->assertEmpty($morphIdIssues);
    }

    // ── Suggested fixes ────────────────────────────────────

    public function test_missing_inverse_has_suggested_fix(): void
    {
        $issues = $this->findIssuesByCheck('missing_inverse');

        $this->assertNotEmpty($issues);

        foreach ($issues as $issue) {
            $this->assertNotNull($issue->suggestedFix);
            $this->assertStringContainsString('#[', $issue->suggestedFix);
            $this->assertStringContainsString('@method Eloquent\\', $issue->suggestedFix);
        }
    }

    // ── toArray serialization ──────────────────────────────

    public function test_to_array_has_expected_structure(): void
    {
        $arr = $this->result->toArray();

        $this->assertArrayHasKey('summary', $arr);
        $this->assertArrayHasKey('schemas', $arr);
        $this->assertArrayHasKey('issues', $arr);
        $this->assertArrayHasKey('modelCount', $arr['summary']);
        $this->assertArrayHasKey('relationshipCount', $arr['summary']);
        $this->assertArrayHasKey('issueCount', $arr['summary']);
    }

    public function test_to_array_schemas_are_serializable(): void
    {
        $arr = $this->result->toArray();
        $json = json_encode($arr);

        $this->assertNotFalse($json);

        $decoded = json_decode($json, true);

        $this->assertArrayHasKey('summary', $decoded);
        $this->assertIsArray($decoded['schemas']);
        $this->assertIsArray($decoded['issues']);
    }

    // ── Helpers ─────────────────────────────────────────────

    /**
     * @return \SchemaCraft\Visualizer\HealthIssue[]
     */
    private function findIssuesByCheck(string $check): array
    {
        return array_filter(
            $this->result->issues,
            fn ($issue) => $issue->check === $check,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $relationships
     * @return array<string, mixed>|null
     */
    private function findRelationship(array $relationships, string $name): ?array
    {
        foreach ($relationships as $rel) {
            if ($rel['name'] === $name) {
                return $rel;
            }
        }

        return null;
    }
}
