<?php

namespace SchemaCraft\Writer;

class RelationshipInstruction
{
    public function __construct(
        public string $schemaName,
        public string $relationshipType,
        public string $relatedModelName,
        public ?string $propertyName = null,
        public ?string $morphName = null,
    ) {}
}
