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
        public ?string $morphName = null,
        public ?string $pivotModel = null,
        public ?string $ownerKey = null,
        public ?string $localKey = null,
        public ?string $parentKey = null,
        public ?string $relatedKey = null,
        public ?string $foreignPivotKey = null,
        public ?string $relatedPivotKey = null,
        public ?string $through = null,
        public ?string $firstKey = null,
        public ?string $secondKey = null,
        public ?string $secondLocalKey = null,
        public bool $inverse = false,
    ) {}
}
