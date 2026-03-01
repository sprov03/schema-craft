<?php

namespace SchemaCraft\Scanner;

/**
 * Value object representing the full scanned result of a schema class.
 */
class TableDefinition
{
    /**
     * @param  ColumnDefinition[]  $columns
     * @param  RelationshipDefinition[]  $relationships
     * @param  array<int, string[]>  $compositeIndexes
     * @param  string[]  $fillable
     * @param  string[]  $hidden
     * @param  string[]  $with
     */
    public function __construct(
        public string $tableName,
        public string $schemaClass,
        public ?string $connection = null,
        public array $columns = [],
        public array $relationships = [],
        public array $compositeIndexes = [],
        public bool $hasTimestamps = false,
        public bool $hasSoftDeletes = false,
        public array $fillable = [],
        public array $hidden = [],
        public array $with = [],
    ) {}
}
