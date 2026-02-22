<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Attributes\Rules;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Scanner\RelationshipDefinition;
use SchemaCraft\Tests\Fixtures\Enums\PostStatus;
use SchemaCraft\Validation\ValidationRuleMapper;

class ValidationRuleMapperTest extends TestCase
{
    // ─── String columns ─────────────────────────────────────────

    public function test_string_column_with_default_length(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $column = new ColumnDefinition(name: 'title', columnType: 'string');

        $rules = $mapper->createRules($column);

        $this->assertEquals(['required', 'string', 'max:255'], $rules);
    }

    public function test_string_column_with_custom_length(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $column = new ColumnDefinition(name: 'subtitle', columnType: 'string', length: 100);

        $rules = $mapper->createRules($column);

        $this->assertEquals(['required', 'string', 'max:100'], $rules);
    }

    public function test_text_column(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $column = new ColumnDefinition(name: 'body', columnType: 'text');

        $rules = $mapper->createRules($column);

        $this->assertEquals(['required', 'string'], $rules);
    }

    public function test_medium_text_column(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $column = new ColumnDefinition(name: 'content', columnType: 'mediumText');

        $rules = $mapper->createRules($column);

        $this->assertEquals(['required', 'string'], $rules);
    }

    public function test_long_text_column(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $column = new ColumnDefinition(name: 'content', columnType: 'longText');

        $rules = $mapper->createRules($column);

        $this->assertEquals(['required', 'string'], $rules);
    }

    // ─── Integer columns ────────────────────────────────────────

    public function test_integer_column(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $column = new ColumnDefinition(name: 'view_count', columnType: 'integer');

        $rules = $mapper->createRules($column);

        $this->assertEquals(['required', 'integer'], $rules);
    }

    public function test_big_integer_column(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $column = new ColumnDefinition(name: 'big_count', columnType: 'bigInteger');

        $rules = $mapper->createRules($column);

        $this->assertEquals(['required', 'integer'], $rules);
    }

    public function test_small_integer_column(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $column = new ColumnDefinition(name: 'small_val', columnType: 'smallInteger');

        $rules = $mapper->createRules($column);

        $this->assertEquals(['required', 'integer'], $rules);
    }

    public function test_tiny_integer_column(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $column = new ColumnDefinition(name: 'tiny_val', columnType: 'tinyInteger');

        $rules = $mapper->createRules($column);

        $this->assertEquals(['required', 'integer'], $rules);
    }

    public function test_unsigned_big_integer_column(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $column = new ColumnDefinition(name: 'count', columnType: 'unsignedBigInteger', unsigned: true);

        $rules = $mapper->createRules($column);

        $this->assertEquals(['required', 'integer', 'min:0'], $rules);
    }

    public function test_unsigned_integer_column(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $column = new ColumnDefinition(name: 'count', columnType: 'unsignedInteger', unsigned: true);

        $rules = $mapper->createRules($column);

        $this->assertEquals(['required', 'integer', 'min:0'], $rules);
    }

    // ─── Boolean columns ────────────────────────────────────────

    public function test_boolean_column(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $column = new ColumnDefinition(name: 'is_featured', columnType: 'boolean');

        $rules = $mapper->createRules($column);

        $this->assertEquals(['required', 'boolean'], $rules);
    }

    // ─── Numeric columns ────────────────────────────────────────

    public function test_decimal_column(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $column = new ColumnDefinition(name: 'price', columnType: 'decimal', precision: 10, scale: 2);

        $rules = $mapper->createRules($column);

        $this->assertEquals(['required', 'numeric'], $rules);
    }

    public function test_float_column(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $column = new ColumnDefinition(name: 'rating', columnType: 'float');

        $rules = $mapper->createRules($column);

        $this->assertEquals(['required', 'numeric'], $rules);
    }

    public function test_double_column(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $column = new ColumnDefinition(name: 'amount', columnType: 'double');

        $rules = $mapper->createRules($column);

        $this->assertEquals(['required', 'numeric'], $rules);
    }

    // ─── Date/time columns ──────────────────────────────────────

    public function test_timestamp_column(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $column = new ColumnDefinition(name: 'published_at', columnType: 'timestamp');

        $rules = $mapper->createRules($column);

        $this->assertEquals(['required', 'date'], $rules);
    }

    public function test_date_time_column(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $column = new ColumnDefinition(name: 'starts_at', columnType: 'dateTime');

        $rules = $mapper->createRules($column);

        $this->assertEquals(['required', 'date'], $rules);
    }

    public function test_date_time_tz_column(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $column = new ColumnDefinition(name: 'starts_at', columnType: 'dateTimeTz');

        $rules = $mapper->createRules($column);

        $this->assertEquals(['required', 'date'], $rules);
    }

    public function test_date_column(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $column = new ColumnDefinition(name: 'birth_date', columnType: 'date');

        $rules = $mapper->createRules($column);

        $this->assertEquals(['required', 'date'], $rules);
    }

