<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Generator\Api\ApiCodeGenerator;
use SchemaCraft\Generator\Api\GeneratedFile;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Scanner\RelationshipDefinition;
use SchemaCraft\Scanner\TableDefinition;

class ApiCodeGeneratorTest extends TestCase
{
    private ApiCodeGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new ApiCodeGenerator(
            dirname(__DIR__, 2).'/src/Console/stubs',
        );
    }

    private function makeTable(array $columns = [], array $relationships = []): TableDefinition
    {
        if (empty($columns)) {
            $columns = [
                new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
                new ColumnDefinition(name: 'title', columnType: 'string'),
                new ColumnDefinition(name: 'slug', columnType: 'string', unique: true),
                new ColumnDefinition(name: 'body', columnType: 'text', nullable: true),
                new ColumnDefinition(name: 'is_featured', columnType: 'boolean'),
                new ColumnDefinition(name: 'view_count', columnType: 'integer'),
                new ColumnDefinition(name: 'published_at', columnType: 'timestamp', nullable: true),
            ];
        }

        return new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: $columns,
            relationships: $relationships,
            hasTimestamps: true,
        );
    }

    // ─── generate() returns all files ─────────────────────────────

    public function test_generate_returns_all_five_files(): void
    {
        $files = $this->generator->generate($this->makeTable(), 'Post');

        $this->assertArrayHasKey('controller', $files);
        $this->assertArrayHasKey('service', $files);
        $this->assertArrayHasKey('create_request', $files);
        $this->assertArrayHasKey('update_request', $files);
        $this->assertArrayHasKey('resource', $files);

        foreach ($files as $file) {
            $this->assertInstanceOf(GeneratedFile::class, $file);
        }
    }

    // ─── Controller generation ────────────────────────────────────

    public function test_controller_has_correct_namespace(): void
    {
        $files = $this->generator->generate($this->makeTable(), 'Post');
        $content = $files['controller']->content;

        $this->assertStringContainsString('namespace App\\Http\\Controllers\\Api;', $content);
    }

    public function test_controller_has_api_routes_method(): void
    {
        $files = $this->generator->generate($this->makeTable(), 'Post');
        $content = $files['controller']->content;

        $this->assertStringContainsString('public static function apiRoutes(): void', $content);
        $this->assertStringContainsString("Route::get('posts',", $content);
        $this->assertStringContainsString("Route::get('posts/{post}',", $content);
        $this->assertStringContainsString("Route::post('posts',", $content);
        $this->assertStringContainsString("Route::put('posts/{post}',", $content);
        $this->assertStringContainsString("Route::delete('posts/{post}',", $content);
    }

    public function test_controller_has_crud_methods(): void
    {
        $files = $this->generator->generate($this->makeTable(), 'Post');
        $content = $files['controller']->content;

        $this->assertStringContainsString('public function getCollection()', $content);
        $this->assertStringContainsString('public function get(Post $post)', $content);
        $this->assertStringContainsString('public function create(CreatePostRequest $request)', $content);
        $this->assertStringContainsString('public function update(UpdatePostRequest $request, Post $post)', $content);
        $this->assertStringContainsString('public function delete(Post $post)', $content);
    }

    public function test_controller_imports_all_dependencies(): void
    {
        $files = $this->generator->generate($this->makeTable(), 'Post');
        $content = $files['controller']->content;

        $this->assertStringContainsString('use App\\Models\\Post;', $content);
        $this->assertStringContainsString('use App\\Models\\Services\\PostService;', $content);
        $this->assertStringContainsString('use App\\Resources\\PostResource;', $content);
        $this->assertStringContainsString('use App\\Http\\Requests\\CreatePostRequest;', $content);
        $this->assertStringContainsString('use App\\Http\\Requests\\UpdatePostRequest;', $content);
    }

    public function test_controller_create_delegates_to_service(): void
    {
        $files = $this->generator->generate($this->makeTable(), 'Post');
        $content = $files['controller']->content;

        $this->assertStringContainsString('PostService::create(', $content);
        $this->assertStringContainsString('...$request->validated()', $content);
    }

    public function test_controller_update_calls_service_method(): void
    {
        $files = $this->generator->generate($this->makeTable(), 'Post');
        $content = $files['controller']->content;

        $this->assertStringContainsString('$post->Service()->update(', $content);
    }

    public function test_controller_delete_calls_service_method(): void
    {
        $files = $this->generator->generate($this->makeTable(), 'Post');
        $content = $files['controller']->content;

        $this->assertStringContainsString('$post->Service()->delete()', $content);
    }

    public function test_controller_returns_resource(): void
    {
        $files = $this->generator->generate($this->makeTable(), 'Post');
        $content = $files['controller']->content;

        $this->assertStringContainsString('return new PostResource($post)', $content);
        $this->assertStringContainsString('PostResource::collection(', $content);
    }

    // ─── Service generation ───────────────────────────────────────

    public function test_service_has_correct_namespace(): void
    {
        $files = $this->generator->generate($this->makeTable(), 'Post');
        $content = $files['service']->content;

        $this->assertStringContainsString('namespace App\\Models\\Services;', $content);
    }

    public function test_service_has_model_property(): void
    {
        $files = $this->generator->generate($this->makeTable(), 'Post');
        $content = $files['service']->content;

        $this->assertStringContainsString('private Post $post;', $content);
    }

    public function test_service_has_constructor_with_model(): void
    {
        $files = $this->generator->generate($this->makeTable(), 'Post');
        $content = $files['service']->content;

        $this->assertStringContainsString('public function __construct(Post $post)', $content);
        $this->assertStringContainsString('$this->post = $post;', $content);
    }

    public function test_service_has_static_create_method(): void
    {
        $files = $this->generator->generate($this->makeTable(), 'Post');
        $content = $files['service']->content;

        $this->assertStringContainsString('public static function create(', $content);
        $this->assertStringContainsString('): Post', $content);
        $this->assertStringContainsString('$post = new Post();', $content);
        $this->assertStringContainsString('$post->save();', $content);
        $this->assertStringContainsString('return $post;', $content);
    }

    public function test_service_has_instance_update_method(): void
    {
        $files = $this->generator->generate($this->makeTable(), 'Post');
        $content = $files['service']->content;

        $this->assertStringContainsString('public function update(', $content);
        $this->assertStringContainsString('$this->post->save();', $content);
        $this->assertStringContainsString('return $this->post;', $content);
    }

    public function test_service_has_delete_method(): void
    {
        $files = $this->generator->generate($this->makeTable(), 'Post');
        $content = $files['service']->content;

        $this->assertStringContainsString('public function delete(): void', $content);
        $this->assertStringContainsString('$this->post->delete();', $content);
    }

    public function test_service_create_has_correct_assignments(): void
    {
        $files = $this->generator->generate($this->makeTable(), 'Post');
        $content = $files['service']->content;

        $this->assertStringContainsString('$post->title = $title;', $content);
        $this->assertStringContainsString('$post->slug = $slug;', $content);
        $this->assertStringContainsString('$post->body = $body;', $content);
        $this->assertStringContainsString('$post->is_featured = $isFeatured;', $content);
        $this->assertStringContainsString('$post->view_count = $viewCount;', $content);
        $this->assertStringContainsString('$post->published_at = $publishedAt;', $content);
    }

    public function test_service_update_uses_this_model(): void
    {
        $files = $this->generator->generate($this->makeTable(), 'Post');
        $content = $files['service']->content;

        $this->assertStringContainsString('$this->post->title = $title;', $content);
        $this->assertStringContainsString('$this->post->slug = $slug;', $content);
    }

    public function test_service_excludes_primary_key_and_timestamps(): void
    {
        $columns = [
            new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
            new ColumnDefinition(name: 'title', columnType: 'string'),
            new ColumnDefinition(name: 'created_at', columnType: 'timestamp', nullable: true),
            new ColumnDefinition(name: 'updated_at', columnType: 'timestamp', nullable: true),
        ];
        $table = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: $columns,
            hasTimestamps: true,
        );

        $files = $this->generator->generate($table, 'Post');
        $content = $files['service']->content;

        $this->assertStringNotContainsString('$post->id', $content);
        $this->assertStringNotContainsString('$post->created_at', $content);
        $this->assertStringNotContainsString('$post->updated_at', $content);
        $this->assertStringContainsString('$post->title = $title;', $content);
    }

    // ─── Service params use correct PHP types ─────────────────────

    public function test_service_params_have_correct_types(): void
    {
        $files = $this->generator->generate($this->makeTable(), 'Post');
        $content = $files['service']->content;

        $this->assertStringContainsString('string $title', $content);
        $this->assertStringContainsString('string $slug', $content);
        $this->assertStringContainsString('?string $body = null', $content);
        $this->assertStringContainsString('bool $isFeatured', $content);
        $this->assertStringContainsString('int $viewCount', $content);
        $this->assertStringContainsString('?string $publishedAt = null', $content);
    }

    // ─── Create Request generation ────────────────────────────────

    public function test_create_request_uses_schema_create_rules(): void
    {
        $files = $this->generator->generate($this->makeTable(), 'Post');
        $content = $files['create_request']->content;

        $this->assertStringContainsString('PostSchema::createRules([', $content);
        $this->assertStringContainsString("'title',", $content);
        $this->assertStringContainsString("'slug',", $content);
        $this->assertStringContainsString("'body',", $content);
    }

    public function test_create_request_has_correct_class_name(): void
    {
        $files = $this->generator->generate($this->makeTable(), 'Post');
        $content = $files['create_request']->content;

        $this->assertStringContainsString('class CreatePostRequest extends FormRequest', $content);
    }

    public function test_create_request_imports_schema(): void
    {
        $files = $this->generator->generate($this->makeTable(), 'Post');
        $content = $files['create_request']->content;

        $this->assertStringContainsString('use App\\Schemas\\PostSchema;', $content);
    }

    // ─── Update Request generation ────────────────────────────────

    public function test_update_request_uses_schema_update_rules(): void
    {
        $files = $this->generator->generate($this->makeTable(), 'Post');
        $content = $files['update_request']->content;

        $this->assertStringContainsString('PostSchema::updateRules([', $content);
    }

    public function test_update_request_has_correct_class_name(): void
    {
        $files = $this->generator->generate($this->makeTable(), 'Post');
        $content = $files['update_request']->content;

        $this->assertStringContainsString('class UpdatePostRequest extends FormRequest', $content);
    }

    // ─── Resource generation ──────────────────────────────────────

    public function test_resource_is_generated(): void
    {
        $files = $this->generator->generate($this->makeTable(), 'Post');
        $content = $files['resource']->content;

        $this->assertStringContainsString('class PostResource extends JsonResource', $content);
        $this->assertStringContainsString('public function toArray(Request $request): array', $content);
    }

    // ─── Route prefix naming ──────────────────────────────────────

    public function test_route_prefix_for_multi_word_model(): void
    {
        $columns = [
            new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
            new ColumnDefinition(name: 'name', columnType: 'string'),
        ];
        $table = new TableDefinition(
            tableName: 'blog_posts',
            schemaClass: 'App\\Schemas\\BlogPostSchema',
            columns: $columns,
        );

        $files = $this->generator->generate($table, 'BlogPost');
        $content = $files['controller']->content;

        $this->assertStringContainsString("Route::get('blog-posts',", $content);
        $this->assertStringContainsString('{blogPost}', $content);
    }

    // ─── File paths ───────────────────────────────────────────────

    public function test_controller_file_path(): void
    {
        $files = $this->generator->generate($this->makeTable(), 'Post');

        $this->assertSame('app/Http/Controllers/Api/PostController.php', $files['controller']->path);
    }

    public function test_service_file_path(): void
    {
        $files = $this->generator->generate($this->makeTable(), 'Post');

        $this->assertSame('app/Models/Services/PostService.php', $files['service']->path);
    }

    public function test_create_request_file_path(): void
    {
        $files = $this->generator->generate($this->makeTable(), 'Post');

        $this->assertSame('app/Http/Requests/CreatePostRequest.php', $files['create_request']->path);
    }

    public function test_update_request_file_path(): void
    {
        $files = $this->generator->generate($this->makeTable(), 'Post');

        $this->assertSame('app/Http/Requests/UpdatePostRequest.php', $files['update_request']->path);
    }

    public function test_resource_file_path(): void
    {
        $files = $this->generator->generate($this->makeTable(), 'Post');

        $this->assertSame('app/Resources/PostResource.php', $files['resource']->path);
    }

    // ─── Action request generation ────────────────────────────────

    public function test_generate_action_request(): void
    {
        $file = $this->generator->generateAction('cancel', 'Post');

        $this->assertInstanceOf(GeneratedFile::class, $file);
        $this->assertStringContainsString('class CancelPostRequest extends FormRequest', $file->content);
        $this->assertStringContainsString('namespace App\\Http\\Requests;', $file->content);
    }

    // ─── FK columns included in editable fields ───────────────────

    public function test_fk_columns_are_editable(): void
    {
        $columns = [
            new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
            new ColumnDefinition(name: 'title', columnType: 'string'),
            new ColumnDefinition(name: 'author_id', columnType: 'unsignedBigInteger'),
        ];
        $relationships = [
            new RelationshipDefinition(name: 'author', type: 'belongsTo', relatedModel: 'App\\Models\\User'),
        ];
        $table = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: $columns,
            relationships: $relationships,
        );

        $files = $this->generator->generate($table, 'Post');
        $serviceContent = $files['service']->content;
        $requestContent = $files['create_request']->content;

        $this->assertStringContainsString('$post->author_id = $authorId;', $serviceContent);
        $this->assertStringContainsString("'author_id',", $requestContent);
    }

    // ─── Soft deletes columns excluded ────────────────────────────

    public function test_soft_delete_columns_excluded(): void
    {
        $columns = [
            new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
            new ColumnDefinition(name: 'title', columnType: 'string'),
            new ColumnDefinition(name: 'deleted_at', columnType: 'timestamp', nullable: true),
        ];
        $table = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: $columns,
            hasSoftDeletes: true,
        );

        $files = $this->generator->generate($table, 'Post');
        $content = $files['service']->content;

        $this->assertStringNotContainsString('deleted_at', $content);
    }

    // ─── Custom namespaces ────────────────────────────────────────

    public function test_custom_namespaces(): void
    {
        $files = $this->generator->generate(
            $this->makeTable(),
            'Post',
            modelNamespace: 'Domain\\Blog\\Models',
            controllerNamespace: 'App\\Http\\Controllers\\Api\\V2',
            serviceNamespace: 'Domain\\Blog\\Services',
            requestNamespace: 'App\\Http\\Requests\\V2',
            resourceNamespace: 'App\\Http\\Resources\\V2',
            schemaNamespace: 'Domain\\Blog\\Schemas',
        );

        $controller = $files['controller']->content;
        $service = $files['service']->content;
        $createRequest = $files['create_request']->content;
        $resource = $files['resource']->content;

        $this->assertStringContainsString('namespace App\\Http\\Controllers\\Api\\V2;', $controller);
        $this->assertStringContainsString('use Domain\\Blog\\Models\\Post;', $controller);
        $this->assertStringContainsString('use Domain\\Blog\\Services\\PostService;', $controller);
        $this->assertStringContainsString('namespace Domain\\Blog\\Services;', $service);
        $this->assertStringContainsString('namespace App\\Http\\Requests\\V2;', $createRequest);
        $this->assertStringContainsString('use Domain\\Blog\\Schemas\\PostSchema;', $createRequest);
        $this->assertStringContainsString('namespace App\\Http\\Resources\\V2;', $resource);
    }

    // ─── No excessive blank lines ─────────────────────────────────

    public function test_output_has_no_triple_blank_lines(): void
    {
        $files = $this->generator->generate($this->makeTable(), 'Post');

        foreach ($files as $file) {
            $this->assertDoesNotMatchRegularExpression('/\n{3,}/', $file->content, "File {$file->path} has excessive blank lines");
        }
    }
}
