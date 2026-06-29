<?php

namespace SchemaCraft\Tests\Feature;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema as DbSchema;
use SchemaCraft\Scanner\SchemaScanner;
use SchemaCraft\SchemaModel;
use SchemaCraft\Tests\Fixtures\Models\CatalogBrand;
use SchemaCraft\Tests\Fixtures\Models\CatalogSupplier;
use SchemaCraft\Tests\Fixtures\Models\Comment;
use SchemaCraft\Tests\Fixtures\Models\SchemaRefPost;
use SchemaCraft\Tests\Fixtures\Models\Tag;
use SchemaCraft\Tests\Fixtures\Models\User;
use SchemaCraft\Tests\Fixtures\Schemas\SchemaRefPostSchema;
use SchemaCraft\Tests\TestCase;

/**
 * Proves a relation attribute may reference another *Schema* class and that the
 * scanner collapses it to the underlying Model the instant it is read — so the
 * Schema form behaves identically to the historical Model form.
 *
 * The "works now" half of each concern is the Model-form coverage in
 * SchemaScannerTest / SchemaModelBootTest; this class is the Schema-form half.
 */
class SchemaRefRelationTest extends TestCase
{
    // ─── Scan-level: Schema refs resolve to Model FQCNs ──────────

    public function test_belongs_to_schema_ref_resolves_to_model(): void
    {
        $table = (new SchemaScanner(SchemaRefPostSchema::class))->scan();
        $author = $this->findRelationship($table, 'author');

        $this->assertNotNull($author);
        $this->assertEquals('belongsTo', $author->type);
        $this->assertEquals(User::class, $author->relatedModel);
    }

    public function test_has_many_schema_ref_resolves_to_model(): void
    {
        $table = (new SchemaScanner(SchemaRefPostSchema::class))->scan();
        $comments = $this->findRelationship($table, 'comments');

        $this->assertNotNull($comments);
        $this->assertEquals('hasMany', $comments->type);
        $this->assertEquals(Comment::class, $comments->relatedModel);
    }

    public function test_belongs_to_many_schema_ref_resolves_to_model(): void
    {
        $table = (new SchemaScanner(SchemaRefPostSchema::class))->scan();
        $tags = $this->findRelationship($table, 'tags');

        $this->assertNotNull($tags);
        $this->assertEquals('belongsToMany', $tags->type);
        $this->assertEquals(Tag::class, $tags->relatedModel);
    }

    public function test_has_many_through_resolves_both_related_and_through_schema_refs(): void
    {
        $table = (new SchemaScanner(SchemaRefPostSchema::class))->scan();
        $brandSuppliers = $this->findRelationship($table, 'brandSuppliers');

        $this->assertNotNull($brandSuppliers);
        $this->assertEquals('hasManyThrough', $brandSuppliers->type);
        // Both the related class AND the `through` class were declared as Schemas.
        $this->assertEquals(CatalogSupplier::class, $brandSuppliers->relatedModel);
        $this->assertEquals(CatalogBrand::class, $brandSuppliers->through);
    }

    // ─── Runtime: Eloquent builds the relation from a Schema ref ──

    public function test_belongs_to_schema_ref_works_at_runtime(): void
    {
        $this->createSchemaRefTables();

        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'secret']);
        $post = SchemaRefPost::create(['title' => 'Hello', 'author_id' => $user->id]);

        $this->assertInstanceOf(User::class, $post->author);
        $this->assertEquals($user->id, $post->author->id);
    }

    public function test_has_many_schema_ref_works_at_runtime(): void
    {
        $this->createSchemaRefTables();

        $post = SchemaRefPost::create(['title' => 'Hello', 'author_id' => 1]);

        $this->assertInstanceOf(EloquentCollection::class, $post->comments);
        $this->assertCount(0, $post->comments);
    }

    private function createSchemaRefTables(): void
    {
        SchemaModel::clearSchemaCache();
        SchemaModel::clearBootedModels();

        DbSchema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });

        DbSchema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->string('body');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('commentable_type')->nullable();
            $table->unsignedBigInteger('commentable_id')->nullable();
            // hasMany Comment off SchemaRefPost is keyed by schema_ref_post_id by convention
            $table->unsignedBigInteger('schema_ref_post_id')->nullable();
            $table->timestamps();
        });

        DbSchema::create('schema_ref_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->unsignedBigInteger('author_id');
            $table->timestamps();
        });
    }

    private function findRelationship($table, string $name)
    {
        foreach ($table->relationships as $rel) {
            if ($rel->name === $name) {
                return $rel;
            }
        }

        return null;
    }
}