    public function test_time_column(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $column = new ColumnDefinition(name: 'start_time', columnType: 'time');

        $rules = $mapper->createRules($column);

        $this->assertEquals(['required', 'date_format:H:i:s'], $rules);
    }

    public function test_time_tz_column(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $column = new ColumnDefinition(name: 'start_time', columnType: 'timeTz');

        $rules = $mapper->createRules($column);

        $this->assertEquals(['required', 'date_format:H:i:s'], $rules);
    }

    // ─── JSON columns ───────────────────────────────────────────

    public function test_json_column(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $column = new ColumnDefinition(name: 'metadata', columnType: 'json');

        $rules = $mapper->createRules($column);

        $this->assertEquals(['required', 'array'], $rules);
    }

    // ─── UUID/ULID columns ──────────────────────────────────────

    public function test_uuid_column(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $column = new ColumnDefinition(name: 'uuid', columnType: 'uuid');

        $rules = $mapper->createRules($column);

        $this->assertEquals(['required', 'string', 'uuid'], $rules);
    }

    public function test_ulid_column(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $column = new ColumnDefinition(name: 'ulid', columnType: 'ulid');

        $rules = $mapper->createRules($column);

        $this->assertEquals(['required', 'string', 'ulid'], $rules);
    }

    // ─── Year column ────────────────────────────────────────────

    public function test_year_column(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $column = new ColumnDefinition(name: 'published_year', columnType: 'year');

        $rules = $mapper->createRules($column);

        $this->assertEquals(['required', 'integer', 'digits:4'], $rules);
    }

    // ─── Nullable columns ───────────────────────────────────────

    public function test_nullable_column_uses_nullable_prefix(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $column = new ColumnDefinition(name: 'body', columnType: 'text', nullable: true);

        $rules = $mapper->createRules($column);

        $this->assertEquals(['nullable', 'string'], $rules);
    }

    public function test_nullable_string_column(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $column = new ColumnDefinition(name: 'subtitle', columnType: 'string', nullable: true, length: 100);

        $rules = $mapper->createRules($column);

        $this->assertEquals(['nullable', 'string', 'max:100'], $rules);
    }

    public function test_nullable_timestamp_column(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $column = new ColumnDefinition(name: 'published_at', columnType: 'timestamp', nullable: true);

        $rules = $mapper->createRules($column);

        $this->assertEquals(['nullable', 'date'], $rules);
    }

    // ─── Update context ─────────────────────────────────────────

    public function test_update_uses_required_for_non_nullable(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $column = new ColumnDefinition(name: 'title', columnType: 'string');

        $rules = $mapper->updateRules($column, 'post');

        $this->assertEquals(['required', 'string', 'max:255'], $rules);
    }

    public function test_update_keeps_nullable_for_nullable_column(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $column = new ColumnDefinition(name: 'body', columnType: 'text', nullable: true);

        $rules = $mapper->updateRules($column, 'post');

        $this->assertEquals(['nullable', 'string'], $rules);
    }

    // ─── Unique columns ─────────────────────────────────────────

    public function test_unique_column_in_create_context(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $column = new ColumnDefinition(name: 'slug', columnType: 'string', unique: true);

        $rules = $mapper->createRules($column);

        $this->assertEquals(['required', 'string', 'max:255', 'unique:posts,slug'], $rules);
    }

    public function test_unique_column_in_update_context(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $column = new ColumnDefinition(name: 'slug', columnType: 'string', unique: true);

        $rules = $mapper->updateRules($column, 'post');

        $this->assertContains('required', $rules);
        $this->assertContains('string', $rules);
        $this->assertContains('max:255', $rules);
        $this->assertStringContainsString('unique:posts,slug', $rules[3]);
        $this->assertStringContainsString("ignore:\$this->route('post')", $rules[3]);
    }

    // ─── Enum cast ──────────────────────────────────────────────

    public function test_enum_cast_appends_enum_rule(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $column = new ColumnDefinition(
            name: 'status',
            columnType: 'string',
            castType: PostStatus::class,
        );

        $rules = $mapper->createRules($column);

        $this->assertContains('required', $rules);
        $this->assertContains('string', $rules);
        $this->assertContains('enum:'.PostStatus::class, $rules);
    }

    // ─── BelongsTo foreign key ──────────────────────────────────

    public function test_belongs_to_fk_column_gets_exists_rule(): void
    {
        $relationships = [
            new RelationshipDefinition(
                name: 'author',
                type: 'belongsTo',
                relatedModel: 'App\Models\User',
            ),
        ];

        $mapper = new ValidationRuleMapper('posts', $relationships);
        $column = new ColumnDefinition(
            name: 'author_id',
            columnType: 'unsignedBigInteger',
            unsigned: true,
            castType: 'integer',
        );

        $rules = $mapper->createRules($column);

        $this->assertContains('required', $rules);
        $this->assertContains('integer', $rules);
        $this->assertContains('min:0', $rules);
        $this->assertContains('exists:users,id', $rules);
    }

