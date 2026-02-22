<?php

namespace SchemaCraft\Console;

use Illuminate\Console\Command;
use SchemaCraft\Migration\DatabaseReader;
use SchemaCraft\Migration\DatabaseTableNormalizer;
use SchemaCraft\Migration\SchemaDiffer;
use SchemaCraft\Migration\SchemaDiscovery;
use SchemaCraft\Migration\TableDefinitionNormalizer;
use SchemaCraft\Scanner\SchemaScanner;

class SchemaStatusCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'schema:status
        {--connection= : The database connection to use}
        {--path=* : Directories to scan for schema classes}';

    /**
     * @var string
     */
    protected $description = 'Show which schema-defined tables are in sync with the database';

    public function handle(): int
    {
        $directories = $this->getDirectories();
        $connection = $this->option('connection');

        $discovery = new SchemaDiscovery;
        $reader = new DatabaseReader($connection);
        $tableNorm = new TableDefinitionNormalizer;
        $dbNorm = new DatabaseTableNormalizer;
        $differ = new SchemaDiffer;

        $schemaClasses = $discovery->discover($directories);

        if (empty($schemaClasses)) {
            $this->warn('No schema classes found.');

            return self::SUCCESS;
        }

        $hasChanges = false;

        foreach ($schemaClasses as $schemaClass) {
            $scanner = new SchemaScanner($schemaClass);
            $desired = $scanner->scan();
            $actual = $reader->read($desired->tableName);

            $desiredCanonical = $tableNorm->normalize($desired);
            $actualCanonical = $actual !== null ? $dbNorm->normalize($actual) : null;
            $renameMap = TableDefinitionNormalizer::extractRenameMap($desired);
            $diff = $differ->diff($desiredCanonical, $actualCanonical, $renameMap);

            if ($diff->isEmpty()) {
                $this->line("  <fg=green>✓</> {$desired->tableName}");
            } else {
                $hasChanges = true;
                $changeCount = count($diff->columnDiffs) + count($diff->indexDiffs) + count($diff->fkDiffs);

                if ($diff->addTimestamps) {
                    $changeCount++;
                }

                if ($diff->dropTimestamps) {
                    $changeCount++;
                }

                if ($diff->addSoftDeletes) {
                    $changeCount++;
                }

                if ($diff->dropSoftDeletes) {
                    $changeCount++;
                }

                $label = $diff->type === 'create'
                    ? 'table does not exist'
                    : "{$changeCount} change".($changeCount !== 1 ? 's' : '').' detected';

                $this->line("  <fg=red>✗</> {$desired->tableName} — {$label}");

                $this->renderDiffDetails($diff);
            }
        }

        if (! $hasChanges) {
            $this->newLine();
            $this->info('All schemas are in sync.');
        }

        return $hasChanges ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return string[]
     */
    private function getDirectories(): array
    {
        $paths = $this->option('path');

        if (! empty($paths)) {
            return $paths;
        }

        // Default: scan app/Schemas directory
        return [app_path('Schemas')];
    }

    private function renderDiffDetails(\SchemaCraft\Migration\TableDiff $diff): void
    {
        foreach ($diff->columnDiffs as $colDiff) {
            $symbol = match ($colDiff->action) {
                'add' => '<fg=green>+</>',
                'modify' => '<fg=yellow>~</>',
                'drop' => '<fg=red>-</>',
                'rename' => '<fg=blue>→</>',
            };

            if ($colDiff->action === 'rename') {
                $this->line("      {$symbol} rename column: {$colDiff->oldColumnName} → {$colDiff->columnName}");
            } else {
                $type = $colDiff->desired?->type ?? $colDiff->actual?->type ?? 'unknown';
                $this->line("      {$symbol} {$colDiff->action} column: {$colDiff->columnName} ({$type})");
            }
        }

        foreach ($diff->indexDiffs as $idxDiff) {
            $symbol = $idxDiff->action === 'add' ? '<fg=green>+</>' : '<fg=red>-</>';
            $cols = implode(', ', $idxDiff->columns);
            $type = $idxDiff->unique ? 'unique' : 'index';
            $this->line("      {$symbol} {$idxDiff->action} {$type}: [{$cols}]");
        }

        foreach ($diff->fkDiffs as $fkDiff) {
            $symbol = $fkDiff->action === 'add' ? '<fg=green>+</>' : '<fg=red>-</>';
            $this->line("      {$symbol} {$fkDiff->action} foreign key: {$fkDiff->column}");
        }

        if ($diff->addTimestamps) {
            $this->line('      <fg=green>+</> add timestamps');
        }

        if ($diff->dropTimestamps) {
            $this->line('      <fg=red>-</> drop timestamps');
        }

        if ($diff->addSoftDeletes) {
            $this->line('      <fg=green>+</> add soft deletes');
        }

        if ($diff->dropSoftDeletes) {
            $this->line('      <fg=red>-</> drop soft deletes');
        }
    }
}
