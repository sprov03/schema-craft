<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Generator\FactoryGenerator;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Scanner\RelationshipDefinition;
use SchemaCraft\Scanner\TableDefinition;
use SchemaCraft\Tests\Fixtures\Enums\PostStatus;

class FactoryGeneratorTest extends TestCase
{
    private FactoryGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new FactoryGenerator;
    }

    // ─── Basic structure ─────────────────────────────────────────

    public function test_generates_correct_namespace_and_class_name(): void
    {
        $table = $this->simpleTable();

        $output = $this->generator->generate($table, 'Post');

        $this->assertStringContainsString('namespace Database\Factories;', $output);
        $this->assertStringContainsString('class PostFactory', $output);
    }

    public function test_generates_custom_factory_namespace(): void
    {
        $table = $this->simpleTable();

        $output = $this->generator->generate($table, 'Post', 'App\\Models', 'App\\Factories');

        $this->assertStringContainsString('namespace App\Factories;', $output);
    }

    public function test_imports_model_and_faker_and_collection(): void
    {
        $table = $this->simpleTable();

        $output = $this->generator->generate($table, 'Post');

        $this->assertStringContainsString('use App\Models\Post;', $output);
        $this->assertStringContainsString('use Faker\Generator as Faker;', $output);
        $this->assertStringContainsString('use Illuminate\Support\Collection;', $output);
    }

    public function test_generates_three_static_methods(): void
    {
        $table = $this->simpleTable();

        $output = $this->generator->generate($table, 'Post');

        $this->assertStringContainsString('public static function makeDefault(array $data = []): Post', $output);
        $this->assertStringContainsString('public static function createDefault(array $data = []): Post', $output);
        $this->assertStringContainsString('public static function createDefaults(int $number, array $data = []): Collection', $output);
    }

    // ─── makeDefault body ────────────────────────────────────────

    public function test_make_default_instantiates_model_and_uses_faker(): void
    {
        $table = $this->simpleTable();

        $output = $this->generator->generate($table, 'Post');

        $this->assertStringContainsString('$faker = app(Faker::class);', $output);
        $this->assertStringContainsString('$post = new Post;', $output);
    }

    public function test_make_default_assigns_faker_values_to_columns(): void
    {
        $table = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'bigInteger', primary: true, autoIncrement: true),
                new ColumnDefinition(name: 'title', columnType: 'string', length: 255),
                new ColumnDefinition(name: 'body', columnType: 'text'),
                new ColumnDefinition(name: 'is_published', columnType: 'boolean'),
            ],
        );

        $output = $this->generator->generate($table, 'Post');

        $this->assertStringContainsString('$post->title = $faker->sentence();', $output);
        $this->assertStringContainsString('$post->body = $faker->paragraph();', $output);
        $this->assertStringContainsString('$post->is_published = $faker->boolean();', $output);
    }

    public function test_make_default_uses_force_fill_for_overrides(): void
    {
        $table = $this->simpleTable();

        $output = $this->generator->generate($table, 'Post');

        $this->assertStringContainsString('$post->forceFill($data);', $output);
        $this->assertStringContainsString('return $post;', $output);
    }

    // ─── Skipping managed columns ────────────────────────────────

    public function test_skips_primary_key_column(): void
    {
        $table = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'bigInteger', primary: true, autoIncrement: true),
                new ColumnDefinition(name: 'title', columnType: 'string'),
            ],
        );

        $output = $this->generator->generate($table, 'Post');

        $this->assertStringNotContainsString('$post->id =', $output);
        $this->assertStringContainsString('$post->title =', $output);
    }

    public function test_skips_timestamp_columns(): void
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

        $this->assertStringNotContainsString('$post->created_at', $output);
        $this->assertStringNotContainsString('$post->updated_at', $output);
    }

    public function test_skips_soft_delete_column(): void
    {
        $table = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'bigInteger', primary: true, autoIncrement: true),
                new ColumnDefinition(name: 'title', columnType: 'string'),
                new ColumnDefinition(name: 'deleted_at', columnType: 'timestamp', nullable: true),
            ],
            hasSoftDeletes: true,
        );

        $output = $this->generator->generate($table, 'Post');

        $this->assertStringNotContainsString('$post->deleted_at', $output);
    }

    // ─── Unique columns ──────────────────────────────────────────

    public function test_unique_columns_use_unique_faker(): void
    {
        $table = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'bigInteger', primary: true, autoIncrement: true),
                new ColumnDefinition(name: 'slug', columnType: 'string', unique: true),
            ],
        );

        $output = $this->generator->generate($table, 'Post');

        $this->assertStringContainsString('$post->slug = $faker->unique()->slug();', $output);
    }

    // ─── Enum cast columns ───────────────────────────────────────

    public function test_enum_cast_column_uses_random_element(): void
    {
        $table = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'bigInteger', primary: true, autoIncrement: true),
                new ColumnDefinition(name: 'status', columnType: 'string', castType: PostStatus::class),
            ],
        );

        $output = $this->generator->generate($table, 'Post');

        $this->assertStringContainsString('$post->status = $faker->randomElement(PostStatus::cases());', $output);
    }

    // ─── UUID / ULID primary keys ────────────────────────────────

    public function test_skips_uuid_primary_key(): void
    {
        $table = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'uuid', primary: true),
                new ColumnDefinition(name: 'title', columnType: 'string'),
            ],
        );

        $output = $this->generator->generate($table, 'Post');

        $this->assertStringNotContainsString('$post->id =', $output);
        $this->assertStringContainsString('$post->title =', $output);
    }

    public function test_skips_ulid_primary_key(): void
    {
        $table = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'ulid', primary: true),
                new ColumnDefinition(name: 'title', columnType: 'string'),
            ],
        );

        $output = $this->generator->generate($table, 'Post');

        $this->assertStringNotContainsString('$post->id =', $output);
    }

    // ─── createDefault with BelongsTo ────────────────────────────

    public function test_create_default_calls_make_default_and_saves(): void
    {
        $table = $this->simpleTable();

        $output = $this->generator->generate($table, 'Post');

        $this->assertStringContainsString('$post = self::makeDefault($data);', $output);
        $this->assertStringContainsString('$post->save();', $output);
    }

    public function test_create_default_auto_associates_belongs_to(): void
    {
        $table = new TableDefinition(
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

        $output = $this->generator->generate($table, 'Post');

        $this->assertStringContainsString('if (! $post->author) {', $output);
        $this->assertStringContainsString('$post->author()->associate(UserFactory::createDefault());', $output);
    }

    public function test_create_default_skips_fk_column_in_make_default(): void
    {
        $table = new TableDefinition(
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

        $output = $this->generator->generate($table, 'Post');

        // FK column should NOT appear in makeDefault body assignments
        $this->assertStringNotContainsString('$post->author_id =', $output);
    }

    public function test_nullable_belongs_to_checks_fk_not_null(): void
    {
        $table = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'bigInteger', primary: true, autoIncrement: true),
                new ColumnDefinition(name: 'title', columnType: 'string'),
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

        $this->assertStringContainsString('if (! $post->reviewer && $post->reviewer_id !== null) {', $output);
    }

    public function test_multiple_belongs_to_relationships(): void
    {
        $table = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'bigInteger', primary: true, autoIncrement: true),
                new ColumnDefinition(name: 'title', columnType: 'string'),
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

        $this->assertStringContainsString('UserFactory::createDefault()', $output);
        $this->assertStringContainsString('CategoryFactory::createDefault()', $output);
        $this->assertStringContainsString('use App\Models\User;', $output);
        $this->assertStringContainsString('use App\Models\Category;', $output);
    }

    public function test_imports_related_models_for_belongs_to(): void
    {
        $table = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'bigInteger', primary: true, autoIncrement: true),
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

        $output = $this->generator->generate($table, 'Post');

        $this->assertStringContainsString('use App\Models\User;', $output);
    }

    // ─── createDefaults ──────────────────────────────────────────

    public function test_create_defaults_uses_loop_with_collection(): void
    {
        $table = $this->simpleTable();

        $output = $this->generator->generate($table, 'Post');

        $this->assertStringContainsString('$items = new Collection;', $output);
        $this->assertStringContainsString('for ($i = 0; $i < $number; $i++) {', $output);
        $this->assertStringContainsString('$items->push(self::createDefault($data));', $output);
        $this->assertStringContainsString('return $items;', $output);
    }

    // ─── HasMany relationships ignored ───────────────────────────

    public function test_has_many_relationships_are_ignored(): void
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

        // HasMany should NOT generate any factory association logic
        $this->assertStringNotContainsString('PostFactory', $output);
        $this->assertStringNotContainsString('$user->posts()', $output);
    }

    // ─── Hidden columns still included ───────────────────────────

    public function test_hidden_columns_are_still_included_in_factory(): void
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

        // password is hidden in the model, but factories still need to set it
        $this->assertStringContainsString('$user->password = $faker->password();', $output);
    }

    // ─── FK derived from relationship name ───────────────────────

    public function test_fk_derived_from_relationship_name_when_no_foreign_column(): void
    {
        $table = new TableDefinition(
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
                    // foreignColumn is null — should derive as author_id
                ),
            ],
        );

        $output = $this->generator->generate($table, 'Post');

        // author_id should be skipped in makeDefault (handled by relationship)
        $this->assertStringNotContainsString('$post->author_id =', $output);
        $this->assertStringContainsString('$post->author()->associate(UserFactory::createDefault());', $output);
    }

    // ─── Valid PHP syntax ────────────────────────────────────────

    public function test_output_is_valid_php(): void
    {
        $table = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'bigInteger', primary: true, autoIncrement: true),
                new ColumnDefinition(name: 'title', columnType: 'string', length: 255),
                new ColumnDefinition(name: 'slug', columnType: 'string', unique: true),
                new ColumnDefinition(name: 'body', columnType: 'text'),
                new ColumnDefinition(name: 'is_published', columnType: 'boolean'),
                new ColumnDefinition(name: 'views', columnType: 'unsignedBigInteger'),
                new ColumnDefinition(name: 'author_id', columnType: 'unsignedBigInteger'),
                new ColumnDefinition(name: 'created_at', columnType: 'timestamp', nullable: true),
                new ColumnDefinition(name: 'updated_at', columnType: 'timestamp', nullable: true),
            ],
            relationships: [
                new RelationshipDefinition(
                    name: 'author',
                    type: 'belongsTo',
                    relatedModel: 'App\\Models\\User',
                    foreignColumn: 'author_id',
                ),
            ],
            hasTimestamps: true,
        );

        $output = $this->generator->generate($table, 'Post');

        $tmpFile = tempnam(sys_get_temp_dir(), 'factory_test_').'.php';
        file_put_contents($tmpFile, $output);

        exec("php -l {$tmpFile} 2>&1", $lintOutput, $exitCode);
        unlink($tmpFile);

        $this->assertEquals(0, $exitCode, "Generated factory has syntax errors:\n".implode("\n", $lintOutput));
    }

    // ─── PHPDoc annotations ──────────────────────────────────────

    public function test_methods_have_phpdoc_annotations(): void
    {
        $table = $this->simpleTable();

        $output = $this->generator->generate($table, 'Post');

        // All three methods should have @param array<string, mixed> $data
        $this->assertGreaterThanOrEqual(3, substr_count($output, '@param  array<string, mixed>  $data'));
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

        $this->assertStringContainsString('$blogPost = new BlogPost;', $output);
        $this->assertStringContainsString('$blogPost->title =', $output);
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
}
