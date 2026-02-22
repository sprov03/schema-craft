<?php

namespace SchemaCraft\Tests\Feature;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Schema;
use SchemaCraft\Tests\Fixtures\Schemas\PostSchema;
use SchemaCraft\Tests\Fixtures\Schemas\ValidationTestSchema;

class SchemaValidationRulesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::clearScanCache();
    }

    // ─── createRules — basic usage ──────────────────────────────

    public function test_create_rules_returns_rule_set(): void
    {
        $rules = ValidationTestSchema::createRules(['title', 'slug', 'body'])->toArray();

        $this->assertIsArray($rules);
        $this->assertArrayHasKey('title', $rules);
        $this->assertArrayHasKey('slug', $rules);
        $this->assertArrayHasKey('body', $rules);
    }

    public function test_create_rules_for_string_column(): void
    {
        $rules = ValidationTestSchema::createRules(['title'])->toArray();

        $this->assertContains('required', $rules['title']);
        $this->assertContains('string', $rules['title']);
        $this->assertContains('max:255', $rules['title']);
    }

    public function test_create_rules_for_string_column_with_length(): void
    {
        $rules = ValidationTestSchema::createRules(['slug'])->toArray();

        $this->assertContains('required', $rules['slug']);
        $this->assertContains('string', $rules['slug']);
        $this->assertContains('max:100', $rules['slug']);
    }

    public function test_create_rules_for_nullable_text_column(): void
    {
        $rules = ValidationTestSchema::createRules(['body'])->toArray();

        $this->assertContains('nullable', $rules['body']);
        $this->assertNotContains('required', $rules['body']);
        $this->assertContains('string', $rules['body']);
    }

    public function test_create_rules_for_boolean_column(): void
    {
        $rules = ValidationTestSchema::createRules(['is_active'])->toArray();

        $this->assertContains('required', $rules['is_active']);
        $this->assertContains('boolean', $rules['is_active']);
    }

    public function test_create_rules_for_integer_column(): void
    {
        $rules = ValidationTestSchema::createRules(['view_count'])->toArray();

        $this->assertContains('required', $rules['view_count']);
        $this->assertContains('integer', $rules['view_count']);
    }

    public function test_create_rules_for_nullable_timestamp(): void
    {
        $rules = ValidationTestSchema::createRules(['published_at'])->toArray();

        $this->assertContains('nullable', $rules['published_at']);
        $this->assertContains('date', $rules['published_at']);
    }

    public function test_create_rules_for_json_column(): void
    {
        $rules = ValidationTestSchema::createRules(['metadata'])->toArray();

        $this->assertContains('required', $rules['metadata']);
        $this->assertContains('array', $rules['metadata']);
    }

    // ─── createRules — unique constraint ────────────────────────

    public function test_create_rules_includes_unique_rule(): void
    {
        $rules = ValidationTestSchema::createRules(['slug'])->toArray();

        $hasUnique = false;
        foreach ($rules['slug'] as $rule) {
            if (is_string($rule) && str_contains($rule, 'unique:')) {
                $hasUnique = true;
                $this->assertStringContainsString('validation_tests,slug', $rule);
            }
        }
        $this->assertTrue($hasUnique, 'Unique rule should be present');
    }

    // ─── createRules — enum cast ────────────────────────────────

    public function test_create_rules_includes_enum_rule(): void
    {
        $rules = ValidationTestSchema::createRules(['status'])->toArray();

        $this->assertContains('required', $rules['status']);
        $this->assertContains('string', $rules['status']);

        $hasEnum = false;
        foreach ($rules['status'] as $rule) {
            if (is_string($rule) && str_contains($rule, 'enum:')) {
                $hasEnum = true;
            }
        }
        $this->assertTrue($hasEnum, 'Enum rule should be present for enum cast column');
    }

    // ─── createRules — BelongsTo FK ─────────────────────────────

    public function test_create_rules_includes_exists_for_belongs_to(): void
    {
        $rules = ValidationTestSchema::createRules(['author_id'])->toArray();

        $this->assertContains('required', $rules['author_id']);
        $this->assertContains('integer', $rules['author_id']);
        $this->assertContains('exists:users,id', $rules['author_id']);
    }

    public function test_create_rules_nullable_belongs_to_fk(): void
    {
        $rules = ValidationTestSchema::createRules(['editor_id'])->toArray();

        $this->assertContains('nullable', $rules['editor_id']);
        $this->assertNotContains('required', $rules['editor_id']);
        $this->assertContains('integer', $rules['editor_id']);
        $this->assertContains('exists:users,id', $rules['editor_id']);
    }

    // ─── createRules — #[Rules] attribute ───────────────────────

    public function test_rules_attribute_appends_to_inferred(): void
    {
        $rules = ValidationTestSchema::createRules(['title'])->toArray();

        // Title has #[Rules('min:3')] which should be appended
        $this->assertContains('required', $rules['title']);
        $this->assertContains('string', $rules['title']);
        $this->assertContains('max:255', $rules['title']);
        $this->assertContains('min:3', $rules['title']);
    }

    // ─── createRules — excludes primary key ─────────────────────

    public function test_create_rules_excludes_primary_key(): void
    {
        $rules = ValidationTestSchema::createRules(['id', 'title'])->toArray();

        $this->assertArrayNotHasKey('id', $rules);
        $this->assertArrayHasKey('title', $rules);
    }

    // ─── createRules — skips unknown fields ─────────────────────

    public function test_create_rules_skips_unknown_fields(): void
    {
        $rules = ValidationTestSchema::createRules(['title', 'nonexistent'])->toArray();

        $this->assertArrayHasKey('title', $rules);
        $this->assertArrayNotHasKey('nonexistent', $rules);
    }

    // ─── updateRules ────────────────────────────────────────────

    public function test_update_rules_uses_required_for_non_nullable(): void
    {
        $rules = ValidationTestSchema::updateRules(['title'])->toArray();

        $this->assertContains('required', $rules['title']);
        $this->assertNotContains('sometimes', $rules['title']);
        $this->assertContains('string', $rules['title']);
    }

    public function test_update_rules_nullable_stays_nullable(): void
    {
        $rules = ValidationTestSchema::updateRules(['body'])->toArray();

        $this->assertContains('nullable', $rules['body']);
        $this->assertNotContains('sometimes', $rules['body']);
    }

    public function test_update_rules_unique_includes_ignore(): void
    {
        $rules = ValidationTestSchema::updateRules(['slug'])->toArray();

        $hasIgnore = false;
        foreach ($rules['slug'] as $rule) {
            if (is_string($rule) && str_contains($rule, 'ignore:')) {
                $hasIgnore = true;
                $this->assertStringContainsString("ignore:\$this->route('validationTest')", $rule);
            }
        }
        $this->assertTrue($hasIgnore, 'Unique update rule should include ignore');
    }

    // ─── RuleSet merge via Schema ───────────────────────────────

    public function test_create_rules_merge_overrides_nullable(): void
    {
        $rules = ValidationTestSchema::createRules(['body'])
            ->merge(['body' => ['required']])
            ->toArray();

        $this->assertContains('required', $rules['body']);
        $this->assertNotContains('nullable', $rules['body']);
        $this->assertContains('string', $rules['body']);
    }

    public function test_create_rules_merge_adds_extra_rules(): void
    {
        $rules = ValidationTestSchema::createRules(['title'])
            ->merge(['title' => ['regex:/^[A-Z]/']])
            ->toArray();

        $this->assertContains('required', $rules['title']);
        $this->assertContains('string', $rules['title']);
        $this->assertContains('regex:/^[A-Z]/', $rules['title']);
    }

    // ─── Multiple fields at once ────────────────────────────────

    public function test_create_rules_multiple_fields(): void
    {
        $rules = ValidationTestSchema::createRules([
            'title', 'slug', 'body', 'is_active', 'view_count', 'published_at', 'author_id',
        ])->toArray();

        $this->assertCount(7, $rules);
        $this->assertArrayHasKey('title', $rules);
        $this->assertArrayHasKey('slug', $rules);
        $this->assertArrayHasKey('body', $rules);
        $this->assertArrayHasKey('is_active', $rules);
        $this->assertArrayHasKey('view_count', $rules);
        $this->assertArrayHasKey('published_at', $rules);
        $this->assertArrayHasKey('author_id', $rules);
    }

    // ─── Using PostSchema (existing fixture) ────────────────────

    public function test_post_schema_create_rules(): void
    {
        $rules = PostSchema::createRules([
            'title', 'slug', 'subtitle', 'body', 'is_featured', 'author_id',
        ])->toArray();

        // title: required, string, max:255
        $this->assertContains('required', $rules['title']);
        $this->assertContains('string', $rules['title']);

        // slug: required, string, max:255, unique
        $this->assertContains('required', $rules['slug']);
        $hasUnique = false;
        foreach ($rules['slug'] as $rule) {
            if (is_string($rule) && str_contains($rule, 'unique:')) {
                $hasUnique = true;
            }
        }
        $this->assertTrue($hasUnique);

        // subtitle: required, string, max:100
        $this->assertContains('required', $rules['subtitle']);
        $this->assertContains('max:100', $rules['subtitle']);

        // body: nullable (it's ?string), string
        $this->assertContains('nullable', $rules['body']);

        // is_featured: required, boolean
        $this->assertContains('required', $rules['is_featured']);
        $this->assertContains('boolean', $rules['is_featured']);

        // author_id: required, integer, min:0, exists
        $this->assertContains('required', $rules['author_id']);
        $this->assertContains('exists:users,id', $rules['author_id']);
    }

    public function test_post_schema_nullable_category_fk(): void
    {
        $rules = PostSchema::createRules(['category_id'])->toArray();

        $this->assertContains('nullable', $rules['category_id']);
        $this->assertContains('exists:categories,id', $rules['category_id']);
    }

    // ─── Caching ────────────────────────────────────────────────

    public function test_schema_scan_cache_works(): void
    {
        // Call twice — second call should use cache
        $rules1 = ValidationTestSchema::createRules(['title'])->toArray();
        $rules2 = ValidationTestSchema::createRules(['title'])->toArray();

        $this->assertEquals($rules1, $rules2);
    }

    public function test_clear_scan_cache(): void
    {
        ValidationTestSchema::createRules(['title']);
        Schema::clearScanCache();

        // Should work fine after clearing cache
        $rules = ValidationTestSchema::createRules(['title'])->toArray();
        $this->assertArrayHasKey('title', $rules);
    }
}
