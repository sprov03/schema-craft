<?php

namespace SchemaCraft\Migration;

/**
 * Aggregates all changes detected for a single table.
 */
class TableDiff
{
    /**
     * @param  ColumnDiff[]  $columnDiffs
     * @param  IndexDiff[]  $indexDiffs
     * @param  ForeignKeyDiff[]  $fkDiffs
     */
    public function __construct(
        public string $tableName,
        public string $type,
        public array $columnDiffs = [],
        public array $indexDiffs = [],
        public array $fkDiffs = [],
        public bool $addTimestamps = false,
        public bool $dropTimestamps = false,
        public bool $addSoftDeletes = false,
        public bool $dropSoftDeletes = false,
    ) {}

    /**
     * Check if this diff has no changes.
     */
    public function isEmpty(): bool
    {
        return empty($this->columnDiffs)
            && empty($this->indexDiffs)
            && empty($this->fkDiffs)
            && ! $this->addTimestamps
            && ! $this->dropTimestamps
            && ! $this->addSoftDeletes
            && ! $this->dropSoftDeletes;
    }
}
