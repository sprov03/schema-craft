<?php

namespace SchemaCraft\Generator;

/**
 * Value object representing the full editor payload for rendering a schema file.
 */
class SchemaEditorPayload
{
    /**
     * @param  EditorColumn[]  $columns
     * @param  EditorRelationship[]  $relationships
     * @param  string[][]  $compositeIndexes
     */
    public function __construct(
        public string $schemaName,
        public string $schemaNamespace,
        public string $modelNamespace,
        public ?string $tableName = null,
        public ?string $connection = null,
        public bool $hasTimestamps = true,
        public bool $hasSoftDeletes = false,
        public array $columns = [],
        public array $relationships = [],
        public array $compositeIndexes = [],
    ) {}
}
