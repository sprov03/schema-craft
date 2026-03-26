<?php

namespace SchemaCraft\Scanner;

/**
 * Represents nested relationship data within an Action parameter.
 *
 * Captures which fields from a related model are included,
 * the relationship type, and any deeply nested sub-relationships.
 */
class NestedRelationshipParameter
{
    /**
     * @param  NestedFieldDefinition[]  $fields
     * @param  string[]  $pivotFields
     * @param  NestedRelationshipParameter[]  $nestedRelationships
     */
    public function __construct(
        /** The property/relationship name (e.g. 'contact'). */
        public string $name,
        /** The Eloquent relationship type ('hasOne', 'hasMany', 'belongsToMany', etc.). */
        public string $relationshipType,
        /** FQCN of the related model. */
        public string $relatedModel,
        /** Whether the relationship is optional. */
        public bool $nullable = false,
        /** Whether this is a collection relationship (hasMany, belongsToMany, morphMany, morphToMany). */
        public bool $isCollection = false,
        /** Flat field definitions on this relationship level. */
        public array $fields = [],
        /** Pivot field names for belongsToMany/morphToMany. */
        public array $pivotFields = [],
        /** The morph name for polymorphic relationships. */
        public ?string $morphName = null,
        /** The schema class for the related model (for field resolution). */
        public ?string $relatedSchemaClass = null,
        /** Recursively nested relationships for deep nesting. */
        public array $nestedRelationships = [],
        /** The DataSchema class FQCN when defined via DataSchema (null for legacy field arrays). */
        public ?string $dataSchemaClass = null,
    ) {}
}
