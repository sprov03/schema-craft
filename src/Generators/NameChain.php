<?php

namespace SchemaCraft\Generators;

use Illuminate\Support\Str;

/**
 * Immutable, Stringable name helper for code generators.
 *
 * Wraps a base word (stored as snake_case singular) and exposes modifier
 * flags via magic property access. Modifiers are independent — order does
 * not matter: `$chain->plural->title` === `$chain->title->plural`.
 *
 * ## Modifiers
 *
 * **Plurality:**
 * - `->singular` — singular form (default)
 * - `->plural`   — plural form
 *
 * **Casing:**
 * - `->title` — StudlyCase (`UserProfile`)
 * - `->camel` — camelCase (`userProfile`)
 * - `->snake` — snake_case (`user_profile`) (default)
 * - `->kebab` — kebab-case (`user-profile`)
 *
 * ## Examples
 *
 *     $model = new NameChain('user_profile');
 *
 *     (string) $model               // "user_profile"
 *     (string) $model->title        // "UserProfile"
 *     (string) $model->plural->title // "UserProfiles"
 *     (string) $model->title->plural // "UserProfiles" (same — order doesn't matter)
 *     (string) $model->plural->snake // "user_profiles"
 *     (string) $model->camel        // "userProfile"
 *     (string) $model->plural->kebab // "user-profiles"
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
     * @param  string  $baseWord  Any casing — will be normalized to snake_case singular.
     * @param  bool  $plural  Whether the output should be plural.
     * @param  string|null  $casing  One of: null, 'title', 'camel', 'snake', 'kebab'.
     */
    public function __construct(string $baseWord, bool $plural = false, ?string $casing = null)
    {
        // Normalize input to snake_case singular
        $this->baseWord = Str::snake(Str::singular($baseWord));
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
            'singular' => new self($this->baseWord, plural: false, casing: $this->casing),
            'plural' => new self($this->baseWord, plural: true, casing: $this->casing),
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
