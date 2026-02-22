<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Generator\Api\ResourceGenerator;
use SchemaCraft\Scanner\SchemaScanner;
use SchemaCraft\Tests\Fixtures\Schemas\PivotTestSchema;
use SchemaCraft\Tests\Fixtures\Schemas\PostSchema;
use SchemaCraft\Tests\Fixtures\Schemas\UserSchema;

class ResourceGeneratorTest extends TestCase
{
    private ResourceGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new ResourceGenerator;
    }

    // ─── Basic output structure ─────────────────────────────────

    public function test_generates_valid_php(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $code = $this->generator->generate($table);

        $this->assertStringStartsWith('<?php', $code);
    }

    public function test_generates_correct_namespace(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $code = $this->generator->generate($table);

        $this->assertStringContainsString('namespace App\Resources;', $code);
    }

    public function test_generates_correct_class_name(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $code = $this->generator->generate($table);

        $this->assertStringContainsString('class PostResource extends JsonResource', $code);
    }

    public function test_custom_namespace(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $code = $this->generator->generate($table, resourceNamespace: 'App\Http\Resources');

        $this->assertStringContainsString('namespace App\Http\Resources;', $code);
    }

    // ─── Imports ────────────────────────────────────────────────

    public function test_imports_json_resource(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $code = $this->generator->generate($table);

        $this->assertStringContainsString('use Illuminate\Http\Resources\Json\JsonResource;', $code);
    }

    public function test_imports_request(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $code = $this->generator->generate($table);

        $this->assertStringContainsString('use Illuminate\Http\Request;', $code);
    }

    public function test_imports_child_resource_classes(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $code = $this->generator->generate($table);

        // PostSchema has HasMany(Comment::class) and BelongsToMany(Tag::class)
        $this->assertStringContainsString('use App\Resources\CommentResource;', $code);
        $this->assertStringContainsString('use App\Resources\TagResource;', $code);
    }

    public function test_does_not_import_belongs_to_resource(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $code = $this->generator->generate($table);

        // PostSchema has BelongsTo(User::class) — should NOT import UserResource
        $this->assertStringNotContainsString('use App\Resources\UserResource;', $code);
        // But it DOES have BelongsTo(Category::class) — also should NOT import
        $this->assertStringNotContainsString('use App\Resources\CategoryResource;', $code);
    }

    // ─── Regular columns ────────────────────────────────────────

    public function test_includes_regular_columns(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $code = $this->generator->generate($table);

        $this->assertStringContainsString("'title' => \$this->title,", $code);
        $this->assertStringContainsString("'slug' => \$this->slug,", $code);
        $this->assertStringContainsString("'subtitle' => \$this->subtitle,", $code);
        $this->assertStringContainsString("'body' => \$this->body,", $code);
    }

    public function test_includes_primary_key(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $code = $this->generator->generate($table);

        $this->assertStringContainsString("'id' => \$this->id,", $code);
    }

    public function test_includes_boolean_columns(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $code = $this->generator->generate($table);

        $this->assertStringContainsString("'is_featured' => \$this->is_featured,", $code);
    }

    // ─── FK columns from BelongsTo ──────────────────────────────

    public function test_includes_belongs_to_fk_as_id(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $code = $this->generator->generate($table);

        $this->assertStringContainsString("'author_id' => \$this->author_id,", $code);
        $this->assertStringContainsString("'category_id' => \$this->category_id,", $code);
    }

    // ─── Hidden columns ─────────────────────────────────────────

    public function test_excludes_hidden_columns(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $code = $this->generator->generate($table);

        // PostSchema has #[Hidden] on metadata
        $this->assertStringNotContainsString("'metadata'", $code);
    }

    // ─── Timestamps ─────────────────────────────────────────────

    public function test_includes_timestamps(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $code = $this->generator->generate($table);

        $this->assertStringContainsString("'created_at' => \$this->created_at,", $code);
        $this->assertStringContainsString("'updated_at' => \$this->updated_at,", $code);
    }

    // ─── Child relationships with whenLoaded ────────────────────

    public function test_has_many_uses_collection_when_loaded(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $code = $this->generator->generate($table);

        $this->assertStringContainsString(
            "'comments' => CommentResource::collection(\$this->whenLoaded('comments')),",
            $code
        );
    }

    public function test_belongs_to_many_uses_collection_when_loaded(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $code = $this->generator->generate($table);

        $this->assertStringContainsString(
            "'tags' => TagResource::collection(\$this->whenLoaded('tags')),",
            $code
        );
    }

    public function test_morph_many_uses_collection_when_loaded(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $code = $this->generator->generate($table);

        $this->assertStringContainsString(
            "'morphComments' => CommentResource::collection(\$this->whenLoaded('morphComments')),",
            $code
        );
    }

    public function test_belongs_to_relationship_not_in_when_loaded(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $code = $this->generator->generate($table);

        // BelongsTo should NOT appear as whenLoaded
        $this->assertStringNotContainsString("whenLoaded('author')", $code);
        $this->assertStringNotContainsString("whenLoaded('category')", $code);
    }

    // ─── toArray method ─────────────────────────────────────────

    public function test_has_to_array_method(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $code = $this->generator->generate($table);

        $this->assertStringContainsString('public function toArray(Request $request): array', $code);
    }

    // ─── UserSchema (simpler schema) ────────────────────────────

    public function test_user_schema_resource(): void
    {
        $table = (new SchemaScanner(UserSchema::class))->scan();
        $code = $this->generator->generate($table);

        $this->assertStringContainsString('class UserResource extends JsonResource', $code);
        $this->assertStringContainsString("'name' => \$this->name,", $code);
        $this->assertStringContainsString("'email' => \$this->email,", $code);
    }

    public function test_user_schema_excludes_hidden_password(): void
    {
        $table = (new SchemaScanner(UserSchema::class))->scan();
        $code = $this->generator->generate($table);

        // UserSchema has #[Hidden] on password
        $this->assertStringNotContainsString("'password'", $code);
    }

    // ─── No duplicate resource imports ──────────────────────────

    public function test_no_duplicate_resource_imports(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $code = $this->generator->generate($table);

        // PostSchema has both HasMany(Comment) and MorphMany(Comment) — should only import once
        $count = substr_count($code, 'use App\Resources\CommentResource;');
        $this->assertEquals(1, $count, 'CommentResource should only be imported once');
    }

    // ─── Pivot columns ──────────────────────────────────────────

    public function test_belongs_to_many_with_pivot_columns_uses_closure(): void
    {
        $table = (new SchemaScanner(PivotTestSchema::class))->scan();
        $code = $this->generator->generate($table);

        // tags has PivotColumns(['order' => 'integer', 'note' => 'string'])
        $this->assertStringContainsString("'tags' => \$this->whenLoaded('tags', function ()", $code);
        $this->assertStringContainsString('TagResource::make($item)->resolve()', $code);
        $this->assertStringContainsString("->pivot->only(['order', 'note'])", $code);
    }

    public function test_multiple_belongs_to_many_with_pivot_columns(): void
    {
        $table = (new SchemaScanner(PivotTestSchema::class))->scan();
        $code = $this->generator->generate($table);

        // members has PivotColumns(['role' => 'string'])
        $this->assertStringContainsString("'members' => \$this->whenLoaded('members', function ()", $code);
        $this->assertStringContainsString("->pivot->only(['role'])", $code);
    }

    public function test_has_many_without_pivot_uses_simple_collection(): void
    {
        $table = (new SchemaScanner(PivotTestSchema::class))->scan();
        $code = $this->generator->generate($table);

        // comments is HasMany (no pivot) — should use simple collection
        $this->assertStringContainsString(
            "'comments' => CommentResource::collection(\$this->whenLoaded('comments')),",
            $code
        );
    }

    public function test_belongs_to_many_without_pivot_uses_simple_collection(): void
    {
        // PostSchema tags has NO PivotColumns — should use simple collection
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $code = $this->generator->generate($table);

        $this->assertStringContainsString(
            "'tags' => TagResource::collection(\$this->whenLoaded('tags')),",
            $code
        );
        // Should NOT have closure-based pivot handling
        $this->assertStringNotContainsString('->pivot->only(', $code);
    }
}
