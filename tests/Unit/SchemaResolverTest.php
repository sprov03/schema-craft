<?php

namespace SchemaCraft\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use SchemaCraft\Scanner\SchemaResolver;
use SchemaCraft\Tests\Fixtures\Models\Post;
use SchemaCraft\Tests\Fixtures\Schemas\PostSchema;

class SchemaResolverTest extends TestCase
{
    // ─── resolveModelClass: Schema → Model ───────────────────────

    public function test_resolves_schema_class_to_its_model(): void
    {
        $this->assertSame(Post::class, SchemaResolver::resolveModelClass(PostSchema::class));
    }

    // ─── resolveModelClass: pass-through (backwards compatibility) ─

    public function test_passes_model_fqcn_through_unchanged(): void
    {
        // A Model FQCN is not a Schema subclass — must be returned verbatim so the
        // historical #[BelongsTo(User::class)] form keeps working.
        $this->assertSame(Post::class, SchemaResolver::resolveModelClass(Post::class));
    }

    public function test_passes_morph_to_model_placeholder_through(): void
    {
        // MorphTo stores Illuminate's base Model as a placeholder — not a Schema,
        // so it must pass through untouched.
        $this->assertSame(Model::class, SchemaResolver::resolveModelClass(Model::class));
    }

    public function test_passes_arbitrary_non_schema_string_through(): void
    {
        $this->assertSame('Not\\A\\Real\\Class', SchemaResolver::resolveModelClass('Not\\A\\Real\\Class'));
    }

    // ─── symmetry with the inverse (findByModel) ─────────────────

    public function test_is_inverse_of_find_by_model(): void
    {
        $model = SchemaResolver::resolveModelClass(PostSchema::class);
        $this->assertSame(PostSchema::class, SchemaResolver::findByModel($model));
    }
}
