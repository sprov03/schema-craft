<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Scanner\SchemaScanner;
use SchemaCraft\Tests\Fixtures\Casts\AddressData;
use SchemaCraft\Tests\Fixtures\Enums\PostStatus;
use SchemaCraft\Tests\Fixtures\Schemas\CommentSchema;
use SchemaCraft\Tests\Fixtures\Schemas\IndexedCommentSchema;
use SchemaCraft\Tests\Fixtures\Schemas\NonStandardFkSchema;
use SchemaCraft\Tests\Fixtures\Schemas\PostSchema;
use SchemaCraft\Tests\Fixtures\Schemas\RenamedColumnSchema;
use SchemaCraft\Tests\Fixtures\Schemas\UserSchema;

class SchemaScannerTest extends TestCase
{
    public function test_resolves_table_name_from_class_name(): void
    {
        $scanner = new SchemaScanner(PostSchema::class);
        $table = $scanner->scan();

        $this->assertEquals('posts', $table->tableName);
    }

    public function test_resolves_table_name_for_user_schema(): void
    {
        $scanner = new SchemaScanner(UserSchema::class);
        $table = $scanner->scan();

        $this->assertEquals('users', $table->tableName);
    }

    public function test_detects_timestamps_schema(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();

        $this->assertTrue($table->hasTimestamps);
    }

    public function test_detects_soft_deletes_schema(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();

        $this->assertTrue($table->hasSoftDeletes);
    }

    public function test_no_soft_deletes_when_not_used(): void
    {
        $table = (new SchemaScanner(UserSchema::class))->scan();

        $this->assertFalse($table->hasSoftDeletes);
    }

    public function test_scans_id_column(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $id = $this->findColumn($table, 'id');

        $this->assertNotNull($id);
        $this->assertEquals('unsignedBigInteger', $id->columnType);
        $this->assertTrue($id->primary);
        $this->assertTrue($id->autoIncrement);
    }

    public function test_infers_string_column(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $title = $this->findColumn($table, 'title');

        $this->assertNotNull($title);
        $this->assertEquals('string', $title->columnType);
        $this->assertEquals('string', $title->castType);
        $this->assertFalse($title->nullable);
    }

    public function test_infers_nullable_string(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $body = $this->findColumn($table, 'body');

        $this->assertNotNull($body);
        $this->assertTrue($body->nullable);
        $this->assertEquals('text', $body->columnType); // Text attribute applied
    }

    public function test_text_attribute_overrides_column_type(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $body = $this->findColumn($table, 'body');

        $this->assertEquals('text', $body->columnType);
    }

    public function test_length_attribute_sets_length(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $subtitle = $this->findColumn($table, 'subtitle');

        $this->assertEquals(100, $subtitle->length);
    }

    public function test_unique_attribute(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $slug = $this->findColumn($table, 'slug');

        $this->assertTrue($slug->unique);
    }

    public function test_decimal_attribute(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $price = $this->findColumn($table, 'price');

        $this->assertEquals('decimal', $price->columnType);
        $this->assertEquals(10, $price->precision);
        $this->assertEquals(2, $price->scale);
        $this->assertTrue($price->unsigned);
    }

    public function test_infers_boolean_column(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $isFeatured = $this->findColumn($table, 'is_featured');

        $this->assertEquals('boolean', $isFeatured->columnType);
        $this->assertEquals('boolean', $isFeatured->castType);
        $this->assertTrue($isFeatured->hasDefault);
        $this->assertFalse($isFeatured->default);
    }

    public function test_infers_integer_column(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $viewCount = $this->findColumn($table, 'view_count');

        $this->assertEquals('integer', $viewCount->columnType);
        $this->assertEquals('integer', $viewCount->castType);
        $this->assertTrue($viewCount->unsigned);
        $this->assertTrue($viewCount->hasDefault);
        $this->assertEquals(0, $viewCount->default);
    }

    public function test_infers_enum_column(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $status = $this->findColumn($table, 'status');

        $this->assertEquals('string', $status->columnType); // string-backed enum
        $this->assertEquals(PostStatus::class, $status->castType);
        $this->assertTrue($status->hasDefault);
        $this->assertEquals('draft', $status->default); // backed value
    }

