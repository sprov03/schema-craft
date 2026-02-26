<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Generator\ControllerTestGenerator;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Scanner\RelationshipDefinition;
use SchemaCraft\Scanner\TableDefinition;

class ControllerTestGeneratorTest extends TestCase
{
    private ControllerTestGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new ControllerTestGenerator;
    }

    // ─── Basic structure ─────────────────────────────────────────

    public function test_generates_correct_namespace_and_class(): void
    {
        $table = $this->simpleTable();

        $output = $this->generator->generate($table, 'Post');

        $this->assertStringContainsString('namespace Tests\Feature\Controllers;', $output);
        $this->assertStringContainsString('class PostControllerTest extends TestCase', $output);
    }

    public function test_uses_refresh_database_trait(): void
    {
        $table = $this->simpleTable();

        $output = $this->generator->generate($table, 'Post');

        $this->assertStringContainsString('use RefreshDatabase;', $output);
    }

    public function test_imports_factory_and_test_case(): void
    {
        $table = $this->simpleTable();

        $output = $this->generator->generate($table, 'Post');

        $this->assertStringContainsString('use Database\Factories\PostFactory;', $output);
        $this->assertStringContainsString('use Database\Factories\UserFactory;', $output);
        $this->assertStringContainsString('use Tests\TestCase;', $output);
    }

    // ─── Five CRUD test methods ──────────────────────────────────

    public function test_generates_all_five_crud_tests(): void
    {
        $table = $this->simpleTable();

        $output = $this->generator->generate($table, 'Post');

        $this->assertStringContainsString('test_can_get_collection', $output);
        $this->assertStringContainsString('test_can_get_single', $output);
        $this->assertStringContainsString('test_can_create', $output);
        $this->assertStringContainsString('test_can_update', $output);
        $this->assertStringContainsString('test_can_delete', $output);
    }

    // ─── Sanctum authentication ──────────────────────────────────

    public function test_all_tests_use_sanctum_auth(): void
    {
        $table = $this->simpleTable();

        $output = $this->generator->generate($table, 'Post');

        // Every test should use actingAs with sanctum
        $this->assertEquals(5, substr_count($output, "\$this->actingAs(\$user, 'sanctum')"));
    }

    // ─── Route prefix ────────────────────────────────────────────

    public function test_uses_correct_route_prefix(): void
    {
        $table = $this->simpleTable();

        $output = $this->generator->generate($table, 'Post', 'App\\Models', 'Database\\Factories', 'api');

        $this->assertStringContainsString("'/api/posts'", $output);
    }

    public function test_custom_route_prefix(): void
    {
        $table = $this->simpleTable();

        $output = $this->generator->generate($table, 'Post', 'App\\Models', 'Database\\Factories', 'api/v2');

        $this->assertStringContainsString("'/api/v2/posts'", $output);
    }

    public function test_pluralizes_model_name_for_route(): void
    {
        $table = new TableDefinition(
            tableName: 'categories',
            schemaClass: 'App\\Schemas\\CategorySchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'bigInteger', primary: true, autoIncrement: true),
                new ColumnDefinition(name: 'name', columnType: 'string'),
            ],
        );

        $output = $this->generator->generate($table, 'Category');

        $this->assertStringContainsString('/api/categories', $output);
    }

    // ─── JSON structure assertions ───────────────────────────────

    public function test_get_collection_asserts_json_structure(): void
    {
        $table = $this->simpleTable();

        $output = $this->generator->generate($table, 'Post');

        $this->assertStringContainsString("'data' => [", $output);
        $this->assertStringContainsString("'*' =>", $output);
        $this->assertStringContainsString("'id'", $output);
        $this->assertStringContainsString("'title'", $output);
    }

    public function test_hidden_columns_excluded_from_json_structure(): void
    {
        $table = new TableDefinition(
            tableName: 'users',
            schemaClass: 'App\\Schemas\\UserSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'bigInteger', primary: true, autoIncrement: true),
                new ColumnDefinition(name: 'name', columnType: 'string'),
                new ColumnDefinition(name: 'password', columnType: 'string'),
            ],
            hidden: ['password'],
        );

        $output = $this->generator->generate($table, 'User');

        // password should not appear in JSON structure assertions (it's hidden)
        // But it should appear in request data for create/update (factories need it)
        $this->assertStringContainsString("'name'", $output);
        // The visible columns used in JSON structure should not include password
        // Check the getJson assertJsonStructure section specifically
        $lines = explode("\n", $output);
        $inJsonStructure = false;
        $passwordInStructure = false;
        foreach ($lines as $line) {
            if (str_contains($line, 'assertJsonStructure')) {
                $inJsonStructure = true;
            }
            if ($inJsonStructure && str_contains($line, "'password'")) {
                $passwordInStructure = true;
            }
            if ($inJsonStructure && str_contains($line, ']);') && ! str_contains($line, '$response')) {
                $inJsonStructure = false;
            }
        }
        $this->assertFalse($passwordInStructure, 'Hidden columns should not appear in JSON structure assertions');
    }

    // ─── Create test with request data ───────────────────────────

    public function test_create_test_includes_request_data(): void
    {
        $table = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'bigInteger', primary: true, autoIncrement: true),
                new ColumnDefinition(name: 'title', columnType: 'string'),
                new ColumnDefinition(name: 'body', columnType: 'text'),
                new ColumnDefinition(name: 'is_published', columnType: 'boolean'),
                new ColumnDefinition(name: 'views', columnType: 'integer'),
            ],
        );

        $output = $this->generator->generate($table, 'Post');

        $this->assertStringContainsString("'title' =>", $output);
        $this->assertStringContainsString("'body' =>", $output);
        $this->assertStringContainsString("'is_published' => true", $output);
        $this->assertStringContainsString("'views' => 1", $output);
    }

    public function test_create_test_asserts_database_has(): void
    {
        $table = $this->simpleTable();

        $output = $this->generator->generate($table, 'Post');

        $this->assertStringContainsString('assertDatabaseHas', $output);
        $this->assertStringContainsString("'posts'", $output);
    }

    public function test_create_test_asserts_created_status(): void
    {
        $table = $this->simpleTable();

        $output = $this->generator->generate($table, 'Post');

        $this->assertStringContainsString('assertCreated()', $output);
    }

    // ─── BelongsTo FK in request data ────────────────────────────

    public function test_create_test_includes_fk_from_belongs_to(): void
    {
        $table = $this->tableWithBelongsTo();

        $output = $this->generator->generate($table, 'Post');

        // Should create the related model and use its ID
        $this->assertStringContainsString("'author_id' => \$author->id", $output);
    }

    public function test_fk_columns_not_duplicated_in_editable_columns(): void
    {
        $table = $this->tableWithBelongsTo();

        $output = $this->generator->generate($table, 'Post');

        // author_id should only appear once per test as FK, not also as editable column
        $createSection = $this->extractSection($output, 'test_can_create', 'test_can_update');
        $this->assertEquals(1, substr_count($createSection, "'author_id'"));
    }

    // ─── Delete test ─────────────────────────────────────────────

    public function test_delete_test_asserts_no_content(): void
    {
        $table = $this->simpleTable();

        $output = $this->generator->generate($table, 'Post');

        $this->assertStringContainsString('assertNoContent()', $output);
    }

    // ─── Skipped columns ─────────────────────────────────────────

    public function test_skips_primary_key_in_request_data(): void
    {
        $table = $this->simpleTable();

        $output = $this->generator->generate($table, 'Post');

        // id should NOT appear in $request array
        $createSection = $this->extractSection($output, 'test_can_create', 'test_can_update');
        $this->assertStringNotContainsString("'id' =>", $createSection);
    }

    public function test_skips_timestamps_in_request_data(): void
    {
        $table = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'bigInteger', primary: true, autoIncrement: true),
                new ColumnDefinition(name: 'title', columnType: 'string'),
                new ColumnDefinition(name: 'created_at', columnType: 'timestamp', nullable: true),
                new ColumnDefinition(name: 'updated_at', columnType: 'timestamp', nullable: true),
            ],
            hasTimestamps: true,
        );

        $output = $this->generator->generate($table, 'Post');

        $createSection = $this->extractSection($output, 'test_can_create', 'test_can_update');
        $this->assertStringNotContainsString("'created_at'", $createSection);
        $this->assertStringNotContainsString("'updated_at'", $createSection);
    }

    // ─── Related factory imports ─────────────────────────────────

    public function test_imports_related_factories_for_belongs_to(): void
    {
        $table = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'bigInteger', primary: true, autoIncrement: true),
                new ColumnDefinition(name: 'title', columnType: 'string'),
                new ColumnDefinition(name: 'category_id', columnType: 'unsignedBigInteger'),
            ],
            relationships: [
                new RelationshipDefinition(
                    name: 'category',
                    type: 'belongsTo',
                    relatedModel: 'App\\Models\\Category',
                    foreignColumn: 'category_id',
                ),
            ],
        );

        $output = $this->generator->generate($table, 'Post');

        $this->assertStringContainsString('use Database\Factories\CategoryFactory;', $output);
    }

    // ─── Valid PHP ───────────────────────────────────────────────

    public function test_output_is_valid_php(): void
    {
        $table = $this->tableWithBelongsTo();

        $output = $this->generator->generate($table, 'Post');

        $tmpFile = tempnam(sys_get_temp_dir(), 'ctrl_test_').'.php';
        file_put_contents($tmpFile, $output);

        exec("php -l {$tmpFile} 2>&1", $lintOutput, $exitCode);
        unlink($tmpFile);

        $this->assertEquals(0, $exitCode, "Generated controller test has syntax errors:\n".implode("\n", $lintOutput));
    }

    // ─── Model variable casing ───────────────────────────────────

    public function test_model_variable_uses_camel_case(): void
    {
        $table = new TableDefinition(
            tableName: 'blog_posts',
            schemaClass: 'App\\Schemas\\BlogPostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'bigInteger', primary: true, autoIncrement: true),
                new ColumnDefinition(name: 'title', columnType: 'string'),
            ],
        );

        $output = $this->generator->generate($table, 'BlogPost');

        $this->assertStringContainsString('$blogPost = BlogPostFactory::createDefault();', $output);
        $this->assertStringContainsString('/api/blog-posts', $output);
    }

    // ─── Helpers ─────────────────────────────────────────────────

    private function simpleTable(): TableDefinition
    {
        return new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'bigInteger', primary: true, autoIncrement: true),
                new ColumnDefinition(name: 'title', columnType: 'string', length: 255),
                new ColumnDefinition(name: 'body', columnType: 'text'),
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
                new ColumnDefinition(name: 'title', columnType: 'string', length: 255),
                new ColumnDefinition(name: 'body', columnType: 'text'),
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

    /**
     * Extract a section of the output between two test method names.
     */
    private function extractSection(string $output, string $from, string $to): string
    {
        $startPos = strpos($output, $from);
        $endPos = strpos($output, $to);

        if ($startPos === false || $endPos === false) {
            return '';
        }

        return substr($output, $startPos, $endPos - $startPos);
    }
}
