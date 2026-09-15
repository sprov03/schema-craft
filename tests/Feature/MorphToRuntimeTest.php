<?php

namespace SchemaCraft\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SchemaCraft\SchemaModel;
use SchemaCraft\Tests\Fixtures\Models\Comment;
use SchemaCraft\Tests\Fixtures\Models\Post;
use SchemaCraft\Tests\Fixtures\Models\User;
use SchemaCraft\Tests\TestCase;

/**
 * Runtime verification for a single #[MorphTo] relationship declared on a schema.
 *
 * Covers both supported declaration shapes:
 *  1. CommentSchema fixture — property == morph name: #[MorphTo('commentable')] public Model $commentable;
 *  2. CareItemSchema (below) — camel property + snake morph name + INTERFACE-typed property:
 *     #[MorphTo('in_the_care_of')] public ?CareOfContract $inTheCareOf;
 *
 * Shape 2 works because SchemaTrait's morphTo arm passes $rel->name (the property) as the
 * relation cache name and derives the columns from the morph name explicitly — Eloquent
 * would otherwise snake-case the relation name to guess columns, breaking any morphName that
 * differs from the property.
 *
 *  3. UnionCareItemSchema (below) — UNION-typed property, no interface needed:
 *     #[MorphTo('in_the_care_of')] public Post|User|null $inTheCareOf;
 *     The union is the honest documentation of the possible targets; the scanner only allows
 *     it on #[MorphTo] properties (everywhere else the single type drives resolution).
 *     Nullable form is `A|B|null` — PHP forbids `?` on a union.
 */
class MorphToRuntimeTest extends TestCase
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
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->string('body');
            $table->unsignedBigInteger('user_id');
            $table->string('commentable_type');
            $table->unsignedBigInteger('commentable_id');
            $table->timestamps();
        });

        Schema::create('care_items', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('in_the_care_of_type')->nullable();
            $table->unsignedBigInteger('in_the_care_of_id')->nullable();
        });

        Schema::create('union_care_items', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('in_the_care_of_type')->nullable();
            $table->unsignedBigInteger('in_the_care_of_id')->nullable();
        });
    }

    public function test_morph_to_relation_uses_property_name_and_snake_columns(): void
    {
        $comment = new Comment;
        $relation = $comment->commentable();

        // Relation name must be the property name so associate() caches where reads look.
        $this->assertSame('commentable', $relation->getRelationName());
        $this->assertSame('commentable_type', $relation->getMorphType());
        $this->assertSame('commentable_id', $relation->getForeignKeyName());
    }

    public function test_associate_save_and_fresh_read_round_trip(): void
    {
        $post = $this->makePost();
        $comment = $this->makeComment();

        $comment->commentable()->associate($post);
        $comment->save();

        $this->assertSame(Post::class, $comment->commentable_type);
        $this->assertEquals($post->id, $comment->commentable_id);

        $fresh = Comment::query()->findOrFail($comment->id);
        $this->assertInstanceOf(Post::class, $fresh->commentable);
        $this->assertEquals($post->id, $fresh->commentable->id);
    }

    public function test_associate_is_visible_without_reload(): void
    {
        // The stale-cache regression shape: read (null) -> associate -> read again.
        // Passes only when the relation cache key matches the property name.
        $post = $this->makePost();
        $comment = $this->makeComment();

        $this->assertNull($comment->commentable);

        $comment->commentable()->associate($post);

        $this->assertInstanceOf(Post::class, $comment->commentable);
    }

    public function test_morph_target_can_switch_type(): void
    {
        $post = $this->makePost();
        $user = $this->makeUser('other@example.com');
        $comment = $this->makeComment();

        $comment->commentable()->associate($post);
        $comment->save();

        $comment->commentable()->associate($user);
        $comment->save();

        $fresh = Comment::query()->findOrFail($comment->id);
        $this->assertInstanceOf(User::class, $fresh->commentable);
        $this->assertSame(User::class, $fresh->commentable_type);
    }

    public function test_eager_loading_morph_to(): void
    {
        $post = $this->makePost();

        $comment = $this->makeComment();
        $comment->commentable()->associate($post);
        $comment->save();

        $loaded = Comment::query()->with('commentable')->findOrFail($comment->id);

        $this->assertTrue($loaded->relationLoaded('commentable'));
        $this->assertInstanceOf(Post::class, $loaded->commentable);
    }

    // ─── camel property + snake morph name + interface typing ──

    public function test_camel_property_with_snake_morph_name_builds_correctly(): void
    {
        $item = new CareItem;
        $relation = $item->inTheCareOf();

        // Cache name is the PROPERTY; columns come from the snake morph name.
        $this->assertSame('inTheCareOf', $relation->getRelationName());
        $this->assertSame('in_the_care_of_type', $relation->getMorphType());
        $this->assertSame('in_the_care_of_id', $relation->getForeignKeyName());
    }

    public function test_camel_property_associate_read_and_round_trip(): void
    {
        $post = $this->makePost();

        $item = new CareItem;
        $item->label = 'x';

        // The stale-cache regression shape: read (null) -> associate -> read again.
        $this->assertNull($item->inTheCareOf);
        $item->inTheCareOf()->associate($post);
        $this->assertInstanceOf(Post::class, $item->inTheCareOf);

        $item->save();

        $fresh = CareItem::query()->findOrFail($item->id);
        $this->assertInstanceOf(Post::class, $fresh->inTheCareOf);
        $this->assertEquals($post->id, $fresh->inTheCareOf->id);
    }

    // ─── union-typed morphTo property (no interface) ────────────

    public function test_union_typed_morph_to_scans_and_builds(): void
    {
        $table = (new \SchemaCraft\Scanner\SchemaScanner(UnionCareItemSchema::class))->scan();

        $rel = collect($table->relationships)->firstWhere('name', 'inTheCareOf');
        $this->assertNotNull($rel);
        $this->assertSame('morphTo', $rel->type);
        $this->assertTrue($rel->nullable); // Post|User|null -> nullable columns

        $relation = (new UnionCareItem)->inTheCareOf();
        $this->assertSame('inTheCareOf', $relation->getRelationName());
        $this->assertSame('in_the_care_of_type', $relation->getMorphType());
    }

    public function test_union_typed_morph_to_round_trip(): void
    {
        $user = $this->makeUser('union@example.com');

        $item = new UnionCareItem;
        $item->label = 'x';

        $this->assertNull($item->inTheCareOf);
        $item->inTheCareOf()->associate($user);
        $this->assertInstanceOf(User::class, $item->inTheCareOf);

        $item->save();

        $fresh = UnionCareItem::query()->findOrFail($item->id);
        $this->assertInstanceOf(User::class, $fresh->inTheCareOf);
    }

    public function test_union_type_still_rejected_outside_morph_to(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must have a single named type');

        (new \SchemaCraft\Scanner\SchemaScanner(InvalidUnionColumnSchema::class))->scan();
    }

    // ─── helpers ────────────────────────────────────────────────

    private function makeUser(string $email = 'author@example.com'): User
    {
        $user = new User;
        $user->name = 'Author';
        $user->email = $email;
        $user->password = 'secret';
        $user->save();

        return $user;
    }

    private function makePost(): Post
    {
        $post = new Post;
        $post->title = 'Title';
        $post->slug = 'slug-'.uniqid();
        $post->subtitle = 'Subtitle';
        $post->price = 10;
        $post->author_id = $this->makeUser('a'.uniqid().'@example.com')->id;
        $post->save();

        return $post;
    }

    private function makeComment(): Comment
    {
        $comment = new Comment;
        $comment->body = 'A comment';
        $comment->user_id = $this->makeUser('c'.uniqid().'@example.com')->id;

        return $comment;
    }
}