    public function test_infers_carbon_timestamp(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $publishedAt = $this->findColumn($table, 'published_at');

        $this->assertEquals('timestamp', $publishedAt->columnType);
        $this->assertEquals('datetime', $publishedAt->castType);
        $this->assertTrue($publishedAt->nullable);
    }

    public function test_infers_array_json(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $metadata = $this->findColumn($table, 'metadata');

        $this->assertEquals('json', $metadata->columnType);
        $this->assertEquals('array', $metadata->castType);
        $this->assertTrue($metadata->hasDefault);
        $this->assertEquals([], $metadata->default);
    }

    public function test_detects_castable_class(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $address = $this->findColumn($table, 'address');

        $this->assertNotNull($address);
        $this->assertEquals('json', $address->columnType);
        $this->assertEquals(AddressData::class, $address->castType);
        $this->assertTrue($address->nullable);
    }

    public function test_scans_belongs_to_relationship(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $author = $this->findRelationship($table, 'author');

        $this->assertNotNull($author);
        $this->assertEquals('belongsTo', $author->type);
        $this->assertEquals(\SchemaCraft\Tests\Fixtures\Models\User::class, $author->relatedModel);
        $this->assertFalse($author->nullable);
        $this->assertEquals('cascade', $author->onDelete);
    }

    public function test_belongs_to_creates_foreign_key_column(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $authorId = $this->findColumn($table, 'author_id');

        $this->assertNotNull($authorId);
        $this->assertEquals('unsignedBigInteger', $authorId->columnType);
        $this->assertTrue($authorId->unsigned);
        $this->assertTrue($authorId->index);
        $this->assertFalse($authorId->nullable);
    }

    public function test_belongs_to_without_index_attribute_has_no_index(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $categoryId = $this->findColumn($table, 'category_id');

        $this->assertNotNull($categoryId);
        $this->assertFalse($categoryId->index);
    }

    public function test_nullable_belongs_to_create_nullable_fk(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $categoryId = $this->findColumn($table, 'category_id');

        $this->assertNotNull($categoryId);
        $this->assertTrue($categoryId->nullable);
    }

    public function test_scans_has_many_relationship(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $comments = $this->findRelationship($table, 'comments');

        $this->assertNotNull($comments);
        $this->assertEquals('hasMany', $comments->type);
    }

    public function test_scans_belongs_to_many_relationship(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $tags = $this->findRelationship($table, 'tags');

        $this->assertNotNull($tags);
        $this->assertEquals('belongsToMany', $tags->type);
    }

    public function test_scans_morph_many_relationship(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $morphComments = $this->findRelationship($table, 'morphComments');

        $this->assertNotNull($morphComments);
        $this->assertEquals('morphMany', $morphComments->type);
        $this->assertEquals('commentable', $morphComments->morphName);
    }

    public function test_scans_morph_to_relationship(): void
    {
        $table = (new SchemaScanner(CommentSchema::class))->scan();
        $commentable = $this->findRelationship($table, 'commentable');

        $this->assertNotNull($commentable);
        $this->assertEquals('morphTo', $commentable->type);
        $this->assertEquals('commentable', $commentable->morphName);
    }

    public function test_morph_to_creates_two_columns(): void
    {
        $table = (new SchemaScanner(CommentSchema::class))->scan();

        $typeCol = $this->findColumn($table, 'commentable_type');
        $idCol = $this->findColumn($table, 'commentable_id');

        $this->assertNotNull($typeCol);
        $this->assertEquals('string', $typeCol->columnType);
        $this->assertFalse($typeCol->index);

        $this->assertNotNull($idCol);
        $this->assertEquals('unsignedBigInteger', $idCol->columnType);
        $this->assertFalse($idCol->index);
    }

    public function test_morph_to_with_index_attribute_creates_indexed_columns(): void
    {
        $table = (new SchemaScanner(IndexedCommentSchema::class))->scan();

        $typeCol = $this->findColumn($table, 'commentable_type');
        $idCol = $this->findColumn($table, 'commentable_id');

        $this->assertNotNull($typeCol);
        $this->assertEquals('string', $typeCol->columnType);
        $this->assertTrue($typeCol->index);

        $this->assertNotNull($idCol);
        $this->assertEquals('unsignedBigInteger', $idCol->columnType);
        $this->assertTrue($idCol->index);
    }

