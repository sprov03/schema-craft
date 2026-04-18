<?php

namespace SchemaCraft\Scanner;

/**
 * Value object representing a discovered API resource class.
 */
class ResourceDefinition
{
    /**
     * @param  array<int, array{name: string, type: string}>  $properties
     * @param  array<int, array{name: string, type: string, resource: string}>  $relationships
     * @param  string[]  $computed
     */
    public function __construct(
        /** Fully-qualified class name. */
        public readonly string $class,
        /** Short class name, e.g. CampaignFullResource. */
        public readonly string $name,
        /** Associated schema FQCN from #[ResourceSchema], null for manual resources. */
        public readonly ?string $schema,
        /** True when the resource extends plain JsonResource (not SchemaCraftResource). */
        public readonly bool $isManual,
        /** Scalar typed properties defined on the resource. */
        public readonly array $properties = [],
        /** Relationship properties (HasMany, HasOne, BelongsTo) with their resource class. */
        public readonly array $relationships = [],
        /** Computed method names included in the response. */
        public readonly array $computed = [],
        /** Raw file contents — only populated for manual resources. */
        public readonly ?string $fileContents = null,
        /** Action class FQCN wired to this resource, populated by GenerateController. */
        public readonly ?string $action = null,
    ) {}

    /**
     * Return relationship names only — used for eager loading.
     *
     * @return string[]
     */
    public function relationshipNames(): array
    {
        return array_column($this->relationships, 'name');
    }
}
