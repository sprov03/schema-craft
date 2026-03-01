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

    // ─── FK columns with relationship-aware service generation ─────

    public function test_fk_columns_use_associate_in_service(): void
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

        // Service uses model-typed params and associate()
        $this->assertStringContainsString('User $author', $serviceContent);
        $this->assertStringContainsString('$post->author()->associate($author);', $serviceContent);
        $this->assertStringNotContainsString('$post->author_id = $authorId;', $serviceContent);

        // Request still uses FK column name
        $this->assertStringContainsString("'author_id',", $requestContent);
    }

    public function test_service_imports_related_models(): void
    {
        $columns = [
            new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
            new ColumnDefinition(name: 'title', columnType: 'string'),
            new ColumnDefinition(name: 'author_id', columnType: 'unsignedBigInteger'),
            new ColumnDefinition(name: 'category_id', columnType: 'unsignedBigInteger'),
        ];
        $relationships = [
            new RelationshipDefinition(name: 'author', type: 'belongsTo', relatedModel: 'App\\Models\\User'),
            new RelationshipDefinition(name: 'category', type: 'belongsTo', relatedModel: 'App\\Models\\Category'),
        ];
        $table = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: $columns,
            relationships: $relationships,
        );

        $files = $this->generator->generate($table, 'Post');
        $serviceContent = $files['service']->content;

        $this->assertStringContainsString('use App\\Models\\User;', $serviceContent);
        $this->assertStringContainsString('use App\\Models\\Category;', $serviceContent);
    }

    public function test_nullable_fk_uses_conditional_associate_on_create(): void
    {
        $columns = [
            new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
            new ColumnDefinition(name: 'title', columnType: 'string'),
            new ColumnDefinition(name: 'reviewer_id', columnType: 'unsignedBigInteger', nullable: true),
        ];
        $relationships = [
            new RelationshipDefinition(name: 'reviewer', type: 'belongsTo', relatedModel: 'App\\Models\\User', nullable: true),
        ];
        $table = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: $columns,
            relationships: $relationships,
        );

        $files = $this->generator->generate($table, 'Post');
        $serviceContent = $files['service']->content;

        // Nullable param
        $this->assertStringContainsString('?User $reviewer = null', $serviceContent);

        // Create uses conditional associate
        $this->assertStringContainsString('if ($reviewer !== null) {', $serviceContent);
        $this->assertStringContainsString('$post->reviewer()->associate($reviewer);', $serviceContent);
    }

    public function test_nullable_fk_uses_dissociate_on_update(): void
    {
        $columns = [
            new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
            new ColumnDefinition(name: 'title', columnType: 'string'),
            new ColumnDefinition(name: 'reviewer_id', columnType: 'unsignedBigInteger', nullable: true),
        ];
        $relationships = [
            new RelationshipDefinition(name: 'reviewer', type: 'belongsTo', relatedModel: 'App\\Models\\User', nullable: true),
        ];
        $table = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: $columns,
            relationships: $relationships,
        );

        $files = $this->generator->generate($table, 'Post');
        $serviceContent = $files['service']->content;

        // Update uses dissociate for null case
        $this->assertStringContainsString('$this->post->reviewer()->associate($reviewer);', $serviceContent);
        $this->assertStringContainsString('$this->post->reviewer()->dissociate();', $serviceContent);
    }

    public function test_service_no_related_imports_without_relationships(): void
    {
        $files = $this->generator->generate($this->makeTable(), 'Post');
        $serviceContent = $files['service']->content;

        // Should only have the model import, no extra use statements
        $this->assertStringContainsString('use App\\Models\\Post;', $serviceContent);
        $this->assertStringNotContainsString('use App\\Models\\User;', $serviceContent);
    }

    public function test_service_mixes_associate_and_direct_assignments(): void
    {
        $columns = [
            new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
            new ColumnDefinition(name: 'title', columnType: 'string'),
            new ColumnDefinition(name: 'author_id', columnType: 'unsignedBigInteger'),
            new ColumnDefinition(name: 'body', columnType: 'text', nullable: true),
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

        // Direct assignment for regular columns
        $this->assertStringContainsString('$post->title = $title;', $serviceContent);
        $this->assertStringContainsString('$post->body = $body;', $serviceContent);

        // associate() for FK column
        $this->assertStringContainsString('$post->author()->associate($author);', $serviceContent);
        $this->assertStringNotContainsString('$post->author_id', $serviceContent);
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

    // ─── Action controller method PHPDoc description ───────────────

    public function test_action_controller_method_has_default_phpdoc(): void
    {
        $result = $this->generator->renderActionControllerMethod(
            'get', 'archive', 'Post', 'post', 'post',
        );

        $this->assertStringContainsString("/**\n     * Archive the post.\n     */", $result);
    }

    public function test_action_controller_method_uses_custom_description(): void
    {
        $result = $this->generator->renderActionControllerMethod(
            'get', 'archive', 'Post', 'post', 'post',
            description: 'Archive the post and remove from public listings.',
        );

        $this->assertStringContainsString('Archive the post and remove from public listings.', $result);
        $this->assertStringNotContainsString('Archive the post.', $result);
    }

    // ─── generateService() standalone ──────────────────────────────

    public function test_generate_service_returns_service_file(): void
    {
        $file = $this->generator->generateService($this->makeTable(), 'Post');

        $this->assertInstanceOf(GeneratedFile::class, $file);
        $this->assertSame('app/Models/Services/PostService.php', $file->path);
        $this->assertStringContainsString('namespace App\\Models\\Services;', $file->content);
        $this->assertStringContainsString('class PostService', $file->content);
        $this->assertStringContainsString('use App\\Models\\Post;', $file->content);
    }

    public function test_generate_service_with_custom_namespace(): void
    {
        $file = $this->generator->generateService(
            $this->makeTable(),
            'Post',
            modelNamespace: 'Domain\\Blog\\Models',
            serviceNamespace: 'Domain\\Blog\\Services',
        );

        $this->assertSame('Domain/Blog/Services/PostService.php', $file->path);
        $this->assertStringContainsString('namespace Domain\\Blog\\Services;', $file->content);
        $this->assertStringContainsString('use Domain\\Blog\\Models\\Post;', $file->content);
    }

    public function test_generate_service_includes_crud_methods(): void
    {
        $file = $this->generator->generateService($this->makeTable(), 'Post');

        $this->assertStringContainsString('public static function create(', $file->content);
        $this->assertStringContainsString('public function update(', $file->content);
        $this->assertStringContainsString('public function delete(): void', $file->content);
        $this->assertStringContainsString('string $title', $file->content);
        $this->assertStringContainsString('$post->title = $title;', $file->content);
    }

    // ─── Action test method generation ──────────────────────────────

    public function test_action_test_method_get_generates_correct_structure(): void
    {
        $result = $this->generator->renderActionTestMethod(
            'get',
            'archive',
            'Post',
            'post',
            'api/posts',
        );

        $this->assertStringContainsString('public function test_can_archive(): void', $result);
        $this->assertStringContainsString('UserFactory::createDefault()', $result);
        $this->assertStringContainsString("actingAs(\$user, 'sanctum')", $result);
        $this->assertStringContainsString('PostFactory::createDefault()', $result);
        $this->assertStringContainsString("getJson('/api/posts/' . \$post->id . '/archive')", $result);
        $this->assertStringContainsString('assertOk()', $result);
    }

    public function test_action_test_method_delete_generates_correct_structure(): void
    {
        $result = $this->generator->renderActionTestMethod(
            'delete',
            'archive',
            'Post',
            'post',
            'api/posts',
        );

        $this->assertStringContainsString("deleteJson('/api/posts/' . \$post->id . '/archive')", $result);
        $this->assertStringContainsString('assertNoContent()', $result);
    }

    public function test_action_test_method_put_includes_request_fields(): void
    {
        $table = $this->makeTable();

        $result = $this->generator->renderActionTestMethod(
            'put',
            'publish',
            'Post',
            'post',
            'api/posts',
            $table,
        );

        $this->assertStringContainsString('public function test_can_publish(): void', $result);
        $this->assertStringContainsString('$request = [', $result);
        $this->assertStringContainsString("'title' => 'title_test'", $result);
        $this->assertStringContainsString("'body' => 'Test body content'", $result);
        $this->assertStringContainsString("'is_featured' => true", $result);
        $this->assertStringContainsString("putJson('/api/posts/' . \$post->id . '/publish', \$request)", $result);
        $this->assertStringContainsString('assertOk()', $result);
    }

    public function test_action_test_method_post_includes_related_factories(): void
    {
        $columns = [
            new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
            new ColumnDefinition(name: 'title', columnType: 'string'),
            new ColumnDefinition(name: 'author_id', columnType: 'unsignedBigInteger'),
        ];

        $relationships = [
            new RelationshipDefinition(
                name: 'author',
                type: 'belongsTo',
                relatedModel: 'App\\Models\\User',
                foreignColumn: 'author_id',
            ),
        ];

        $table = $this->makeTable($columns, $relationships);

        $result = $this->generator->renderActionTestMethod(
            'post',
            'duplicate',
            'Post',
            'post',
            'api/posts',
            $table,
        );

        $this->assertStringContainsString('public function test_can_duplicate(): void', $result);
        $this->assertStringContainsString('UserFactory::createDefault()', $result);
        $this->assertStringContainsString("'author_id' => \$post->author_id", $result);
        $this->assertStringContainsString("postJson('/api/posts/' . \$post->id . '/duplicate', \$request)", $result);
        $this->assertStringContainsString('assertCreated()', $result);
    }

    public function test_action_test_method_put_uses_fk_from_existing_model(): void
    {
        $columns = [
            new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
            new ColumnDefinition(name: 'title', columnType: 'string'),
            new ColumnDefinition(name: 'category_id', columnType: 'unsignedBigInteger'),
        ];

        $relationships = [
            new RelationshipDefinition(
                name: 'category',
                type: 'belongsTo',
                relatedModel: 'App\\Models\\Category',
                foreignColumn: 'category_id',
            ),
        ];

        $table = $this->makeTable($columns, $relationships);

        $result = $this->generator->renderActionTestMethod(
            'put',
            'recategorize',
            'Post',
            'post',
            'api/posts',
            $table,
        );

        // PUT tests should reference the existing model's FK
        $this->assertStringContainsString("'category_id' => \$post->category_id", $result);
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
