<?php

namespace SchemaCraft\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use SchemaCraft\Generator\SchemaFileGenerator;
use SchemaCraft\Migration\DatabaseReader;

class SchemaFromDatabaseCommand extends Command
{
    protected $signature = 'schema:from-database
        {--connection= : Database connection to read from}
        {--table=* : Specific tables to import (default: all)}
        {--exclude=* : Tables to exclude}
        {--no-model : Do not generate model files}
        {--force : Overwrite existing schema/model files}
        {--schema-path= : Output directory for schema files (relative to base_path)}
        {--model-path= : Output directory for model files (relative to base_path)}
        {--schema-namespace= : Namespace for generated schemas}
        {--model-namespace= : Namespace for generated models}';

    protected $description = 'Generate schema and model files from an existing database';

    /**
     * Laravel internal tables to skip by default.
     */
    private const LARAVEL_INTERNAL_TABLES = [
        'migrations',
        'password_reset_tokens',
        'password_resets',
        'personal_access_tokens',
        'failed_jobs',
        'jobs',
        'job_batches',
        'cache',
        'cache_locks',
        'sessions',
        'telescope_entries',
        'telescope_entries_tags',
        'telescope_monitoring',
        'pulse_aggregates',
        'pulse_entries',
        'pulse_values',
    ];

    public function handle(Filesystem $files): int
    {
        $connection = $this->option('connection') ?: null;
        $reader = new DatabaseReader($connection);
        $generator = new SchemaFileGenerator;

        // Resolve output paths and namespaces
        $schemaDir = $this->option('schema-path')
            ? base_path($this->option('schema-path'))
            : app_path('Schemas');
        $modelDir = $this->option('model-path')
            ? base_path($this->option('model-path'))
            : app_path('Models');
        $schemaNamespace = $this->option('schema-namespace') ?? 'App\\Schemas';
        $modelNamespace = $this->option('model-namespace') ?? 'App\\Models';

        // 1. Get all table names
        $allTableNames = $reader->tables();

        if (count($allTableNames) === 0) {
            $this->components->warn('No tables found in the database.');

            return self::SUCCESS;
        }

        // 2. Filter tables
        $tableNames = $this->filterTables($allTableNames);

        if (count($tableNames) === 0) {
            $this->components->warn('No tables to import after filtering.');

            return self::SUCCESS;
        }

        // 3. Read full table state for each
        $allTables = [];
        foreach ($tableNames as $tableName) {
            $tableState = $reader->read($tableName);
            if ($tableState !== null) {
                $allTables[$tableName] = $tableState;
            }
        }

        // 4. Detect and separate pivot tables
        $pivotTables = [];
        $regularTables = [];
        /** @var array<string, array<string, string>> $pivotMap */
        $pivotMap = []; // [regularTable => [relatedTable => pivotTableName]]

        foreach ($allTables as $tableName => $tableState) {
            $pivot = $generator->detectPivotTable($tableState);
            if ($pivot !== null) {
                $pivotTables[$tableName] = $pivot;
                $this->components->twoColumnDetail(
                    "<fg=yellow>Pivot</>  {$tableName}",
                    "<fg=gray>{$pivot['tableA']} ↔ {$pivot['tableB']}</>"
                );

                // Build pivot map for both sides
                if (isset($allTables[$pivot['tableA']])) {
                    $pivotMap[$pivot['tableA']][$pivot['tableB']] = $tableName;
                }
                if (isset($allTables[$pivot['tableB']])) {
                    $pivotMap[$pivot['tableB']][$pivot['tableA']] = $tableName;
                }
            } else {
                $regularTables[$tableName] = $tableState;
            }
        }

        // 5. Generate schema + model files for regular tables
        $schemasCreated = 0;
        $modelsCreated = 0;
        $skipped = 0;

        foreach ($regularTables as $tableName => $tableState) {
            $pivotRelationships = $pivotMap[$tableName] ?? [];

            $result = $generator->generate(
                table: $tableState,
                allTables: $regularTables,
                pivotRelationships: $pivotRelationships,
                schemaNamespace: $schemaNamespace,
                modelNamespace: $modelNamespace,
            );

            // Write schema file
            $schemaPath = $schemaDir."/{$result->schemaClassName}.php";
            if (! $this->option('force') && $files->exists($schemaPath)) {
                $this->components->twoColumnDetail(
                    "<fg=yellow>Skipped</>  {$result->schemaClassName}",
                    'already exists'
                );
                $skipped++;
            } else {
                $files->ensureDirectoryExists(dirname($schemaPath));
                $files->put($schemaPath, $result->schemaContent);
                $this->components->twoColumnDetail(
                    "<fg=green>Schema</>  {$result->schemaClassName}",
                    $schemaPath
                );
                $schemasCreated++;
            }

            // Write model file
            if (! $this->option('no-model')) {
                $modelPath = $modelDir."/{$result->modelClassName}.php";
                if (! $this->option('force') && $files->exists($modelPath)) {
                    $this->components->twoColumnDetail(
                        "<fg=yellow>Skipped</>  {$result->modelClassName}",
                        'already exists'
                    );
                    $skipped++;
                } else {
                    $files->ensureDirectoryExists(dirname($modelPath));
                    $files->put($modelPath, $result->modelContent);
                    $this->components->twoColumnDetail(
                        "<fg=green>Model</>   {$result->modelClassName}",
                        $modelPath
                    );
                    $modelsCreated++;
                }
            }
        }

        // 6. Summary
        $this->newLine();
        $total = $schemasCreated + $modelsCreated;
        $this->components->info("Generated {$schemasCreated} schemas and {$modelsCreated} models from database.");

        if ($skipped > 0) {
            $this->components->warn("Skipped {$skipped} files that already exist. Use --force to overwrite.");
        }

        if (count($pivotTables) > 0) {
            $this->components->info('Detected '.count($pivotTables).' pivot table(s) — BelongsToMany relationships added to related schemas.');
        }

        return self::SUCCESS;
    }

    /**
     * Filter table names based on options and internal skip list.
     *
     * @param  string[]  $tableNames
     * @return string[]
     */
    private function filterTables(array $tableNames): array
    {
        // Remove Laravel internal tables
        $tableNames = array_filter(
            $tableNames,
            fn (string $name) => ! in_array($name, self::LARAVEL_INTERNAL_TABLES, true),
        );

        // Apply --table filter
        $onlyTables = $this->option('table');
        if (count($onlyTables) > 0) {
            $tableNames = array_filter(
                $tableNames,
                fn (string $name) => in_array($name, $onlyTables, true),
            );
        }

        // Apply --exclude filter
        $excludeTables = $this->option('exclude');
        if (count($excludeTables) > 0) {
            $tableNames = array_filter(
                $tableNames,
                fn (string $name) => ! in_array($name, $excludeTables, true),
            );
        }

        return array_values($tableNames);
    }
}
