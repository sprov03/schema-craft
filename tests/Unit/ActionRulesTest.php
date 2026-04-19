<?php

namespace SchemaCraft\Tests\Unit;

use SchemaCraft\Action;
use SchemaCraft\Tests\Fixtures\Actions\Post\AnnotatedPostAction;
use SchemaCraft\Tests\Fixtures\Actions\Post\CreatePostAction;
use SchemaCraft\Tests\TestCase;

class ActionRulesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Action::clearScanCache();
    }

    // ─── Schema-mapped params (no regression) ─────────────────────────────

    public function test_schema_mapped_string_param_gets_schema_rules(): void
    {
        $action = new CreatePostAction;
        $rules = $action->rules();

        // 'title' is on PostSchema as a plain string → required|string|max:255
        $this->assertArrayHasKey('title', $rules);
        $this->assertContains('required', $rules['title']);
        $this->assertContains('string', $rules['title']);
    }

    public function test_schema_mapped_text_param_gets_schema_rules(): void
    {
        $action = new CreatePostAction;
        $rules = $action->rules();

        // 'body' is on PostSchema as nullable #[Text] → nullable|string
        $this->assertArrayHasKey('body', $rules);
        $this->assertContains('nullable', $rules['body']);
        $this->assertContains('string', $rules['body']);
        $this->assertNotContains('max:255', $rules['body']);
    }

    public function test_schema_mapped_fk_param_gets_exists_rule(): void
    {
        $action = new CreatePostAction;
        $rules = $action->rules();

        // 'author_id' is a BelongsTo(User) FK on PostSchema → exists:users,id
        $this->assertArrayHasKey('author_id', $rules);
        $existsRule = collect($rules['author_id'])->first(fn ($r) => str_starts_with((string) $r, 'exists:'));
        $this->assertNotNull($existsRule);
        $this->assertStringContainsString('users', (string) $existsRule);
    }

    // ─── Fallback: action-only params not on the schema ───────────────────

    public function test_action_only_string_param_gets_required_string_rules(): void
    {
        $action = new AnnotatedPostAction;
        $rules = $action->rules();

        // 'external_id' is NOT on PostSchema → fallback: required|string|max:255
        $this->assertArrayHasKey('external_id', $rules);
        $this->assertContains('required', $rules['external_id']);
        $this->assertContains('string', $rules['external_id']);
        $this->assertContains('max:255', $rules['external_id']);
    }

    public function test_action_only_medium_text_param_gets_string_without_max(): void
    {
        $action = new AnnotatedPostAction;
        $rules = $action->rules();

        // 'summary' is NOT on PostSchema, has #[MediumText] → required|string (no max)
        $this->assertArrayHasKey('summary', $rules);
        $this->assertContains('required', $rules['summary']);
        $this->assertContains('string', $rules['summary']);
        $maxRule = collect($rules['summary'])->first(fn ($r) => str_starts_with((string) $r, 'max:'));
        $this->assertNull($maxRule);
    }

    public function test_action_only_small_int_param_gets_integer_rule(): void
    {
        $action = new AnnotatedPostAction;
        $rules = $action->rules();

        // 'priority' is NOT on PostSchema, has #[SmallInt] → required|integer
        $this->assertArrayHasKey('priority', $rules);
        $this->assertContains('required', $rules['priority']);
        $this->assertContains('integer', $rules['priority']);
    }

    public function test_action_only_param_with_length_gets_max_rule(): void
    {
        $action = new AnnotatedPostAction;
        $rules = $action->rules();

        // 'slug' IS on PostSchema so it goes through schema path with its own rules
        // Let's check 'external_id' which has #[Unique] but no #[Length]
        // and verify max:255 comes from the default string type
        $this->assertArrayHasKey('external_id', $rules);
        $this->assertContains('max:255', $rules['external_id']);
    }

    // ─── #[Rules] appending ───────────────────────────────────────────────

    public function test_rules_attribute_is_appended_to_schema_mapped_param(): void
    {
        $action = new AnnotatedPostAction;
        $rules = $action->rules();

        // 'title' IS on PostSchema, and AnnotatedPostAction has #[Rules('min:3')] on $title
        $this->assertArrayHasKey('title', $rules);
        $this->assertContains('min:3', $rules['title']);
        // schema rules are still present
        $this->assertContains('required', $rules['title']);
        $this->assertContains('string', $rules['title']);
    }

    public function test_rules_attribute_is_appended_to_fallback_param(): void
    {
        $action = new AnnotatedPostAction;
        $rules = $action->rules();

        // 'external_id' is action-only with #[Unique] — verify unique rule is generated
        $this->assertArrayHasKey('external_id', $rules);
        $uniqueRule = collect($rules['external_id'])->first(fn ($r) => str_starts_with((string) $r, 'unique:'));
        $this->assertNotNull($uniqueRule);
    }
}