    public function test_composite_index(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();

        $this->assertCount(1, $table->compositeIndexes);
        $this->assertEquals(['status', 'published_at'], $table->compositeIndexes[0]);
    }

    public function test_reads_fillable_from_schema(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();

        $this->assertEquals(['title', 'slug', 'subtitle', 'body', 'status', 'price', 'author_id', 'category_id'], $table->fillable);
    }

    public function test_reads_hidden_from_schema(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();

        $this->assertEquals(['metadata'], $table->hidden);
    }

    public function test_reads_with_from_schema(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();

        $this->assertEquals(['author'], $table->with);
    }

    public function test_timestamp_columns_scanned(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $createdAt = $this->findColumn($table, 'created_at');
        $updatedAt = $this->findColumn($table, 'updated_at');

        $this->assertNotNull($createdAt);
        $this->assertEquals('timestamp', $createdAt->columnType);
        $this->assertTrue($createdAt->nullable);

        $this->assertNotNull($updatedAt);
    }

    public function test_soft_delete_column_scanned(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $deletedAt = $this->findColumn($table, 'deleted_at');

        $this->assertNotNull($deletedAt);
        $this->assertEquals('timestamp', $deletedAt->columnType);
        $this->assertTrue($deletedAt->nullable);
    }

    public function test_renamed_from_attribute_is_read(): void
    {
        $table = (new SchemaScanner(RenamedColumnSchema::class))->scan();
        $title = $this->findColumn($table, 'title');

        $this->assertNotNull($title);
        $this->assertEquals('old_title', $title->renamedFrom);
    }

    public function test_renamed_from_with_type_override(): void
    {
        $table = (new SchemaScanner(RenamedColumnSchema::class))->scan();
        $content = $this->findColumn($table, 'content');

        $this->assertNotNull($content);
        $this->assertEquals('body_text', $content->renamedFrom);
        $this->assertEquals('text', $content->columnType);
        $this->assertTrue($content->nullable);
    }

    public function test_column_type_attribute_overrides_belongs_to_fk_type(): void
    {
        $table = (new SchemaScanner(NonStandardFkSchema::class))->scan();
        $fkCol = $this->findColumn($table, 'legacy_user_id');

        $this->assertNotNull($fkCol);
        $this->assertEquals('unsignedInteger', $fkCol->columnType);
        $this->assertTrue($fkCol->unsigned);
        $this->assertTrue($fkCol->index);
    }

    public function test_column_type_attribute_overrides_morph_to_id_type(): void
    {
        $table = (new SchemaScanner(NonStandardFkSchema::class))->scan();
        $idCol = $this->findColumn($table, 'taggable_id');

        $this->assertNotNull($idCol);
        $this->assertEquals('unsignedInteger', $idCol->columnType);
        $this->assertTrue($idCol->unsigned);
        $this->assertTrue($idCol->index);
    }

    public function test_column_type_default_is_unsigned_big_integer(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $authorId = $this->findColumn($table, 'author_id');

        $this->assertNotNull($authorId);
        $this->assertEquals('unsignedBigInteger', $authorId->columnType);
    }

    public function test_default_expression_attribute_is_read(): void
    {
        $table = (new SchemaScanner(NonStandardFkSchema::class))->scan();
        $verifiedAt = $this->findColumn($table, 'verified_at');

        $this->assertNotNull($verifiedAt);
        $this->assertEquals('CURRENT_TIMESTAMP', $verifiedAt->expressionDefault);
        $this->assertTrue($verifiedAt->nullable);
    }

    public function test_default_expression_is_null_when_absent(): void
    {
        $table = (new SchemaScanner(PostSchema::class))->scan();
        $title = $this->findColumn($table, 'title');

        $this->assertNotNull($title);
        $this->assertNull($title->expressionDefault);
    }

    public function test_renamed_from_is_null_when_absent(): void
    {
        $table = (new SchemaScanner(RenamedColumnSchema::class))->scan();
        $slug = $this->findColumn($table, 'slug');

        $this->assertNotNull($slug);
        $this->assertNull($slug->renamedFrom);
    }

    private function findColumn($table, string $name)
    {
        foreach ($table->columns as $col) {
            if ($col->name === $name) {
                return $col;
            }
        }

        return null;
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