// ─── fixtures for the camel-property/interface-typed shape ─────────────────────
// Defined here (not tests/Fixtures) because they exist solely to pin this recipe.

/** The morph-target contract. Targets implement it; the schema property is typed against it. */
interface CareOfContract {}

class CareItemSchema extends \SchemaCraft\Schema
{
    #[\SchemaCraft\Attributes\Primary]
    #[\SchemaCraft\Attributes\AutoIncrement]
    public int $id;

    public string $label;

    /**
     * @var Post|User — document the allowed targets in the docblock; the scanner requires a
     *                  single named type, so the property is typed against the interface.
     */
    #[\SchemaCraft\Attributes\Relations\MorphTo('in_the_care_of')]
    public ?CareOfContract $inTheCareOf;
}

/** @mixin CareItemSchema */
class CareItem extends SchemaModel
{
    protected static string $schema = CareItemSchema::class;
}

class UnionCareItemSchema extends \SchemaCraft\Schema
{
    #[\SchemaCraft\Attributes\Primary]
    #[\SchemaCraft\Attributes\AutoIncrement]
    public int $id;

    public string $label;

    // The union IS the documentation of allowed targets — no contract interface required.
    // `A|B|null` is the nullable form (PHP forbids `?` on a union type).
    #[\SchemaCraft\Attributes\Relations\MorphTo('in_the_care_of')]
    public Post|User|null $inTheCareOf;
}

/** @mixin UnionCareItemSchema */
class UnionCareItem extends SchemaModel
{
    protected static string $schema = UnionCareItemSchema::class;
}

/** Union on a plain scalar column stays fatal — only #[MorphTo] properties may be unions. */
class InvalidUnionColumnSchema extends \SchemaCraft\Schema
{
    #[\SchemaCraft\Attributes\Primary]
    #[\SchemaCraft\Attributes\AutoIncrement]
    public int $id;

    public int|string $mixed_column;
}
