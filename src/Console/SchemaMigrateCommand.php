<?php

namespace SchemaCraft\Console;

use Illuminate\Console\Command;
use SchemaCraft\Migration\DatabaseReader;
use SchemaCraft\Migration\DatabaseTableNormalizer;
use SchemaCraft\Migration\MigrationGenerator;
use SchemaCraft\Migration\SchemaDiffer;
use SchemaCraft\Migration\SchemaDiscovery;
use SchemaCraft\Migration\TableDefinitionNormalizer;
use SchemaCraft\Scanner\SchemaScanner;

class SchemaMigrateCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'schema:migrate
        {--connection= : The database connection to use}
        {--path=* : Directories to scan for schema classes}
        {--migration-path= : Where to write migration files}
        {--run : Run the migrations immediately after generating}';

    /**
     * @var string
     */
    protected $description = 'Generate migration files from schema definitions';

    public function handle(): int
    {
        $directories = $this->getDirectories();
        $connection = $this->option('connection');
        $migrationPath = $this->getMigrationPath();

        $discovery = new SchemaDiscovery;
        $reader = new DatabaseReader($connection);
        $tableNorm = new TableDefinitionNormalizer;
        $dbNorm = new DatabaseTableNormalizer;
        $differ = new SchemaDiffer;
        $generator = new MigrationGenerator;

        $schemaClasses = $discovery->discover($directories);

        if (empty($schemaClasses)) {
            $this->warn('No schema classes found.');

            return self::SUCCESS;
        }

        $generated = [];

        foreach ($schemaClasses as $schemaClass) {
            $scanner = new SchemaScanner($schemaClass);
            $desired = $scanner->scan();
            $actual = $reader->read($desired->tableName);

            $desiredCanonical = $tableNorm->normalize($desired);
            $actualCanonical = $actual !== null ? $dbNorm->normalize($actual) : null;
            $renameMap = TableDefinitionNormalizer::extractRenameMap($desired);
            $diff = $differ->diff($desiredCanonical, $actualCanonical, $renameMap);

            if ($diff->isEmpty()) {
                continue;
            }

            // Add a small delay between files to ensure unique timestamps
            if (! empty($generated)) {
                usleep(1_000_000); // 1 second
            }

            $path = $generator->write($diff, $migrationPath);
            $generated[] = $path;

            $action = $diff->type === 'create' ? 'Create' : 'Update';
            $this->line("  <fg=green>Created:</> {$action} {$desired->tableName} → ".basename($path));
        }

        if (empty($generated)) {
            $this->info('Nothing to migrate. All schemas are in sync.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info(count($generated).' migration'.(count($generated) !== 1 ? 's' : '').' generated.');

        if ($this->option('run')) {
            $this->newLine();
            $this->call('migrate');
        }

        return self::SUCCESS;
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

        return [app_path('Schemas')];
    }

    private function getMigrationPath(): string
    {
        $path = $this->option('migration-path');

        if ($path !== null) {
            return $path;
        }

        return database_path('migrations');
    }
}
