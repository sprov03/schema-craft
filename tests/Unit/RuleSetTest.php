<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Validation\RuleSet;

class RuleSetTest extends TestCase
{
    // ─── toArray ────────────────────────────────────────────────

    public function test_to_array_returns_rules(): void
    {
        $ruleSet = new RuleSet([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
        ]);

        $this->assertEquals([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
        ], $ruleSet->toArray());
    }

    // ─── merge — basic append ───────────────────────────────────

    public function test_merge_appends_new_rules_to_existing_field(): void
    {
        $ruleSet = new RuleSet([
            'title' => ['required', 'string', 'max:255'],
        ]);

        $result = $ruleSet->merge([
            'title' => ['min:3'],
        ]);

        $this->assertEquals(
            ['required', 'string', 'max:255', 'min:3'],
            $result->toArray()['title']
        );
    }

    public function test_merge_does_not_duplicate_existing_rules(): void
    {
        $ruleSet = new RuleSet([
            'title' => ['required', 'string', 'max:255'],
        ]);

        $result = $ruleSet->merge([
            'title' => ['string', 'min:3'],
        ]);

        $this->assertEquals(
            ['required', 'string', 'max:255', 'min:3'],
            $result->toArray()['title']
        );
    }

    public function test_merge_adds_new_field(): void
    {
        $ruleSet = new RuleSet([
            'title' => ['required', 'string'],
        ]);

        $result = $ruleSet->merge([
            'extra_field' => ['required', 'integer'],
        ]);

        $this->assertEquals([
            'title' => ['required', 'string'],
            'extra_field' => ['required', 'integer'],
        ], $result->toArray());
    }

    // ─── merge — presence rule replacement ──────────────────────

    public function test_merge_required_replaces_nullable(): void
    {
        $ruleSet = new RuleSet([
            'body' => ['nullable', 'string'],
        ]);

        $result = $ruleSet->merge([
            'body' => ['required'],
        ]);

        $rules = $result->toArray()['body'];
        $this->assertContains('required', $rules);
        $this->assertNotContains('nullable', $rules);
        $this->assertContains('string', $rules);
    }

    public function test_merge_nullable_replaces_required(): void
    {
        $ruleSet = new RuleSet([
            'title' => ['required', 'string', 'max:255'],
        ]);

        $result = $ruleSet->merge([
            'title' => ['nullable'],
        ]);

        $rules = $result->toArray()['title'];
        $this->assertContains('nullable', $rules);
        $this->assertNotContains('required', $rules);
        $this->assertContains('string', $rules);
        $this->assertContains('max:255', $rules);
    }

    public function test_merge_nullable_replaces_sometimes(): void
    {
        $ruleSet = new RuleSet([
            'title' => ['sometimes', 'string', 'max:255'],
        ]);

        $result = $ruleSet->merge([
            'title' => ['nullable'],
        ]);

        $rules = $result->toArray()['title'];
        $this->assertContains('nullable', $rules);
        $this->assertNotContains('sometimes', $rules);
    }

    public function test_merge_required_replaces_sometimes(): void
    {
        $ruleSet = new RuleSet([
            'title' => ['sometimes', 'string', 'max:255'],
        ]);

        $result = $ruleSet->merge([
            'title' => ['required'],
        ]);

        $rules = $result->toArray()['title'];
        $this->assertContains('required', $rules);
        $this->assertNotContains('sometimes', $rules);
    }

    public function test_merge_presence_rule_with_additional_rules(): void
    {
        $ruleSet = new RuleSet([
            'body' => ['nullable', 'string'],
        ]);

        $result = $ruleSet->merge([
            'body' => ['required', 'min:10'],
        ]);

        $rules = $result->toArray()['body'];
        $this->assertEquals('required', $rules[0]);
        $this->assertContains('string', $rules);
        $this->assertContains('min:10', $rules);
        $this->assertNotContains('nullable', $rules);
    }

    // ─── merge — immutability ───────────────────────────────────

    public function test_merge_returns_new_instance(): void
    {
        $original = new RuleSet([
            'title' => ['required', 'string'],
        ]);

        $merged = $original->merge([
            'title' => ['min:3'],
        ]);

        // Original should not be modified
        $this->assertEquals(['required', 'string'], $original->toArray()['title']);
        $this->assertEquals(['required', 'string', 'min:3'], $merged->toArray()['title']);
    }

    // ─── only ───────────────────────────────────────────────────

