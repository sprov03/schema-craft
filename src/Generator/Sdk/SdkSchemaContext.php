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
     */
    public function __construct(
        public TableDefinition $table,
        public array $customActions = [],
        public array $endpoints = [],
        public bool $isDependencyOnly = false,
        public ?array $resourceFields = null,
    ) {}
}
