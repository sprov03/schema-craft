<?php

namespace SchemaCraft\Generators;

/**
 * The resolved value produced by the nestedFieldSelector input type.
 *
 * Carries the top-level columns the user selected from the schema, plus a list
 * of relationship selections — each with its own set of sub-fields chosen from
 * the related schema. Generator templates use this to produce typed action
 * properties, #[BelongsTo] / #[BelongsToMany] annotations, and nested
 * DataSchema classes.
 */
class NestedFieldSelection
{
    /**
     * @param  GeneratorColumn[]  $columns  Top-level columns selected from the schema.
     * @param  NestedRelationshipSelection[]  $relationships  Selected relationships with their sub-fields.
     */
    public function __construct(
        public readonly array $columns,
        public readonly array $relationships,
    ) {}

    /**
     * Whether the selection contains no columns and no relationships.
     */
    public function isEmpty(): bool
    {
        return empty($this->columns) && empty($this->relationships);
    }

    /**
     * True when at least one BelongsToMany (or other collection) relationship is selected.
     * Useful in templates to conditionally import Collection / BelongsToMany.
     */
    public function hasCollectionRelationships(): bool
    {
        foreach ($this->relationships as $rel) {
            if ($rel->isCollection()) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when at least one singular relationship (BelongsTo / HasOne, etc.) is selected.
     */
    public function hasSingularRelationships(): bool
    {
        foreach ($this->relationships as $rel) {
            if ($rel->isSingular()) {
                return true;
            }
        }

        return false;
    }
}
