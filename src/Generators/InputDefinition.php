<?php

namespace SchemaCraft\Generators;

class InputDefinition
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
        public readonly mixed $default = null,
        public readonly array $options = [],
        /** Whether this schemaSelector input should show column checkboxes. */
        public readonly bool $selectColumns = false,
        /** Whether this schemaSelector input should show relationship checkboxes. */
        public readonly bool $selectRelationships = false,
        /** The schemaSelector key that schemaColumn/schemaColumns reference. */
        public readonly ?string $selectorKey = null,
    ) {}
}
