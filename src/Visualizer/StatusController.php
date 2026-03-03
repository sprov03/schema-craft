<?php

namespace SchemaCraft\Visualizer;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use SchemaCraft\Migration\DatabaseReader;
use SchemaCraft\Migration\DatabaseTableNormalizer;
use SchemaCraft\Migration\MigrationDiffSorter;
use SchemaCraft\Migration\MigrationGenerator;
use SchemaCraft\Migration\SchemaDiffer;
use SchemaCraft\Migration\SchemaDiscovery;
use SchemaCraft\Migration\TableDefinitionNormalizer;
use SchemaCraft\Migration\TableDiff;
use SchemaCraft\Scanner\SchemaScanner;

class StatusController
{
    /**
     * Get diff status for all discovered schemas.
     */
    public function status(): JsonResponse
    {
        $diffs = $this->runDiffPipeline();

        $schemas = [];
        $inSync = 0;
        $hasChanges = 0;
        $newTables = 0;

        foreach ($diffs as $item) {
            $schemas[] = $item;

            match ($item['status']) {
                'in_sync' => $inSync++,
                'has_changes' => $hasChanges++,
                'new_table' => $newTables++,
                default => null,
            };
        }

        return new JsonResponse([
            'schemas' => $schemas,
            'summary' => [
                'total' => count($schemas),
                'inSync' => $inSync,
                'hasChanges' => $hasChanges,
                'newTables' => $newTables,
            ],
        ]);
    }

    /**
     * Preview migration code without writing files.
     */
    public function migratePreview(Request $request): JsonResponse
    {
        $filterTables = $request->input('tables');
        $tableDiffs = (new MigrationDiffSorter)->sort($this->collectDiffs($filterTables));

        if (empty($tableDiffs)) {
            return new JsonResponse([
                'success' => true,
                'files' => [],
                'message' => 'All schemas are in sync. Nothing to migrate.',
            ]);
        }

        $generator = new MigrationGenerator;
        $files = [];

        foreach ($tableDiffs as $diff) {
            $content = $generator->generate($diff);
            $action = $diff->type === 'create' ? 'create' : 'update';
            $filename = date('Y_m_d_His')."_{$action}_{$diff->tableName}_table.php";

            $files[] = [
                'path' => "database/migrations/{$filename}",
                'content' => $content,
                'exists' => false,
                'tableName' => $diff->tableName,
                'type' => $diff->type,
            ];
        }

        return new JsonResponse([
            'success' => true,
            'files' => $files,
        ]);
    }

    /**
     * Write migration files to disk.
     */
    public function migrate(Request $request): JsonResponse
    {
        $filterTables = $request->input('tables');
        $tableDiffs = (new MigrationDiffSorter)->sort($this->collectDiffs($filterTables));

        if (empty($tableDiffs)) {
            return new JsonResponse([
                'success' => true,
                'files' => [],
                'message' => 'All schemas are in sync. Nothing to migrate.',
            ]);
        }

        $generator = new MigrationGenerator;
        $migrationPath = database_path('migrations');
        $files = [];

        foreach ($tableDiffs as $idx => $diff) {
            if ($idx > 0) {
                usleep(1_000_000);
            }

            $path = $generator->write($diff, $migrationPath);
            $files[] = [
                'path' => str_replace(base_path().'/', '', $path),
                'tableName' => $diff->tableName,
                'type' => $diff->type,
            ];
        }

        return new JsonResponse([
            'success' => true,
            'files' => $files,
            'message' => count($files).' migration(s) generated.',
        ]);
    }

    /**
     * Write migration files and run them.
     */
    public function migrateAndRun(Request $request): JsonResponse
    {
        $filterTables = $request->input('tables');
        $tableDiffs = (new MigrationDiffSorter)->sort($this->collectDiffs($filterTables));

        if (empty($tableDiffs)) {
            return new JsonResponse([
                'success' => true,
                'files' => [],
                'message' => 'All schemas are in sync. Nothing to migrate.',
                'migrateOutput' => '',
            ]);
        }

        $generator = new MigrationGenerator;
        $migrationPath = database_path('migrations');
        $files = [];

        foreach ($tableDiffs as $idx => $diff) {
            if ($idx > 0) {
                usleep(1_000_000);
            }

            $path = $generator->write($diff, $migrationPath);
            $files[] = [
                'path' => str_replace(base_path().'/', '', $path),
                'tableName' => $diff->tableName,
                'type' => $diff->type,
            ];
        }

        Artisan::call('migrate');
        $migrateOutput = Artisan::output();

        return new JsonResponse([
            'success' => true,
            'files' => $files,
            'message' => count($files).' migration(s) generated and applied.',
            'migrateOutput' => trim($migrateOutput),
        ]);
    }

