<?php

namespace SchemaCraft\Generators;

use Illuminate\Support\Str;

/**
 * Immutable, Stringable name helper for code generators.
 *
 * Wraps a base word (stored as snake_case, preserving the original form — no
 * implicit singularization) and exposes modifier flags via magic property access.
 * Modifiers are independent — order does not matter:
 * `$chain->plural->title` === `$chain->title->plural`.
 *
 * ## Input normalization
 *
 * The input is normalized to snake_case only. The caller is responsible for
 * providing the intended form (singular or plural). The `->singular` and `->plural`
 * modifiers then transform relative to the stored word.
 *
 * For model names, `GeneratorSchemaContext` pre-singularizes via `Str::singular()`
 * before constructing — passing already-singular snake_case is the norm there.
 * For relationship names, the PHP property name (e.g. `'breedings'`) is passed
 * as-is so that `(string) $relationship->name` returns the exact property name.
 *
 * ## Modifiers
 *
 * **Plurality:**
 * - `->singular` — singular form of the stored word
 * - `->plural`   — plural form of the stored word
 *
 * **Casing:**
 * - `->title` — StudlyCase (`UserProfile`)
 * - `->camel` — camelCase (`userProfile`)
 * - `->snake` — snake_case (`user_profile`) (default)
 * - `->kebab` — kebab-case (`user-profile`)
 *
 * ## Examples
 *
 *     $model = new NameChain('user_profile');  // singular — model names are pre-singularized
 *
 *     (string) $model               // "user_profile"
 *     (string) $model->title        // "UserProfile"
 *     (string) $model->plural->title // "UserProfiles"
 *     (string) $model->title->plural // "UserProfiles" (same — order doesn't matter)
 *     (string) $model->plural->snake // "user_profiles"
 *     (string) $model->camel        // "userProfile"
 *     (string) $model->plural->kebab // "user-profiles"
 *
 *     $rel = new NameChain('breedings');  // plural — relationship property name preserved
 *
 *     (string) $rel                  // "breedings"
 *     (string) $rel->singular->title // "Breeding"
 *     (string) $rel->plural->title   // "Breedings"
 */
class NameChain implements \Stringable
{
    /** The base word in snake_case singular form. */
    private string $baseWord;

    /** Whether to pluralize the output. */
    private bool $isPlural;

    /** The casing to apply: null (snake_case), 'title', 'camel', 'snake', 'kebab'. */
    private ?string $casing;

    /**
     * @param  string  $baseWord  Any casing — normalized to snake_case; original form (singular or plural) is preserved.
     * @param  bool  $plural  Whether the output should be pluralized via Str::plural().
     * @param  string|null  $casing  One of: null, 'title', 'camel', 'snake', 'kebab'.
     */
    public function __construct(string $baseWord, bool $plural = false, ?string $casing = null)
    {
        // Normalize to snake_case only — do NOT singularize. The caller controls the form.
        // Relationship names like 'breedings' must survive construction unchanged so that
        // (string) $relationship->name returns the exact PHP property name.
        $this->baseWord = Str::snake($baseWord);
        $this->isPlural = $plural;
        $this->casing = $casing;
    }

    /**
     * Access a modifier to produce a new NameChain with that flag set.
     *
     * Supported modifiers: singular, plural, title, camel, snake, kebab.
     */
    public function __get(string $name): self
    {
        return match ($name) {
            // Normalize through Str::singular/plural so the modifier is idempotent
            // regardless of whether $baseWord was stored in singular or plural form.
            'singular' => new self(Str::singular($this->baseWord), plural: false, casing: $this->casing),
            'plural' => new self(Str::singular($this->baseWord), plural: true, casing: $this->casing),
            'title' => new self($this->baseWord, plural: $this->isPlural, casing: 'title'),
            'camel' => new self($this->baseWord, plural: $this->isPlural, casing: 'camel'),
            'snake' => new self($this->baseWord, plural: $this->isPlural, casing: 'snake'),
            'kebab' => new self($this->baseWord, plural: $this->isPlural, casing: 'kebab'),
            default => throw new \InvalidArgumentException("Unknown NameChain modifier: {$name}"),
        };
    }

    /**
     * Resolve to a string: apply plurality, then casing.
     *
     * Default (no modifiers): singular snake_case.
     */
    public function __toString(): string
    {
        $word = $this->isPlural ? Str::plural($this->baseWord) : $this->baseWord;

        return match ($this->casing) {
            'title' => Str::studly($word),
            'camel' => Str::camel($word),
            'kebab' => Str::kebab(Str::studly($word)),
            default => $word, // snake_case is already the storage format
        };
    }
}
