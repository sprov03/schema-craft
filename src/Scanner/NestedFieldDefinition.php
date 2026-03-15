<?php

namespace SchemaCraft\Scanner;

/**
 * Represents a single field within a nested relationship parameter.
 */
class NestedFieldDefinition
{
    public function __construct(
        /** The column name on the related model (e.g. 'first_name'). */
        public string $name,
        /** Full dot path from the action root (e.g. 'contact.first_name'). */
        public string $dotPath,
        /** The PHP type (string, int, bool, float, array). */
        public string $type,
        /** Whether the field is nullable. */
        public bool $nullable = false,
        /** The schema column type for validation rule derivation. */
        public ?string $columnType = null,
    ) {}
}
