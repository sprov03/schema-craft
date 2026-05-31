<?php

namespace SchemaCraft\Generator\Sdk;

use SchemaCraft\Scanner\TableDefinition;

/**
 * Value object holding the context needed to generate SDK files for a single schema.
 */
class SdkSchemaContext
{
    /**
     * @param  SdkCustomAction[]  $customActions
     * @param  array<int, array{method: string, path: string, action: string, type: string, rules: ?array}>  $endpoints
     * @param  bool  $isDependencyOnly  When true, only a Data DTO is generated (no SDK Resource or Client accessor)
     * @param  array{columns: array, relationships: array}|null  $resourceFields  Output of buildResponseFieldsFromResource() — when set, the DTO is generated from this instead of $table so the SDK matches the API docs exactly
     * @param  array{name: string, fields: array}|null  $innerDto  Set for inner DTOs produced by a rich column type's SdkShape (collection item / json-dto object / bitmask). These have no TableDefinition — the DTO is emitted from this field set instead. Always dependency-only.
     * @param  ?string  $resourceClass  FQCN of the SchemaCraftResource that backs this schema context (or null for inner-DTO contexts that have no Resource layer). Carried so consumers — both SDK gen and the visualizer — can refer to the canonical Resource name without re-deriving it from the model name. Eliminates the strip-and-reappend dance the API-docs payload otherwise required at three sites (SdkResourceNaming centralizes the transform).
     */
    public function __construct(
        public ?TableDefinition $table = null,
        public array $customActions = [],
        public array $endpoints = [],
        public bool $isDependencyOnly = false,
        public ?array $resourceFields = null,
        public ?array $innerDto = null,
        public ?string $resourceClass = null,
    ) {}
}
