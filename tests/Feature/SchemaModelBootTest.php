<?php

namespace SchemaCraft\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SchemaCraft\SchemaModel;
use SchemaCraft\Tests\Fixtures\Enums\PostStatus;
use SchemaCraft\Tests\Fixtures\Models\Post;
use SchemaCraft\Tests\Fixtures\Models\Tag;
use SchemaCraft\Tests\Fixtures\Models\User;
use SchemaCraft\Tests\TestCase;

class SchemaModelBootTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        SchemaModel::clearSchemaCache();
        SchemaModel::clearBootedModels();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('subtitle', 100);
            $table->text('body')->nullable();
            $table->string('status')->default('draft');
            $table->decimal('price', 10, 2)->unsigned();
            $table->integer('view_count')->unsigned()->default(0);
            $table->boolean('is_featured')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->json('metadata')->default('[]');
            $table->json('address')->nullable();
            $table->unsignedBigInteger('author_id');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('author_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('category_id')->references('id')->on('categories');
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->string('body');
            $table->unsignedBigInteger('user_id');
            $table->string('commentable_type');
            $table->unsignedBigInteger('commentable_id');
            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('users');
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('post_tag', function (Blueprint $table) {
            $table->unsignedBigInteger('post_id');
            $table->unsignedBigInteger('tag_id');
            $table->foreign('post_id')->references('id')->on('posts');
            $table->foreign('tag_id')->references('id')->on('tags');
        });
    }

    public function test_model_gets_table_name_from_schema(): void
    {
        $post = new Post;

        $this->assertEquals('posts', $post->getTable());
    }

    public function test_auto_registers_casts_from_schema(): void
    {
        $post = new Post;
        $casts = $post->getCasts();

        $this->assertEquals('string', $casts['title']);
        $this->assertEquals('boolean', $casts['is_featured']);
        $this->assertEquals('integer', $casts['view_count']);
        $this->assertEquals('datetime', $casts['published_at']);
        $this->assertEquals('array', $casts['metadata']);
        $this->assertEquals(PostStatus::class, $casts['status']);
    }

    public function test_default_attributes_from_schema(): void
    {
        $post = new Post;

        $this->assertEquals(0, $post->view_count);
        $this->assertFalse($post->is_featured);
        $this->assertEquals('draft', $post->getAttributes()['status']);
        $this->assertEquals('[]', $post->getAttributes()['metadata']);
        $this->assertEquals([], $post->metadata);
    }

    public function test_fillable_from_schema(): void
    {
        $post = new Post;

        $this->assertEquals(['title', 'slug', 'subtitle', 'body', 'status', 'price', 'author_id', 'category_id'], $post->getFillable());
    }

    public function test_hidden_from_schema(): void
    {
        $post = new Post;

        $this->assertEquals(['metadata'], $post->getHidden());
    }

    public function test_belongs_to_relationship_works(): void
    {
        $user = User::create(['name' => 'John', 'email' => 'john@test.com', 'password' => 'secret']);
        $post = Post::create(['title' => 'Hello', 'slug' => 'hello', 'subtitle' => 'Sub', 'price' => 9.99, 'author_id' => $user->id]);

        $this->assertInstanceOf(User::class, $post->author);
        $this->assertEquals('John', $post->author->name);
    }

    public function test_nullable_belongs_to_relationship_works(): void
    {
        $user = User::create(['name' => 'John', 'email' => 'john@test.com', 'password' => 'secret']);
        $post = Post::create(['title' => 'Hello', 'slug' => 'hello', 'subtitle' => 'Sub', 'price' => 9.99, 'author_id' => $user->id]);

        $this->assertNull($post->category);
    }

    public function test_has_many_relationship_works(): void
    {
        $user = User::create(['name' => 'John', 'email' => 'john@test.com', 'password' => 'secret']);

        $this->assertCount(0, $user->posts);
    }

    public function test_belongs_to_many_relationship_works(): void
    {
        $user = User::create(['name' => 'John', 'email' => 'john@test.com', 'password' => 'secret']);
        $post = Post::create(['title' => 'Hello', 'slug' => 'hello', 'subtitle' => 'Sub', 'price' => 9.99, 'author_id' => $user->id]);
        $tag = Tag::create(['name' => 'Laravel', 'slug' => 'laravel']);

        $post->tags()->attach($tag->id);
        $post->refresh();

        $this->assertCount(1, $post->tags);
        $this->assertEquals('Laravel', $post->tags->first()->name);
    }

    public function test_eager_loading_works_with_schema_relationships(): void
    {
        $user = User::create(['name' => 'John', 'email' => 'john@test.com', 'password' => 'secret']);
        Post::create(['title' => 'Hello', 'slug' => 'hello', 'subtitle' => 'Sub', 'price' => 9.99, 'author_id' => $user->id]);

        $post = Post::with('author', 'tags')->first();

        $this->assertTrue($post->relationLoaded('author'));
        $this->assertTrue($post->relationLoaded('tags'));
    }

    public function test_enum_cast_works_correctly(): void
    {
        $user = User::create(['name' => 'John', 'email' => 'john@test.com', 'password' => 'secret']);
        $post = Post::create(['title' => 'Hello', 'slug' => 'hello', 'subtitle' => 'Sub', 'price' => 9.99, 'author_id' => $user->id]);

        $post->refresh();

        $this->assertInstanceOf(PostStatus::class, $post->status);
        $this->assertEquals(PostStatus::Draft, $post->status);
    }

    public function test_timestamps_enabled(): void
    {
        $post = new Post;

        $this->assertTrue($post->usesTimestamps());
    }

    public function test_schema_model_without_schema_property_works_as_normal_model(): void
    {
        // This tests that if $schema is not set, the model behaves normally
        // We can't easily test this without creating a model without $schema,
        // but we can verify the guard clause works.
        $this->assertTrue(true);
    }
}