    public function test_only_filters_to_specified_fields(): void
    {
        $ruleSet = new RuleSet([
            'title' => ['required', 'string'],
            'body' => ['nullable', 'string'],
            'slug' => ['required', 'string'],
        ]);

        $result = $ruleSet->only(['title', 'slug']);

        $this->assertEquals([
            'title' => ['required', 'string'],
            'slug' => ['required', 'string'],
        ], $result->toArray());
    }

    public function test_only_with_single_string(): void
    {
        $ruleSet = new RuleSet([
            'title' => ['required', 'string'],
            'body' => ['nullable', 'string'],
        ]);

        $result = $ruleSet->only('title');

        $this->assertEquals([
            'title' => ['required', 'string'],
        ], $result->toArray());
    }

    public function test_only_returns_new_instance(): void
    {
        $original = new RuleSet([
            'title' => ['required', 'string'],
            'body' => ['nullable', 'string'],
        ]);

        $filtered = $original->only('title');

        $this->assertCount(2, $original->toArray());
        $this->assertCount(1, $filtered->toArray());
    }

    // ─── except ─────────────────────────────────────────────────

    public function test_except_removes_specified_fields(): void
    {
        $ruleSet = new RuleSet([
            'title' => ['required', 'string'],
            'body' => ['nullable', 'string'],
            'slug' => ['required', 'string'],
        ]);

        $result = $ruleSet->except(['body']);

        $this->assertEquals([
            'title' => ['required', 'string'],
            'slug' => ['required', 'string'],
        ], $result->toArray());
    }

    public function test_except_with_single_string(): void
    {
        $ruleSet = new RuleSet([
            'title' => ['required', 'string'],
            'body' => ['nullable', 'string'],
        ]);

        $result = $ruleSet->except('body');

        $this->assertEquals([
            'title' => ['required', 'string'],
        ], $result->toArray());
    }

    public function test_except_returns_new_instance(): void
    {
        $original = new RuleSet([
            'title' => ['required', 'string'],
            'body' => ['nullable', 'string'],
        ]);

        $filtered = $original->except('body');

        $this->assertCount(2, $original->toArray());
        $this->assertCount(1, $filtered->toArray());
    }

    // ─── chaining ───────────────────────────────────────────────

    public function test_merge_then_only(): void
    {
        $ruleSet = new RuleSet([
            'title' => ['required', 'string'],
            'body' => ['nullable', 'string'],
            'slug' => ['required', 'string'],
        ]);

        $result = $ruleSet
            ->merge(['title' => ['min:3']])
            ->only(['title', 'body']);

        $this->assertEquals([
            'title' => ['required', 'string', 'min:3'],
            'body' => ['nullable', 'string'],
        ], $result->toArray());
    }

    public function test_merge_then_except(): void
    {
        $ruleSet = new RuleSet([
            'title' => ['required', 'string'],
            'body' => ['nullable', 'string'],
        ]);

        $result = $ruleSet
            ->merge(['body' => ['required']])
            ->except('title');

        $rules = $result->toArray();
        $this->assertCount(1, $rules);
        $this->assertContains('required', $rules['body']);
        $this->assertNotContains('nullable', $rules['body']);
    }

    // ─── edge cases ─────────────────────────────────────────────

    public function test_merge_with_empty_overrides(): void
    {
        $ruleSet = new RuleSet([
            'title' => ['required', 'string'],
        ]);

        $result = $ruleSet->merge([]);

        $this->assertEquals($ruleSet->toArray(), $result->toArray());
    }

    public function test_only_with_nonexistent_field(): void
    {
        $ruleSet = new RuleSet([
            'title' => ['required', 'string'],
        ]);

        $result = $ruleSet->only(['nonexistent']);

        $this->assertEquals([], $result->toArray());
    }

    public function test_except_with_nonexistent_field(): void
    {
        $ruleSet = new RuleSet([
            'title' => ['required', 'string'],
        ]);

        $result = $ruleSet->except(['nonexistent']);

        $this->assertEquals(['title' => ['required', 'string']], $result->toArray());
    }

    public function test_empty_rule_set(): void
    {
        $ruleSet = new RuleSet([]);

        $this->assertEquals([], $ruleSet->toArray());
    }

    public function test_presence_rule_goes_to_position_zero(): void
    {
        $ruleSet = new RuleSet([
            'body' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $ruleSet->merge([
            'body' => ['required'],
        ]);

        $rules = $result->toArray()['body'];
        $this->assertEquals('required', $rules[0], 'Presence rule should be at position 0');
    }
}
