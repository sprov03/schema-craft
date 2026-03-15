<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Generator\ActionFileGenerator;
use SchemaCraft\Generator\Api\GeneratedFile;
use SchemaCraft\Tests\Fixtures\Schemas\PostSchema;

class ActionFileGeneratorTest extends TestCase
{
    private ActionFileGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new ActionFileGenerator(
            dirname(__DIR__, 2).'/src/Console/stubs',
        );
    }

    // ─── generateAction() ─────────────────────────────────────────

    public function test_generates_action_file(): void
    {
        $file = $this->generator->generateAction(
            actionName: 'update',
            schemaClass: PostSchema::class,
            selectedColumns: ['title', 'slug'],
            httpMethod: 'put',
            actionNamespace: 'App\\Models\\Actions\\Post',
            schemaNamespace: 'SchemaCraft\\Tests\\Fixtures\\Schemas',
        );

        $this->assertInstanceOf(GeneratedFile::class, $file);
        $this->assertSame('app/Models/Actions/Post/UpdatePostAction.php', $file->path);
    }

    public function test_action_has_correct_namespace(): void
    {
        $file = $this->generator->generateAction(
            actionName: 'update',
            schemaClass: PostSchema::class,
            selectedColumns: ['title'],
            actionNamespace: 'App\\Models\\Actions\\Post',
            schemaNamespace: 'SchemaCraft\\Tests\\Fixtures\\Schemas',
        );

        $this->assertStringContainsString('namespace App\\Models\\Actions\\Post;', $file->content);
    }

    public function test_action_extends_action_base(): void
    {
        $file = $this->generator->generateAction(
            actionName: 'update',
            schemaClass: PostSchema::class,
            selectedColumns: ['title'],
            actionNamespace: 'App\\Models\\Actions\\Post',
            schemaNamespace: 'SchemaCraft\\Tests\\Fixtures\\Schemas',
        );

        $this->assertStringContainsString('use SchemaCraft\\Action;', $file->content);
        $this->assertStringContainsString('class UpdatePostAction extends Action', $file->content);
    }

    public function test_action_has_action_meta(): void
    {
        $file = $this->generator->generateAction(
            actionName: 'update',
            schemaClass: PostSchema::class,
            selectedColumns: ['title'],
            httpMethod: 'put',
            actionNamespace: 'App\\Models\\Actions\\Post',
            schemaNamespace: 'SchemaCraft\\Tests\\Fixtures\\Schemas',
        );

        $this->assertStringContainsString('use SchemaCraft\\Attributes\\Actions\\ActionMeta;', $file->content);
        $this->assertStringContainsString("#[ActionMeta(method: 'put', label: 'Update Post')]", $file->content);
    }

    public function test_action_has_schema_property(): void
    {
        $file = $this->generator->generateAction(
            actionName: 'update',
            schemaClass: PostSchema::class,
            selectedColumns: ['title'],
            actionNamespace: 'App\\Models\\Actions\\Post',
            schemaNamespace: 'SchemaCraft\\Tests\\Fixtures\\Schemas',
        );

        $this->assertStringContainsString('protected static string $schema = PostSchema::class;', $file->content);
        $this->assertStringContainsString('use SchemaCraft\\Tests\\Fixtures\\Schemas\\PostSchema;', $file->content);
    }

    public function test_action_with_scalar_fields(): void
    {
        $file = $this->generator->generateAction(
            actionName: 'update',
            schemaClass: PostSchema::class,
            selectedColumns: ['title', 'slug', 'body'],
            actionNamespace: 'App\\Models\\Actions\\Post',
            schemaNamespace: 'SchemaCraft\\Tests\\Fixtures\\Schemas',
        );

        $this->assertStringContainsString('public string $title;', $file->content);
        $this->assertStringContainsString('public string $slug;', $file->content);
        $this->assertStringContainsString('public ?string $body;', $file->content);
    }

    public function test_action_with_fk_fields(): void
    {
        $file = $this->generator->generateAction(
            actionName: 'update',
            schemaClass: PostSchema::class,
            selectedColumns: ['title', 'author_id'],
            actionNamespace: 'App\\Models\\Actions\\Post',
            schemaNamespace: 'SchemaCraft\\Tests\\Fixtures\\Schemas',
        );

        $this->assertStringContainsString('#[BelongsTo(User::class)]', $file->content);
        $this->assertStringContainsString('public User $author;', $file->content);
        $this->assertStringContainsString('use SchemaCraft\\Tests\\Fixtures\\Models\\User;', $file->content);
        $this->assertStringContainsString('use SchemaCraft\\Attributes\\Relations\\BelongsTo;', $file->content);
    }

    public function test_action_with_nullable_fk(): void
    {
        $file = $this->generator->generateAction(
            actionName: 'update',
            schemaClass: PostSchema::class,
            selectedColumns: ['category_id'],
            actionNamespace: 'App\\Models\\Actions\\Post',
            schemaNamespace: 'SchemaCraft\\Tests\\Fixtures\\Schemas',
        );

        $this->assertStringContainsString('#[BelongsTo(Category::class)]', $file->content);
        $this->assertStringContainsString('public ?Category $category;', $file->content);
    }

    public function test_action_with_mixed_scalars_and_fks(): void
    {
        $file = $this->generator->generateAction(
            actionName: 'create',
            schemaClass: PostSchema::class,
            selectedColumns: ['title', 'body', 'author_id', 'category_id'],
            httpMethod: 'post',
            actionNamespace: 'App\\Models\\Actions\\Post',
            schemaNamespace: 'SchemaCraft\\Tests\\Fixtures\\Schemas',
        );

        $this->assertStringContainsString('public string $title;', $file->content);
        $this->assertStringContainsString('public ?string $body;', $file->content);
        $this->assertStringContainsString('#[BelongsTo(User::class)]', $file->content);
        $this->assertStringContainsString('public User $author;', $file->content);
        $this->assertStringContainsString('#[BelongsTo(Category::class)]', $file->content);
        $this->assertStringContainsString('public ?Category $category;', $file->content);
        $this->assertStringContainsString("#[ActionMeta(method: 'post', label: 'Create Post')]", $file->content);
    }

    public function test_action_boolean_field_has_default(): void
    {
        $file = $this->generator->generateAction(
            actionName: 'update',
            schemaClass: PostSchema::class,
            selectedColumns: ['is_featured'],
            actionNamespace: 'App\\Models\\Actions\\Post',
            schemaNamespace: 'SchemaCraft\\Tests\\Fixtures\\Schemas',
        );

        $this->assertStringContainsString('public bool $isFeatured = false;', $file->content);
    }

    public function test_action_has_run_method_for_put(): void
    {
        $file = $this->generator->generateAction(
            actionName: 'update',
            schemaClass: PostSchema::class,
            selectedColumns: ['title'],
            httpMethod: 'put',
            actionNamespace: 'App\\Models\\Actions\\Post',
            schemaNamespace: 'SchemaCraft\\Tests\\Fixtures\\Schemas',
        );

        $this->assertStringContainsString('/** @param Post $post */', $file->content);
        $this->assertStringContainsString('public function run(mixed $post, array $mapped): Post', $file->content);
        $this->assertStringContainsString('return $post->Service()->updatePost(...$mapped);', $file->content);
    }

    public function test_action_has_run_method_for_post(): void
    {
        $file = $this->generator->generateAction(
            actionName: 'create',
            schemaClass: PostSchema::class,
            selectedColumns: ['title'],
            httpMethod: 'post',
            actionNamespace: 'App\\Models\\Actions\\Post',
            schemaNamespace: 'SchemaCraft\\Tests\\Fixtures\\Schemas',
            serviceNamespace: 'App\\Models\\Services',
        );

        $this->assertStringContainsString('/** @param Post $post */', $file->content);
        $this->assertStringContainsString('public function run(mixed $post, array $mapped): Post', $file->content);
        $this->assertStringContainsString('return PostService::createPost(...$mapped);', $file->content);
        $this->assertStringContainsString('use App\\Models\\Services\\PostService;', $file->content);
    }

    public function test_action_has_run_method_for_delete(): void
    {
        $file = $this->generator->generateAction(
            actionName: 'delete',
            schemaClass: PostSchema::class,
            selectedColumns: [],
            httpMethod: 'delete',
            actionNamespace: 'App\\Models\\Actions\\Post',
            schemaNamespace: 'SchemaCraft\\Tests\\Fixtures\\Schemas',
        );

        $this->assertStringContainsString('/** @param Post $post */', $file->content);
        $this->assertStringContainsString('public function run(mixed $post, array $mapped): Post', $file->content);
        $this->assertStringContainsString('$post->Service()->deletePost();', $file->content);
        $this->assertStringContainsString('return null;', $file->content);
    }

    public function test_action_has_run_method_for_get(): void
    {
        $file = $this->generator->generateAction(
            actionName: 'show',
            schemaClass: PostSchema::class,
            selectedColumns: [],
            httpMethod: 'get',
            actionNamespace: 'App\\Models\\Actions\\Post',
            schemaNamespace: 'SchemaCraft\\Tests\\Fixtures\\Schemas',
        );

        $this->assertStringContainsString('/** @param Post $post */', $file->content);
        $this->assertStringContainsString('public function run(mixed $post, array $mapped): Post', $file->content);
        $this->assertStringContainsString('return $post->Service()->showPost();', $file->content);
    }

    public function test_action_imports_model_class(): void
    {
        $file = $this->generator->generateAction(
            actionName: 'update',
            schemaClass: PostSchema::class,
            selectedColumns: ['title'],
            httpMethod: 'put',
            actionNamespace: 'App\\Models\\Actions\\Post',
            schemaNamespace: 'SchemaCraft\\Tests\\Fixtures\\Schemas',
            modelNamespace: 'App\\Models',
        );

        $this->assertStringContainsString('use App\\Models\\Post;', $file->content);
    }

    public function test_action_no_excessive_blank_lines(): void
    {
        $file = $this->generator->generateAction(
            actionName: 'update',
            schemaClass: PostSchema::class,
            selectedColumns: ['title', 'author_id'],
            actionNamespace: 'App\\Models\\Actions\\Post',
            schemaNamespace: 'SchemaCraft\\Tests\\Fixtures\\Schemas',
        );

        $this->assertDoesNotMatchRegularExpression('/\n{3,}/', $file->content);
    }

    // ─── generateRegistry() ───────────────────────────────────────

    public function test_generates_registry_file(): void
    {
        $file = $this->generator->generateRegistry(
            modelName: 'Post',
            actionClasses: [
                'create' => 'App\\Models\\Actions\\Post\\CreatePostAction',
                'update' => 'App\\Models\\Actions\\Post\\UpdatePostAction',
                'delete' => 'App\\Models\\Actions\\Post\\DeletePostAction',
            ],
            registryNamespace: 'App\\Models\\Actions',
            schemaNamespace: 'App\\Schemas',
        );

        $this->assertInstanceOf(GeneratedFile::class, $file);
        $this->assertSame('app/Models/Actions/PostActions.php', $file->path);
    }

    public function test_registry_has_correct_class(): void
    {
        $file = $this->generator->generateRegistry(
            modelName: 'Post',
            actionClasses: [
                'create' => 'App\\Models\\Actions\\Post\\CreatePostAction',
            ],
            registryNamespace: 'App\\Models\\Actions',
            schemaNamespace: 'App\\Schemas',
        );

        $this->assertStringContainsString('namespace App\\Models\\Actions;', $file->content);
        $this->assertStringContainsString('use SchemaCraft\\ActionRegistry;', $file->content);
        $this->assertStringContainsString('class PostActions extends ActionRegistry', $file->content);
    }

    public function test_registry_has_schema_property(): void
    {
        $file = $this->generator->generateRegistry(
            modelName: 'Post',
            actionClasses: [
                'create' => 'App\\Models\\Actions\\Post\\CreatePostAction',
            ],
            registryNamespace: 'App\\Models\\Actions',
            schemaNamespace: 'App\\Schemas',
        );

        $this->assertStringContainsString('protected static string $schema = PostSchema::class;', $file->content);
        $this->assertStringContainsString('use App\\Schemas\\PostSchema;', $file->content);
    }

    public function test_registry_has_typed_action_properties(): void
    {
        $file = $this->generator->generateRegistry(
            modelName: 'Post',
            actionClasses: [
                'create' => 'App\\Models\\Actions\\Post\\CreatePostAction',
                'update' => 'App\\Models\\Actions\\Post\\UpdatePostAction',
                'delete' => 'App\\Models\\Actions\\Post\\DeletePostAction',
            ],
            registryNamespace: 'App\\Models\\Actions',
            schemaNamespace: 'App\\Schemas',
        );

        $this->assertStringContainsString('public static function create(): CreatePostAction', $file->content);
        $this->assertStringContainsString('public static function update(): UpdatePostAction', $file->content);
        $this->assertStringContainsString('public static function delete(): DeletePostAction', $file->content);
    }

    public function test_registry_imports_action_classes(): void
    {
        $file = $this->generator->generateRegistry(
            modelName: 'Post',
            actionClasses: [
                'create' => 'App\\Models\\Actions\\Post\\CreatePostAction',
                'update' => 'App\\Models\\Actions\\Post\\UpdatePostAction',
            ],
            registryNamespace: 'App\\Models\\Actions',
            schemaNamespace: 'App\\Schemas',
        );

        $this->assertStringContainsString('use App\\Models\\Actions\\Post\\CreatePostAction;', $file->content);
        $this->assertStringContainsString('use App\\Models\\Actions\\Post\\UpdatePostAction;', $file->content);
    }

    // ─── Nested Relationship Properties ─────────────────────────

    public function test_action_with_has_many_nested_fields(): void
    {
        $file = $this->generator->generateAction(
            actionName: 'update',
            schemaClass: PostSchema::class,
            selectedColumns: ['title', 'comments.*.body'],
            actionNamespace: 'App\\Models\\Actions\\Post',
            schemaNamespace: 'SchemaCraft\\Tests\\Fixtures\\Schemas',
        );

        $this->assertStringContainsString('use SchemaCraft\\Attributes\\Relations\\HasMany;', $file->content);
        $this->assertStringContainsString('use SchemaCraft\\Tests\\Fixtures\\Models\\Comment;', $file->content);
        $this->assertStringContainsString("#[HasMany(Comment::class, fields: ['body'])]", $file->content);
        $this->assertStringContainsString('public array $comments = [];', $file->content);
    }

    public function test_action_with_belongs_to_many_nested_fields(): void
    {
        $file = $this->generator->generateAction(
            actionName: 'update',
            schemaClass: PostSchema::class,
            selectedColumns: ['title', 'tags.*.name', 'tags.*.slug'],
            actionNamespace: 'App\\Models\\Actions\\Post',
            schemaNamespace: 'SchemaCraft\\Tests\\Fixtures\\Schemas',
        );

        $this->assertStringContainsString('use SchemaCraft\\Attributes\\Relations\\BelongsToMany;', $file->content);
        $this->assertStringContainsString('use SchemaCraft\\Tests\\Fixtures\\Models\\Tag;', $file->content);
        $this->assertStringContainsString("#[BelongsToMany(Tag::class, fields: ['name', 'slug'])]", $file->content);
        $this->assertStringContainsString('public array $tags = [];', $file->content);
    }

    public function test_action_with_mixed_flat_and_nested(): void
    {
        $file = $this->generator->generateAction(
            actionName: 'update',
            schemaClass: PostSchema::class,
            selectedColumns: ['title', 'author_id', 'comments.*.body'],
            actionNamespace: 'App\\Models\\Actions\\Post',
            schemaNamespace: 'SchemaCraft\\Tests\\Fixtures\\Schemas',
        );

        // Flat properties
        $this->assertStringContainsString('public string $title;', $file->content);
        $this->assertStringContainsString('#[BelongsTo(User::class)]', $file->content);
        $this->assertStringContainsString('public User $author;', $file->content);

        // Nested property
        $this->assertStringContainsString("#[HasMany(Comment::class, fields: ['body'])]", $file->content);
        $this->assertStringContainsString('public array $comments = [];', $file->content);
    }

    public function test_action_with_morph_many_nested_fields(): void
    {
        $file = $this->generator->generateAction(
            actionName: 'update',
            schemaClass: PostSchema::class,
            selectedColumns: ['title', 'morphComments.*.body'],
            actionNamespace: 'App\\Models\\Actions\\Post',
            schemaNamespace: 'SchemaCraft\\Tests\\Fixtures\\Schemas',
        );

        $this->assertStringContainsString('use SchemaCraft\\Attributes\\Relations\\MorphMany;', $file->content);
        $this->assertStringContainsString("#[MorphMany(Comment::class, 'commentable', fields: ['body'])]", $file->content);
        $this->assertStringContainsString('public array $morphComments = [];', $file->content);
    }

    public function test_registry_no_excessive_blank_lines(): void
    {
        $file = $this->generator->generateRegistry(
            modelName: 'Post',
            actionClasses: [
                'create' => 'App\\Models\\Actions\\Post\\CreatePostAction',
                'update' => 'App\\Models\\Actions\\Post\\UpdatePostAction',
            ],
            registryNamespace: 'App\\Models\\Actions',
            schemaNamespace: 'App\\Schemas',
        );

        $this->assertDoesNotMatchRegularExpression('/\n{3,}/', $file->content);
    }
}
