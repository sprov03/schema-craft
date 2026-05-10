<?php

namespace SchemaCraft\Tests\Unit\Generators;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SchemaCraft\Generators\NameChain;

class NameChainTest extends TestCase
{
    // ─── Default behavior ─────────────────────────────────────────

    public function test_default_is_singular_snake_case(): void
    {
        $chain = new NameChain('user_profile');

        $this->assertSame('user_profile', (string) $chain);
    }

    public function test_normalizes_studly_input_to_snake(): void
    {
        // Studly is converted to snake_case but original form (plural) is preserved.
        $chain = new NameChain('UserProfiles');

        $this->assertSame('user_profiles', (string) $chain);
    }

    public function test_normalizes_camel_input(): void
    {
        $chain = new NameChain('salesReport');

        $this->assertSame('sales_report', (string) $chain);
    }

    public function test_preserves_plural_form(): void
    {
        // NameChain no longer silently singularizes. 'user_profiles' stays 'user_profiles'.
        // This is required so that relationship property names like 'breedings' survive
        // construction and (string) $relationship->name returns the exact PHP property name.
        $chain = new NameChain('user_profiles');

        $this->assertSame('user_profiles', (string) $chain);
    }

    public function test_relationship_name_preserved(): void
    {
        // Regression guard: a HasMany relationship named 'breedings' must not be
        // silently singularized to 'breeding' on construction.
        $chain = new NameChain('breedings');

        $this->assertSame('breedings', (string) $chain);
        $this->assertSame('breeding', (string) $chain->singular);
        $this->assertSame('Breeding', (string) $chain->singular->title);
        $this->assertSame('breedings', (string) $chain->plural);
    }

    // ─── Plurality ────────────────────────────────────────────────

    public function test_plural(): void
    {
        $chain = new NameChain('user_profile');

        $this->assertSame('user_profiles', (string) $chain->plural);
    }

    public function test_singular_is_explicit_default(): void
    {
        $chain = new NameChain('user_profile');

        $this->assertSame('user_profile', (string) $chain->singular);
    }

    public function test_plural_then_singular_reverts(): void
    {
        $chain = new NameChain('user_profile');

        $this->assertSame('user_profile', (string) $chain->plural->singular);
    }

    // ─── Casing ───────────────────────────────────────────────────

    public function test_title_casing(): void
    {
        $chain = new NameChain('user_profile');

        $this->assertSame('UserProfile', (string) $chain->title);
    }

    public function test_camel_casing(): void
    {
        $chain = new NameChain('user_profile');

        $this->assertSame('userProfile', (string) $chain->camel);
    }

    public function test_snake_casing_explicit(): void
    {
        $chain = new NameChain('user_profile');

        $this->assertSame('user_profile', (string) $chain->snake);
    }

    public function test_kebab_casing(): void
    {
        $chain = new NameChain('user_profile');

        $this->assertSame('user-profile', (string) $chain->kebab);
    }

    // ─── Combined modifiers (order independence) ──────────────────

    public function test_plural_title(): void
    {
        $chain = new NameChain('user_profile');

        $this->assertSame('UserProfiles', (string) $chain->plural->title);
    }

    public function test_title_plural_same_as_plural_title(): void
    {
        $chain = new NameChain('user_profile');

        $this->assertSame(
            (string) $chain->plural->title,
            (string) $chain->title->plural,
        );
    }

    public function test_plural_camel(): void
    {
        $chain = new NameChain('user_profile');

        $this->assertSame('userProfiles', (string) $chain->plural->camel);
    }

    public function test_plural_snake(): void
    {
        $chain = new NameChain('user_profile');

        $this->assertSame('user_profiles', (string) $chain->plural->snake);
    }

    public function test_plural_kebab(): void
    {
        $chain = new NameChain('user_profile');

        $this->assertSame('user-profiles', (string) $chain->plural->kebab);
    }

    public function test_singular_kebab(): void
    {
        $chain = new NameChain('user_profile');

        $this->assertSame('user-profile', (string) $chain->singular->kebab);
    }

    // ─── Immutability ─────────────────────────────────────────────

    public function test_modifiers_return_new_instances(): void
    {
        $chain = new NameChain('user_profile');
        $plural = $chain->plural;

        $this->assertNotSame($chain, $plural);
        $this->assertSame('user_profile', (string) $chain);
        $this->assertSame('user_profiles', (string) $plural);
    }

    public function test_casing_modifier_does_not_mutate_original(): void
    {
        $chain = new NameChain('user_profile');
        $titled = $chain->title;

        $this->assertSame('user_profile', (string) $chain);
        $this->assertSame('UserProfile', (string) $titled);
    }

    // ─── Constructor flags ────────────────────────────────────────

    public function test_constructor_plural_flag(): void
    {
        $chain = new NameChain('user_profile', plural: true);

        $this->assertSame('user_profiles', (string) $chain);
    }

    public function test_constructor_casing_flag(): void
    {
        $chain = new NameChain('user_profile', casing: 'title');

        $this->assertSame('UserProfile', (string) $chain);
    }

    public function test_constructor_both_flags(): void
    {
        $chain = new NameChain('user_profile', plural: true, casing: 'title');

        $this->assertSame('UserProfiles', (string) $chain);
    }

    // ─── Stringable interface ─────────────────────────────────────

    public function test_implements_stringable(): void
    {
        $chain = new NameChain('user_profile');

        $this->assertInstanceOf(\Stringable::class, $chain);
    }

    public function test_works_in_string_interpolation(): void
    {
        $chain = new NameChain('user_profile');

        $this->assertSame('Hello UserProfile', "Hello {$chain->title}");
    }

    // ─── Edge cases ───────────────────────────────────────────────

    public function test_single_word(): void
    {
        $chain = new NameChain('post');

        $this->assertSame('post', (string) $chain);
        $this->assertSame('Post', (string) $chain->title);
        $this->assertSame('Posts', (string) $chain->plural->title);
        $this->assertSame('posts', (string) $chain->plural);
    }

    public function test_unknown_modifier_throws(): void
    {
        $chain = new NameChain('user_profile');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown NameChain modifier: nonexistent');

        $chain->nonexistent;
    }
}
