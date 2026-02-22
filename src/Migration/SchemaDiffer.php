<?php

namespace SchemaCraft\Migration;

use RuntimeException;

/**
 * Compares a desired CanonicalTable against an actual CanonicalTable
 * and produces a TableDiff describing the changes needed.
 *
 * Both sides must be normalized into CanonicalTable format before
 * being passed to this class (via TableDefinitionNormalizer and
 * DatabaseTableNormalizer respectively).
 */
class SchemaDiffer
{
    /**
     * Columns managed by traits that should not be treated as "extra" in the DB.
     */
    private const MANAGED_COLUMNS = ['created_at', 'updated_at', 'deleted_at'];

    /**
     * Compare desired canonical state against actual canonical state.
     *
     * @param  array<string, string>  $renameMap  ['new_name' => 'old_name']
     */
    public function diff(CanonicalTable $desired, ?CanonicalTable $actual, array $renameMap = []): TableDiff
    {
        if ($actual === null) {
            return $this->createTableDiff($desired);
        }

        return $this->updateTableDiff($desired, $actual, $renameMap);
    }

    /**
     * Generate a "create table" diff when the table doesn't exist at all.
     */
    private function createTableDiff(CanonicalTable $desired): TableDiff
    {
        $columnDiffs = [];
        $indexDiffs = [];

        foreach ($desired->columns as $col) {
            $columnDiffs[] = new ColumnDiff(
                action: 'add',
                columnName: $col->name,
                desired: $col,
            );

            // Unique constraints become index diffs
            if ($col->unique) {
                $indexDiffs[] = new IndexDiff(
                    action: 'add',
                    columns: [$col->name],
                    unique: true,
                );
            }

            if ($col->index && ! $col->primary) {
                $indexDiffs[] = new IndexDiff(
                    action: 'add',
                    columns: [$col->name],
                    unique: false,
                );
            }
        }

        // Composite indexes
        foreach ($desired->compositeIndexes as $idx) {
            $indexDiffs[] = new IndexDiff(
                action: 'add',
                columns: $idx->columns,
                unique: $idx->unique,
            );
        }

        // Foreign keys
        $fkDiffs = [];

        foreach ($desired->foreignKeys as $fk) {
            $fkDiffs[] = new ForeignKeyDiff(
                action: 'add',
                column: $fk->column,
                foreignTable: $fk->foreignTable,
                foreignColumn: $fk->foreignColumn,
                onDelete: $fk->onDelete,
                onUpdate: $fk->onUpdate,
            );
        }

        return new TableDiff(
            tableName: $desired->tableName,
            type: 'create',
            columnDiffs: $columnDiffs,
            indexDiffs: $indexDiffs,
            fkDiffs: $fkDiffs,
            addTimestamps: $desired->hasTimestamps,
            addSoftDeletes: $desired->hasSoftDeletes,
        );
    }

