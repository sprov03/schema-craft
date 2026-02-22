<?php

namespace SchemaCraft\Generator\Sdk;

use SchemaCraft\Scanner\TableDefinition;

/**
 * Value object holding the context needed to generate SDK files for a single schema.
 */
class SdkSchemaContext
{
    /**
     * @param  string[]  $customActions
     * @param  bool  $isDependencyOnly  When true, only a Data DTO is generated (no SDK Resource or Client accessor)
     */
    public function __construct(
        public TableDefinition $table,
        public array $customActions = [],
        public bool $isDependencyOnly = false,
    ) {}
}
