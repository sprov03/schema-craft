<?php

namespace SchemaCraft\Migration;

/**
 * Sorts migration diffs so that FK-referenced tables are created
 * before tables that depend on them.
 */
class MigrationDiffSorter
{
    /**
     * Sort diffs by foreign key dependencies.
     *
     * "Create" diffs are topologically sorted so that a table with FKs
     * pointing to another table in the batch comes after that table.
     * "Update" diffs are appended after all creates.
     *
     * @param  TableDiff[]  $diffs
     * @return TableDiff[]
     */
    public function sort(array $diffs): array
    {
        $creates = [];
        $updates = [];

        foreach ($diffs as $diff) {
            if ($diff->type === 'create') {
                $creates[$diff->tableName] = $diff;
            } else {
                $updates[] = $diff;
            }
        }

        if (count($creates) <= 1) {
            return array_merge(array_values($creates), $updates);
        }

        $sorted = $this->topologicalSort($creates);

        return array_merge($sorted, $updates);
    }

    /**
     * Topological sort using Kahn's algorithm.
     *
     * @param  array<string, TableDiff>  $creates  Keyed by table name.
     * @return TableDiff[]
     */
    private function topologicalSort(array $creates): array
    {
        $dependencies = [];
        foreach ($creates as $tableName => $diff) {
            $dependencies[$tableName] = [];
            foreach ($diff->fkDiffs as $fk) {
                if ($fk->action === 'add'
                    && $fk->foreignTable !== null
                    && isset($creates[$fk->foreignTable])
                    && $fk->foreignTable !== $tableName
                ) {
                    $dependencies[$tableName][] = $fk->foreignTable;
                }
            }
        }

        $sorted = [];
        $resolved = [];

        while (count($sorted) < count($creates)) {
            $picked = false;

            foreach ($dependencies as $tableName => $deps) {
                if (in_array($tableName, $resolved, true)) {
                    continue;
                }

                $unresolved = array_diff($deps, $resolved);
                if (empty($unresolved)) {
                    $sorted[] = $creates[$tableName];
                    $resolved[] = $tableName;
                    $picked = true;
                }
            }

            // Circular dependency fallback: append remaining in original order
            if (! $picked) {
                foreach ($creates as $tableName => $diff) {
                    if (! in_array($tableName, $resolved, true)) {
                        $sorted[] = $diff;
                        $resolved[] = $tableName;
                    }
                }

                break;
            }
        }

        return $sorted;
    }
}