    /**
     * Generate an "update table" diff by comparing column-by-column.
     *
     * @param  array<string, string>  $renameMap  ['new_name' => 'old_name']
     */
    private function updateTableDiff(CanonicalTable $desired, CanonicalTable $actual, array $renameMap): TableDiff
    {
        $columnDiffs = [];
        $indexDiffs = [];

        // Build lookup of desired columns by name
        $desiredColumns = [];

        foreach ($desired->columns as $col) {
            $desiredColumns[$col->name] = $col;
        }

        // Build lookup of actual columns by name
        $actualColumns = [];

        foreach ($actual->columns as $col) {
            $actualColumns[$col->name] = $col;
        }

        // ── Phase 0: Identify renames ──────────────────────────────────────
        $renamedOldNames = [];  // old_name => new_name
        $renamedNewNames = [];  // new_name => old_name

        // Pre-validate: no two desired columns may share the same renamedFrom
        $renamedFromCounts = [];

        foreach ($renameMap as $newName => $oldName) {
            $renamedFromCounts[$oldName] = ($renamedFromCounts[$oldName] ?? 0) + 1;
        }

        foreach ($renamedFromCounts as $oldName => $count) {
            if ($count > 1) {
                throw new RuntimeException(
                    "Multiple columns on table [{$desired->tableName}] declare #[RenamedFrom('{$oldName}')]. Each old column name can only be referenced once."
                );
            }
        }

        foreach ($renameMap as $newName => $oldName) {
            // If old column doesn't exist in DB → stale attribute, skip rename
            if (! isset($actualColumns[$oldName])) {
                continue;
            }

            // If new name already exists in DB alongside old name → conflict
            if (isset($actualColumns[$newName])) {
                throw new RuntimeException(
                    "Column [{$newName}] on table [{$desired->tableName}] declares #[RenamedFrom('{$oldName}')], but both [{$newName}] and [{$oldName}] exist in the database. Remove the old column or the #[RenamedFrom] attribute."
                );
            }

            // Emit rename diff
            $desiredCol = $desiredColumns[$newName] ?? null;

            $columnDiffs[] = new ColumnDiff(
                action: 'rename',
                columnName: $newName,
                desired: $desiredCol,
                actual: $actualColumns[$oldName],
                oldColumnName: $oldName,
            );

            $renamedOldNames[$oldName] = $newName;
            $renamedNewNames[$newName] = $oldName;

            // Check if the column also needs a modify (type/nullable changed)
            if ($desiredCol !== null && $this->columnNeedsUpdate($desiredCol, $actualColumns[$oldName])) {
                $columnDiffs[] = new ColumnDiff(
                    action: 'modify',
                    columnName: $newName,
                    desired: $desiredCol,
                    actual: $actualColumns[$oldName],
                );
            }
        }

        // ── Phase 1: Check for columns to add or modify ───────────────────
        foreach ($desiredColumns as $name => $desiredCol) {
            // Skip columns handled by rename phase
            if (isset($renamedNewNames[$name])) {
                continue;
            }

            $actualCol = $actualColumns[$name] ?? null;

            if ($actualCol === null) {
                // Column exists in schema but not in DB → add
                $columnDiffs[] = new ColumnDiff(
                    action: 'add',
                    columnName: $name,
                    desired: $desiredCol,
                );

                // Add index/unique for new columns
                if ($desiredCol->unique) {
                    $indexDiffs[] = new IndexDiff(action: 'add', columns: [$name], unique: true);
                }

                if ($desiredCol->index && ! $desiredCol->primary) {
                    $indexDiffs[] = new IndexDiff(action: 'add', columns: [$name], unique: false);
                }
            } elseif ($this->columnNeedsUpdate($desiredCol, $actualCol)) {
                // Column exists but properties differ → modify
                $columnDiffs[] = new ColumnDiff(
                    action: 'modify',
                    columnName: $name,
                    desired: $desiredCol,
                    actual: $actualCol,
                );
            }
        }

        // ── Phase 2: Check for columns to drop (in DB but not in schema) ──
        foreach ($actualColumns as $name => $actualCol) {
            // Skip managed columns (timestamps, soft deletes)
            if (in_array($name, self::MANAGED_COLUMNS, true)) {
                continue;
            }

            // Skip primary key columns
            if ($actualCol->primary) {
                continue;
            }

            // Skip columns consumed by a rename
            if (isset($renamedOldNames[$name])) {
                continue;
            }

            if (! isset($desiredColumns[$name])) {
                $columnDiffs[] = new ColumnDiff(
                    action: 'drop',
                    columnName: $name,
                    actual: $actualCol,
                );
            }
        }

        // ── Phase 3: Diff single-column indexes on existing columns ──────
        foreach ($desiredColumns as $name => $desiredCol) {
            if (! isset($actualColumns[$name])) {
                continue; // New columns handled in Phase 1
            }

            if ($desiredCol->primary) {
                continue;
            }

            $actualCol = $actualColumns[$name];

            // Check for missing non-unique index
            if ($desiredCol->index && ! $desiredCol->unique && ! $actualCol->index) {
                $indexDiffs[] = new IndexDiff(action: 'add', columns: [$name], unique: false);
            }

            // Check for missing unique index
            if ($desiredCol->unique && ! $actualCol->unique) {
                $indexDiffs[] = new IndexDiff(action: 'add', columns: [$name], unique: true);
            }
        }

        // Diff composite indexes (multi-column)
        $indexDiffs = array_merge($indexDiffs, $this->diffCompositeIndexes($desired, $actual));

        // Diff foreign keys
        $fkDiffs = $this->diffForeignKeys($desired, $actual);

        // Diff timestamps
        $addTimestamps = $desired->hasTimestamps && ! $actual->hasTimestamps;
        $dropTimestamps = ! $desired->hasTimestamps && $actual->hasTimestamps;

        // Diff soft deletes
        $addSoftDeletes = $desired->hasSoftDeletes && ! $actual->hasSoftDeletes;
        $dropSoftDeletes = ! $desired->hasSoftDeletes && $actual->hasSoftDeletes;

        return new TableDiff(
            tableName: $desired->tableName,
            type: 'update',
            columnDiffs: $columnDiffs,
            indexDiffs: $indexDiffs,
            fkDiffs: $fkDiffs,
            addTimestamps: $addTimestamps,
            dropTimestamps: $dropTimestamps,
            addSoftDeletes: $addSoftDeletes,
            dropSoftDeletes: $dropSoftDeletes,
        );
    }

