<?php

namespace SchemaCraft\Scanner;

/**
 * Represents a single parameter of a scanned Action.
 */
class ActionParameter
{
    public function __construct(
        /** The property/parameter name (camelCase, e.g. 'acquisitionsManager'). */
        public string $name,
        /** The PHP type (e.g. 'string', 'int', 'float', 'bool', 'User', 'Account'). */
        public string $type,
        /** Whether the property is nullable. */
        public bool $nullable = false,
        /** Whether the property has a default value. */
        public bool $hasDefault = false,
        /** The default value (if hasDefault is true). */
        public mixed $default = null,
        /** Whether this parameter represents an Eloquent model (FK relationship). */
        public bool $isModel = false,
        /** Full model class name when isModel is true (e.g. 'App\Models\User'). */
        public ?string $modelClass = null,
        /** The foreign key column name in the database (e.g. 'acquisitions_manager_id'). */
        public ?string $foreignKeyColumn = null,
        /** The database column name for scalar properties (e.g. 'document_folder_url'). */
        public ?string $columnName = null,
        /** The relationship name (from BelongsTo attribute or property name). */
        public ?string $relationship = null,
        /** Whether this parameter represents nested relationship data (array of related fields). */
        public bool $isNestedRelationship = false,
        /** The nested relationship definition when isNestedRelationship is true. */
        public ?NestedRelationshipParameter $nestedRelationship = null,
    ) {}
}
