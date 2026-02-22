<?php

namespace SchemaCraft\Scanner;

/**
 * Value object representing a single relationship derived from a schema property.
 */
class RelationshipDefinition
{
    public function __construct(
        public string $name,
        public string $type,
        public string $relatedModel,
        public bool $nullable = false,
        public ?string $foreignColumn = null,
        public ?string $onDelete = null,
        public ?string $onUpdate = null,
        public bool $noConstraint = false,
        public ?string $pivotTable = null,
        public ?array $pivotColumns = null,
        public ?string $morphName = null,
    ) {}
}