    /**
     * Check if a column's properties have changed between desired and actual.
     */
    private function columnNeedsUpdate(CanonicalColumn $desired, CanonicalColumn $actual): bool
    {
        // Type mismatch
        if ($desired->type !== $actual->type) {
            return true;
        }

        // Unsigned mismatch
        if ($desired->unsigned !== $actual->unsigned) {
            return true;
        }

        // Nullable mismatch
        if ($desired->nullable !== $actual->nullable) {
            return true;
        }

        // Length mismatch (only compare when desired specifies a length)
        if ($desired->length !== null && $desired->length !== $actual->length) {
            return true;
        }

        // Precision / scale mismatch for decimals
        if ($desired->precision !== null && $desired->precision !== $actual->precision) {
            return true;
        }

        if ($desired->scale !== null && $desired->scale !== $actual->scale) {
            return true;
        }

        // Expression default mismatch
        if ($this->normalizeExpressionDefault($desired->expressionDefault)
            !== $this->normalizeExpressionDefault($actual->expressionDefault)) {
            return true;
        }

        return false;
    }

    /**
     * Normalize an expression default for comparison (e.g., NOW() → CURRENT_TIMESTAMP).
     */
    private function normalizeExpressionDefault(?string $expr): ?string
    {
        if ($expr === null) {
            return null;
        }

        $upper = strtoupper(trim($expr));

        if ($upper === 'NOW()' || $upper === 'CURRENT_TIMESTAMP()') {
            return 'CURRENT_TIMESTAMP';
        }

        return $upper;
    }

    /**
     * Diff composite indexes (multi-column indexes).
     *
     * @return IndexDiff[]
     */
    private function diffCompositeIndexes(CanonicalTable $desired, CanonicalTable $actual): array
    {
        $diffs = [];

        foreach ($desired->compositeIndexes as $idx) {
            if (! $actual->hasCompositeIndex($idx->columns)) {
                $diffs[] = new IndexDiff(
                    action: 'add',
                    columns: $idx->columns,
                    unique: $idx->unique,
                );
            }
        }

        return $diffs;
    }

    /**
     * Diff foreign keys by comparing canonical FK lists.
     *
     * @return ForeignKeyDiff[]
     */
    private function diffForeignKeys(CanonicalTable $desired, CanonicalTable $actual): array
    {
        $diffs = [];

        foreach ($desired->foreignKeys as $desiredFk) {
            $existingFk = $actual->getForeignKey($desiredFk->column);

            if ($existingFk === null) {
                // FK doesn't exist in DB → add
                $diffs[] = new ForeignKeyDiff(
                    action: 'add',
                    column: $desiredFk->column,
                    foreignTable: $desiredFk->foreignTable,
                    foreignColumn: $desiredFk->foreignColumn,
                    onDelete: $desiredFk->onDelete,
                    onUpdate: $desiredFk->onUpdate,
                );
            } else {
                // FK exists — check if on_delete/on_update changed
                // Normalize 'restrict' → 'no action' since MySQL treats them identically
                $desiredOnDelete = $this->normalizeFkAction($desiredFk->onDelete);
                $desiredOnUpdate = $this->normalizeFkAction($desiredFk->onUpdate);
                $actualOnDelete = $this->normalizeFkAction($existingFk->onDelete);
                $actualOnUpdate = $this->normalizeFkAction($existingFk->onUpdate);

                if ($actualOnDelete !== $desiredOnDelete || $actualOnUpdate !== $desiredOnUpdate) {
                    // Drop and re-add with new constraints
                    $diffs[] = new ForeignKeyDiff(
                        action: 'drop',
                        column: $desiredFk->column,
                    );
                    $diffs[] = new ForeignKeyDiff(
                        action: 'add',
                        column: $desiredFk->column,
                        foreignTable: $desiredFk->foreignTable,
                        foreignColumn: $desiredFk->foreignColumn,
                        onDelete: $desiredFk->onDelete,
                        onUpdate: $desiredFk->onUpdate,
                    );
                }
            }
        }

        return $diffs;
    }

    /**
     * Normalize FK action strings so semantically equivalent values compare equal.
     *
     * MySQL treats 'restrict' and 'no action' identically — both prevent deletion
     * of the parent row and are checked immediately (not deferred).
     */
    private function normalizeFkAction(string $action): string
    {
        $action = strtolower($action);

        if ($action === 'restrict') {
            return 'no action';
        }

        return $action;
    }
}