    /**
     * Run the full diff pipeline and return structured results.
     *
     * @return array<int, array<string, mixed>>
     */
    private function runDiffPipeline(): array
    {
        $directories = config('schema-craft.schema_paths', [app_path('Schemas')]);

        $discovery = new SchemaDiscovery;
        $reader = new DatabaseReader;
        $tableNorm = new TableDefinitionNormalizer;
        $dbNorm = new DatabaseTableNormalizer;
        $differ = new SchemaDiffer;

        $schemaClasses = $discovery->discover($directories);
        $results = [];

        foreach ($schemaClasses as $schemaClass) {
            $scanner = new SchemaScanner($schemaClass);
            $desired = $scanner->scan();
            $actual = $reader->read($desired->tableName);

            $desiredCanonical = $tableNorm->normalize($desired);
            $actualCanonical = $actual !== null ? $dbNorm->normalize($actual) : null;
            $renameMap = TableDefinitionNormalizer::extractRenameMap($desired);
            $diff = $differ->diff($desiredCanonical, $actualCanonical, $renameMap);

            if ($diff->isEmpty()) {
                $results[] = [
                    'schemaClass' => $schemaClass,
                    'tableName' => $desired->tableName,
                    'status' => 'in_sync',
                    'diff' => null,
                ];
            } else {
                $status = $diff->type === 'create' ? 'new_table' : 'has_changes';
                $results[] = [
                    'schemaClass' => $schemaClass,
                    'tableName' => $desired->tableName,
                    'status' => $status,
                    'diff' => $this->serializeDiff($diff),
                ];
            }
        }

        return $results;
    }

    /**
     * Collect non-empty TableDiff objects, optionally filtered by table names.
     *
     * @param  string[]|null  $filterTables
     * @return TableDiff[]
     */
    private function collectDiffs(?array $filterTables): array
    {
        $directories = config('schema-craft.schema_paths', [app_path('Schemas')]);

        $discovery = new SchemaDiscovery;
        $reader = new DatabaseReader;
        $tableNorm = new TableDefinitionNormalizer;
        $dbNorm = new DatabaseTableNormalizer;
        $differ = new SchemaDiffer;

        $schemaClasses = $discovery->discover($directories);
        $diffs = [];

        foreach ($schemaClasses as $schemaClass) {
            $scanner = new SchemaScanner($schemaClass);
            $desired = $scanner->scan();

            if ($filterTables !== null && ! in_array($desired->tableName, $filterTables, true)) {
                continue;
            }

            $actual = $reader->read($desired->tableName);

            $desiredCanonical = $tableNorm->normalize($desired);
            $actualCanonical = $actual !== null ? $dbNorm->normalize($actual) : null;
            $renameMap = TableDefinitionNormalizer::extractRenameMap($desired);
            $diff = $differ->diff($desiredCanonical, $actualCanonical, $renameMap);

            if (! $diff->isEmpty()) {
                $diffs[] = $diff;
            }
        }

        return $diffs;
    }

    /**
     * Serialize a TableDiff to a JSON-friendly array.
     *
     * @return array<string, mixed>
     */
    private function serializeDiff(TableDiff $diff): array
    {
        $columns = [];
        foreach ($diff->columnDiffs as $col) {
            $entry = [
                'action' => $col->action,
                'column' => $col->columnName,
            ];

            if ($col->action === 'rename') {
                $entry['oldColumn'] = $col->oldColumnName;
            }

            if ($col->desired) {
                $entry['desired'] = [
                    'type' => $col->desired->type,
                    'nullable' => $col->desired->nullable,
                    'unsigned' => $col->desired->unsigned,
                    'length' => $col->desired->length,
                ];
            }

            if ($col->actual) {
                $entry['actual'] = [
                    'type' => $col->actual->type,
                    'nullable' => $col->actual->nullable,
                    'unsigned' => $col->actual->unsigned,
                    'length' => $col->actual->length,
                ];
            }

            $columns[] = $entry;
        }

        $indexes = [];
        foreach ($diff->indexDiffs as $idx) {
            $indexes[] = [
                'action' => $idx->action,
                'columns' => $idx->columns,
                'unique' => $idx->unique,
            ];
        }

        $foreignKeys = [];
        foreach ($diff->fkDiffs as $fk) {
            $foreignKeys[] = [
                'action' => $fk->action,
                'column' => $fk->column,
                'foreignTable' => $fk->foreignTable,
                'foreignColumn' => $fk->foreignColumn,
            ];
        }

        return [
            'type' => $diff->type,
            'columns' => $columns,
            'indexes' => $indexes,
            'foreignKeys' => $foreignKeys,
            'addTimestamps' => $diff->addTimestamps,
            'dropTimestamps' => $diff->dropTimestamps,
            'addSoftDeletes' => $diff->addSoftDeletes,
            'dropSoftDeletes' => $diff->dropSoftDeletes,
        ];
    }
}
