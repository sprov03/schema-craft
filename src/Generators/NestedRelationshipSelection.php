<?php

namespace SchemaCraft\Generators;

/**
 * Represents a single relationship selected via the nestedFieldSelector input type,
 * including the specific sub-fields the generator should include for that relationship.
 *
 * Used in Blade templates to generate nested DataSchema classes and typed action
 * properties such as #[BelongsTo] or #[BelongsToMany].
 */
class NestedRelationshipSelection
{
    /**
     * @param  GeneratorRelationship  $relationship  The full relationship descriptor from the parent schema.
     * @param  GeneratorColumn[]  $selectedFields  The columns of the related schema the user selected.
     */
    public function __construct(
        public readonly GeneratorRelationship $relationship,
        public readonly array $selectedFields,
    ) {}

    /**
     * Convenience accessor: the relationship type string (e.g. "belongsTo", "belongsToMany").
     */
    public function type(): string
    {
        return $this->relationship->type;
    }

    /**
     * Whether this relationship represents a collection (hasMany, belongsToMany, etc.).
     */
    public function isCollection(): bool
    {
        return $this->relationship->isCollection();
    }

    /**
     * Whether this relationship represents a single model (belongsTo, hasOne, etc.).
     */
    public function isSingular(): bool
    {
        return $this->relationship->isSingular();
    }
}