    public function test_belongs_to_with_custom_fk_column(): void
    {
        $relationships = [
            new RelationshipDefinition(
                name: 'owner',
                type: 'belongsTo',
                relatedModel: 'App\Models\User',
                foreignColumn: 'owner_user_id',
            ),
        ];

        $mapper = new ValidationRuleMapper('posts', $relationships);
        $column = new ColumnDefinition(
            name: 'owner_user_id',
            columnType: 'unsignedBigInteger',
            unsigned: true,
            castType: 'integer',
        );

        $rules = $mapper->createRules($column);

        $this->assertContains('exists:users,id', $rules);
    }

    public function test_nullable_belongs_to_fk_column(): void
    {
        $relationships = [
            new RelationshipDefinition(
                name: 'category',
                type: 'belongsTo',
                relatedModel: 'App\Models\Category',
                nullable: true,
            ),
        ];

        $mapper = new ValidationRuleMapper('posts', $relationships);
        $column = new ColumnDefinition(
            name: 'category_id',
            columnType: 'unsignedBigInteger',
            nullable: true,
            unsigned: true,
            castType: 'integer',
        );

        $rules = $mapper->createRules($column);

        $this->assertContains('nullable', $rules);
        $this->assertNotContains('required', $rules);
        $this->assertContains('integer', $rules);
        $this->assertContains('exists:categories,id', $rules);
    }

    public function test_non_fk_column_does_not_get_exists_rule(): void
    {
        $relationships = [
            new RelationshipDefinition(
                name: 'author',
                type: 'belongsTo',
                relatedModel: 'App\Models\User',
            ),
        ];

        $mapper = new ValidationRuleMapper('posts', $relationships);
        $column = new ColumnDefinition(name: 'title', columnType: 'string');

        $rules = $mapper->createRules($column);

        foreach ($rules as $rule) {
            if (is_string($rule)) {
                $this->assertStringNotContainsString('exists:', $rule);
            }
        }
    }

    public function test_has_many_relationship_does_not_produce_exists(): void
    {
        $relationships = [
            new RelationshipDefinition(
                name: 'comments',
                type: 'hasMany',
                relatedModel: 'App\Models\Comment',
            ),
        ];

        $mapper = new ValidationRuleMapper('posts', $relationships);
        $column = new ColumnDefinition(name: 'view_count', columnType: 'integer');

        $rules = $mapper->createRules($column);

        $this->assertEquals(['required', 'integer'], $rules);
    }

    // ─── #[Rules] attribute ─────────────────────────────────────

    public function test_rules_attribute_appends_to_inferred_rules(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $rulesAttr = new Rules('min:3', 'regex:/^[a-z]/');
        $column = new ColumnDefinition(
            name: 'slug',
            columnType: 'string',
            attributes: [$rulesAttr],
        );

        $rules = $mapper->createRules($column);

        $this->assertEquals(['required', 'string', 'max:255', 'min:3', 'regex:/^[a-z]/'], $rules);
    }

    public function test_rules_attribute_with_other_attributes(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $rulesAttr = new Rules('min:3');
        // Simulate other attributes being present alongside Rules
        $otherAttr = new \SchemaCraft\Attributes\Unique;
        $column = new ColumnDefinition(
            name: 'slug',
            columnType: 'string',
            unique: true,
            attributes: [$otherAttr, $rulesAttr],
        );

        $rules = $mapper->createRules($column);

        $this->assertContains('required', $rules);
        $this->assertContains('string', $rules);
        $this->assertContains('max:255', $rules);
        $this->assertContains('min:3', $rules);
        // Unique rule also present
        $this->assertContains('unique:posts,slug', $rules);
    }

    // ─── Combination tests ──────────────────────────────────────

    public function test_nullable_unique_string_column(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $column = new ColumnDefinition(
            name: 'email',
            columnType: 'string',
            nullable: true,
            unique: true,
            length: 200,
        );

        $rules = $mapper->createRules($column);

        $this->assertContains('nullable', $rules);
        $this->assertNotContains('required', $rules);
        $this->assertContains('string', $rules);
        $this->assertContains('max:200', $rules);
        $this->assertContains('unique:posts,email', $rules);
    }

    public function test_update_nullable_unique_string_column(): void
    {
        $mapper = new ValidationRuleMapper('posts');
        $column = new ColumnDefinition(
            name: 'email',
            columnType: 'string',
            nullable: true,
            unique: true,
            length: 200,
        );

        $rules = $mapper->updateRules($column, 'post');

        $this->assertContains('nullable', $rules);
        $this->assertNotContains('sometimes', $rules);
        $this->assertContains('string', $rules);
        $this->assertContains('max:200', $rules);
        $this->assertStringContainsString('unique:posts,email', $rules[3]);
        $this->assertStringContainsString("ignore:\$this->route('post')", $rules[3]);
    }
}
