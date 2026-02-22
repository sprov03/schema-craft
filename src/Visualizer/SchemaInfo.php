<?php

namespace SchemaCraft\Visualizer;

class SchemaInfo
{
    /**
     * @param  array<int, array<string, mixed>>  $columns
     * @param  array<int, array<string, mixed>>  $relationships
     */
    public function __construct(
        public string $schemaClass,
        public string $tableName,
        public array $columns,
        public array $relationships,
        public bool $hasTimestamps,
        public bool $hasSoftDeletes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schemaClass' => $this->schemaClass,
            'tableName' => $this->tableName,
            'columns' => $this->columns,
            'relationships' => $this->relationships,
            'hasTimestamps' => $this->hasTimestamps,
            'hasSoftDeletes' => $this->hasSoftDeletes,
        ];
    }
}
