<?php

namespace SchemaCraft\Validation;

/**
 * A composable collection of validation rules with smart merging.
 *
 * Returned by Schema::createRules() and Schema::updateRules().
 * Supports additive merging where required/nullable/sometimes are
 * intelligently replaced rather than duplicated.
 */
class RuleSet
{
    private const PRESENCE_RULES = ['required', 'nullable', 'sometimes'];

    /**
     * @param  array<string, array<int, mixed>>  $rules
     */
    public function __construct(
        private array $rules,
    ) {}

    /**
     * Smart-merge additional rules into this set.
     *
     * - If override contains 'required', removes 'nullable' and 'sometimes'
     * - If override contains 'nullable', removes 'required' and 'sometimes'
     * - If override contains 'sometimes', removes 'required' and 'nullable'
     * - Other rules are appended without duplication
     *
     * @param  array<string, array<int, mixed>>  $overrides
     */
    public function merge(array $overrides): self
    {
        $merged = $this->rules;

        foreach ($overrides as $field => $newRules) {
            if (! isset($merged[$field])) {
                $merged[$field] = $newRules;

                continue;
            }

            $existing = $merged[$field];

            // Check if any new rule is a presence rule
            $newPresenceRule = $this->findPresenceRule($newRules);

            if ($newPresenceRule !== null) {
                // Remove all existing presence rules
                $existing = array_values(array_filter($existing, function ($rule) {
                    return ! is_string($rule) || ! in_array($rule, self::PRESENCE_RULES, true);
                }));

                // Remove the presence rule from new rules (we'll prepend it)
                $newRules = array_values(array_filter($newRules, function ($rule) use ($newPresenceRule) {
                    return $rule !== $newPresenceRule;
                }));

                // Prepend the new presence rule
                array_unshift($existing, $newPresenceRule);
            }

            // Append remaining new rules (skip duplicates)
            foreach ($newRules as $rule) {
                if (is_string($rule) && in_array($rule, $existing, true)) {
                    continue;
                }
                $existing[] = $rule;
            }

            $merged[$field] = $existing;
        }

        return new self($merged);
    }

    /**
     * Return a new RuleSet with only the specified fields.
     *
     * @param  string|string[]  $fields
     */
    public function only(string|array $fields): self
    {
        $fields = (array) $fields;

        return new self(array_intersect_key($this->rules, array_flip($fields)));
    }

    /**
     * Return a new RuleSet without the specified fields.
     *
     * @param  string|string[]  $fields
     */
    public function except(string|array $fields): self
    {
        $fields = (array) $fields;

        return new self(array_diff_key($this->rules, array_flip($fields)));
    }

    /**
     * Convert to a plain array suitable for FormRequest::rules().
     *
     * @return array<string, array<int, mixed>>
     */
    public function toArray(): array
    {
        return $this->rules;
    }

    /**
     * Find the first presence rule in a rule array.
     *
     * @param  array<int, mixed>  $rules
     */
    private function findPresenceRule(array $rules): ?string
    {
        foreach ($rules as $rule) {
            if (is_string($rule) && in_array($rule, self::PRESENCE_RULES, true)) {
                return $rule;
            }
        }

        return null;
    }
}
