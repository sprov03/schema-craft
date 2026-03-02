<?php

namespace SchemaCraft\Generator;

/**
 * Value object representing a relationship as configured in the visual schema editor.
 */
class EditorRelationship
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
        public ?string $pivotModel = null,
        public bool $index = false,
        public ?string $columnType = null,
        public bool $with = false,
    ) {}
}
