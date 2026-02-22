<?php

namespace SchemaCraft\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SchemaCraft\Migration\DatabaseReader;
use SchemaCraft\Migration\DatabaseTableNormalizer;
use SchemaCraft\Migration\MigrationGenerator;
use SchemaCraft\Migration\SchemaDiffer;
use SchemaCraft\Migration\TableDefinitionNormalizer;
use SchemaCraft\Scanner\SchemaScanner;
use SchemaCraft\Tests\Fixtures\Schemas\TagSchema;
use SchemaCraft\Tests\Fixtures\Schemas\UserSchema;
use SchemaCraft\Tests\TestCase;

/**
 * Full round-trip tests: Schema → scan → normalize → diff → generate → migrate → read back → normalize → no diff.
 *
 * Each test runs against both SQLite and MySQL to verify cross-driver compatibility.
 */
class FullLifecycleTest extends TestCase
{
    private SchemaDiffer $differ;

    private MigrationGenerator $generator;

    private TableDefinitionNormalizer $tableNorm;

    private DatabaseTableNormalizer $dbNorm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->differ = new SchemaDiffer;
        $this->generator = new MigrationGenerator;
        $this->tableNorm = new TableDefinitionNormalizer;
        $this->dbNorm = new DatabaseTableNormalizer;
    }

    /**
     * Return the database connections to test against.
     *
     * @return array<string, string>
     */
    private function connections(): array
    {
        $connections = ['sqlite' => 'testing'];

        if ($this->mysqlAvailable()) {
            $connections['mysql'] = 'mysql_testing';
        }

        return $connections;
    }

    private function mysqlAvailable(): bool
    {
        try {
            DB::connection('mysql_testing')->getPdo();

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Clean up a table on the given connection.
     */
    private function dropTable(string $tableName, string $connection): void
    {
        Schema::connection($connection)->dropIfExists($tableName);
    }

    /**
     * Run a generated migration string by writing to a temp file and requiring it.
     */
    private function runMigration(string $migrationCode, string $connection): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'migration_');
        file_put_contents($tmpFile, $migrationCode);

        $previousDefault = config('database.default');
        config(['database.default' => $connection]);

        try {
            $migration = require $tmpFile;
            $migration->up();
        } finally {
            config(['database.default' => $previousDefault]);
            unlink($tmpFile);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Tags: simple table — id, name, slug (unique), timestamps
    // ──────────────────────────────────────────────────────────────────────

    public function test_tag_schema_round_trip(): void
    {
        $scanner = new SchemaScanner(TagSchema::class);
        $desired = $scanner->scan();
        $desiredCanonical = $this->tableNorm->normalize($desired);

        foreach ($this->connections() as $driver => $connection) {
            $this->dropTable('tags', $connection);

            // 1. First diff — table doesn't exist yet → create
            $reader = new DatabaseReader($connection);
            $actual = $reader->read('tags');
            $this->assertNull($actual, "[{$driver}] Table 'tags' should not exist yet");

            $diff = $this->differ->diff($desiredCanonical, null);
            $this->assertSame('create', $diff->type, "[{$driver}] Should produce a create diff");
            $this->assertFalse($diff->isEmpty(), "[{$driver}] Diff should not be empty");

            // 2. Generate migration and run it
            $migrationCode = $this->generator->generate($diff);
            $this->runMigration($migrationCode, $connection);

            // 3. Verify table exists
            $this->assertTrue(
                Schema::connection($connection)->hasTable('tags'),
                "[{$driver}] Table 'tags' should exist after migration"
            );

            // 4. Read back and diff again — should be empty (in sync)
            $actualAfter = $reader->read('tags');
            $this->assertNotNull($actualAfter, "[{$driver}] Should read table state after creation");

            $actualCanonical = $this->dbNorm->normalize($actualAfter);
            $diffAfter = $this->differ->diff($desiredCanonical, $actualCanonical);
            $this->assertTrue(
                $diffAfter->isEmpty(),
                "[{$driver}] Round-trip diff should be empty. Got column diffs: "
                .json_encode(array_map(fn ($d) => "{$d->action}:{$d->columnName}", $diffAfter->columnDiffs))
                .' index diffs: '.json_encode(array_map(fn ($d) => "{$d->action}:".implode(',', $d->columns), $diffAfter->indexDiffs))
                .' addTimestamps: '.($diffAfter->addTimestamps ? 'true' : 'false')
                .' dropTimestamps: '.($diffAfter->dropTimestamps ? 'true' : 'false')
            );

            // 5. Verify specific column properties
            $idCol = $actualAfter->getColumn('id');
            $this->assertTrue($idCol->primary, "[{$driver}] id should be primary");
            $this->assertTrue($idCol->autoIncrement, "[{$driver}] id should be autoIncrement");

            $nameCol = $actualAfter->getColumn('name');
            $this->assertSame('string', $nameCol->type, "[{$driver}] name should be string type");

            $slugCol = $actualAfter->getColumn('slug');
            $this->assertSame('string', $slugCol->type, "[{$driver}] slug should be string type");

            $this->assertTrue($actualAfter->hasTimestamps(), "[{$driver}] Should have timestamps");

            // Clean up
            $this->dropTable('tags', $connection);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Users: simple table — id, name, email (unique), password, timestamps
    // ──────────────────────────────────────────────────────────────────────

    public function test_user_schema_round_trip(): void
    {
        $scanner = new SchemaScanner(UserSchema::class);
        $desired = $scanner->scan();
        $desiredCanonical = $this->tableNorm->normalize($desired);

        foreach ($this->connections() as $driver => $connection) {
            $this->dropTable('users', $connection);

            $reader = new DatabaseReader($connection);

            // 1. Create
            $diff = $this->differ->diff($desiredCanonical, null);
            $this->assertSame('create', $diff->type, "[{$driver}] Should be a create diff");

            $migrationCode = $this->generator->generate($diff);
            $this->runMigration($migrationCode, $connection);

            // 2. Round-trip — should be in sync
            $actual = $reader->read('users');
            $actualCanonical = $this->dbNorm->normalize($actual);
            $diffAfter = $this->differ->diff($desiredCanonical, $actualCanonical);

            $this->assertTrue(
                $diffAfter->isEmpty(),
                "[{$driver}] Users round-trip diff should be empty. Got column diffs: "
                .json_encode(array_map(fn ($d) => "{$d->action}:{$d->columnName}", $diffAfter->columnDiffs))
            );

            // 3. Verify columns
            $this->assertNotNull($actual->getColumn('name'), "[{$driver}] Should have name column");
            $this->assertNotNull($actual->getColumn('email'), "[{$driver}] Should have email column");
            $this->assertNotNull($actual->getColumn('password'), "[{$driver}] Should have password column");
            $this->assertTrue($actual->hasTimestamps(), "[{$driver}] Should have timestamps");

            $this->dropTable('users', $connection);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Incremental migration: create table, then add a column
    // ──────────────────────────────────────────────────────────────────────

    public function test_incremental_migration(): void
    {
        foreach ($this->connections() as $driver => $connection) {
            $this->dropTable('tags', $connection);

            $reader = new DatabaseReader($connection);

            // Step 1: Create initial table with just id + name + timestamps
            $initialDesired = new \SchemaCraft\Scanner\TableDefinition(
                tableName: 'tags',
                schemaClass: TagSchema::class,
                columns: [
                    new \SchemaCraft\Scanner\ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true, unsigned: true),
                    new \SchemaCraft\Scanner\ColumnDefinition(name: 'name', columnType: 'string'),
                ],
                hasTimestamps: true,
            );

            $initialCanonical = $this->tableNorm->normalize($initialDesired);
            $diff1 = $this->differ->diff($initialCanonical, null);
            $this->runMigration($this->generator->generate($diff1), $connection);

            // Verify table created
            $this->assertTrue(Schema::connection($connection)->hasTable('tags'), "[{$driver}] tags should exist");

            // Step 2: Now the "schema" adds a slug column
            $updatedDesired = new \SchemaCraft\Scanner\TableDefinition(
                tableName: 'tags',
                schemaClass: TagSchema::class,
                columns: [
                    new \SchemaCraft\Scanner\ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true, unsigned: true),
                    new \SchemaCraft\Scanner\ColumnDefinition(name: 'name', columnType: 'string'),
                    new \SchemaCraft\Scanner\ColumnDefinition(name: 'slug', columnType: 'string', unique: true),
                ],
                hasTimestamps: true,
            );

            $updatedCanonical = $this->tableNorm->normalize($updatedDesired);
            $actual = $reader->read('tags');
            $actualCanonical = $this->dbNorm->normalize($actual);
            $diff2 = $this->differ->diff($updatedCanonical, $actualCanonical);

            $this->assertSame('update', $diff2->type, "[{$driver}] Should produce update diff");
            $this->assertFalse($diff2->isEmpty(), "[{$driver}] Should detect missing slug column");

            // Verify the slug column is an add
            $addDiffs = array_filter($diff2->columnDiffs, fn ($d) => $d->action === 'add');
            $this->assertCount(1, $addDiffs, "[{$driver}] Should have exactly one column to add");
            $this->assertSame('slug', array_values($addDiffs)[0]->columnName, "[{$driver}] Added column should be slug");

            // Step 3: Run the update migration
            $this->runMigration($this->generator->generate($diff2), $connection);

            // Step 4: Read back — should be in sync
            $actualAfter = $reader->read('tags');
            $actualAfterCanonical = $this->dbNorm->normalize($actualAfter);
            $diff3 = $this->differ->diff($updatedCanonical, $actualAfterCanonical);

            $this->assertTrue(
                $diff3->isEmpty(),
                "[{$driver}] Should be in sync after incremental migration. Got: "
                .json_encode(array_map(fn ($d) => "{$d->action}:{$d->columnName}", $diff3->columnDiffs))
            );

            // Verify slug column exists
            $slugCol = $actualAfter->getColumn('slug');
            $this->assertNotNull($slugCol, "[{$driver}] slug column should exist");
            $this->assertSame('string', $slugCol->type, "[{$driver}] slug should be string type");

            $this->dropTable('tags', $connection);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Column type change: string → text
    // ──────────────────────────────────────────────────────────────────────

    public function test_column_type_change(): void
    {
        foreach ($this->connections() as $driver => $connection) {
            $this->dropTable('tags', $connection);

            $reader = new DatabaseReader($connection);

            // Step 1: Create table with name as string
            $initial = new \SchemaCraft\Scanner\TableDefinition(
                tableName: 'tags',
                schemaClass: TagSchema::class,
                columns: [
                    new \SchemaCraft\Scanner\ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true, unsigned: true),
                    new \SchemaCraft\Scanner\ColumnDefinition(name: 'name', columnType: 'string'),
                ],
            );

            $initialCanonical = $this->tableNorm->normalize($initial);
            $this->runMigration($this->generator->generate($this->differ->diff($initialCanonical, null)), $connection);

            // Verify initial state
            $actual1 = $reader->read('tags');
            $this->assertSame('string', $actual1->getColumn('name')->type, "[{$driver}] name should start as string");

            // Step 2: Change name to text
            $updated = new \SchemaCraft\Scanner\TableDefinition(
                tableName: 'tags',
                schemaClass: TagSchema::class,
                columns: [
                    new \SchemaCraft\Scanner\ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true, unsigned: true),
                    new \SchemaCraft\Scanner\ColumnDefinition(name: 'name', columnType: 'text'),
                ],
            );

            $updatedCanonical = $this->tableNorm->normalize($updated);
            $actual1Canonical = $this->dbNorm->normalize($actual1);
            $diff = $this->differ->diff($updatedCanonical, $actual1Canonical);
            $this->assertFalse($diff->isEmpty(), "[{$driver}] Should detect type change");

            $modifyDiffs = array_filter($diff->columnDiffs, fn ($d) => $d->action === 'modify');
            $this->assertCount(1, $modifyDiffs, "[{$driver}] Should have one modify diff");
            $this->assertSame('name', array_values($modifyDiffs)[0]->columnName, "[{$driver}] Modified column should be name");

            // Step 3: Run the modify migration
            $this->runMigration($this->generator->generate($diff), $connection);

            // Step 4: Verify
            $actual2 = $reader->read('tags');
            $this->assertSame('text', $actual2->getColumn('name')->type, "[{$driver}] name should now be text");

            $actual2Canonical = $this->dbNorm->normalize($actual2);
            $diffFinal = $this->differ->diff($updatedCanonical, $actual2Canonical);
            $this->assertTrue(
                $diffFinal->isEmpty(),
                "[{$driver}] Should be in sync after type change. Got: "
                .json_encode(array_map(fn ($d) => "{$d->action}:{$d->columnName}", $diffFinal->columnDiffs))
            );

            $this->dropTable('tags', $connection);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Nullable change: non-nullable → nullable
    // ──────────────────────────────────────────────────────────────────────

    public function test_nullable_change(): void
    {
        foreach ($this->connections() as $driver => $connection) {
            $this->dropTable('tags', $connection);

            $reader = new DatabaseReader($connection);

            // Create table with name as NOT NULL
            $initial = new \SchemaCraft\Scanner\TableDefinition(
                tableName: 'tags',
                schemaClass: TagSchema::class,
                columns: [
                    new \SchemaCraft\Scanner\ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true, unsigned: true),
                    new \SchemaCraft\Scanner\ColumnDefinition(name: 'name', columnType: 'string', nullable: false),
                ],
            );

            $initialCanonical = $this->tableNorm->normalize($initial);
            $this->runMigration($this->generator->generate($this->differ->diff($initialCanonical, null)), $connection);

            // Change name to nullable
            $updated = new \SchemaCraft\Scanner\TableDefinition(
                tableName: 'tags',
                schemaClass: TagSchema::class,
                columns: [
                    new \SchemaCraft\Scanner\ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true, unsigned: true),
                    new \SchemaCraft\Scanner\ColumnDefinition(name: 'name', columnType: 'string', nullable: true),
                ],
            );

            $updatedCanonical = $this->tableNorm->normalize($updated);
            $actual = $reader->read('tags');
            $actualCanonical = $this->dbNorm->normalize($actual);
            $diff = $this->differ->diff($updatedCanonical, $actualCanonical);

            $this->assertFalse($diff->isEmpty(), "[{$driver}] Should detect nullable change");

            $this->runMigration($this->generator->generate($diff), $connection);

            $actualAfter = $reader->read('tags');
            $this->assertTrue($actualAfter->getColumn('name')->nullable, "[{$driver}] name should now be nullable");

            $actualAfterCanonical = $this->dbNorm->normalize($actualAfter);
            $diffFinal = $this->differ->diff($updatedCanonical, $actualAfterCanonical);
            $this->assertTrue(
                $diffFinal->isEmpty(),
                "[{$driver}] Should be in sync after nullable change"
            );

            $this->dropTable('tags', $connection);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Timestamps: add timestamps to existing table
    // ──────────────────────────────────────────────────────────────────────

    public function test_add_timestamps_to_existing_table(): void
    {
        foreach ($this->connections() as $driver => $connection) {
            $this->dropTable('tags', $connection);

            $reader = new DatabaseReader($connection);

            // Create table WITHOUT timestamps
            $initial = new \SchemaCraft\Scanner\TableDefinition(
                tableName: 'tags',
                schemaClass: TagSchema::class,
                columns: [
                    new \SchemaCraft\Scanner\ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true, unsigned: true),
                    new \SchemaCraft\Scanner\ColumnDefinition(name: 'name', columnType: 'string'),
                ],
                hasTimestamps: false,
            );

            $initialCanonical = $this->tableNorm->normalize($initial);
            $this->runMigration($this->generator->generate($this->differ->diff($initialCanonical, null)), $connection);

            // Now schema wants timestamps
            $updated = new \SchemaCraft\Scanner\TableDefinition(
                tableName: 'tags',
                schemaClass: TagSchema::class,
                columns: [
                    new \SchemaCraft\Scanner\ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true, unsigned: true),
                    new \SchemaCraft\Scanner\ColumnDefinition(name: 'name', columnType: 'string'),
                ],
                hasTimestamps: true,
            );

            $updatedCanonical = $this->tableNorm->normalize($updated);
            $actual = $reader->read('tags');
            $actualCanonical = $this->dbNorm->normalize($actual);
            $diff = $this->differ->diff($updatedCanonical, $actualCanonical);

            $this->assertTrue($diff->addTimestamps, "[{$driver}] Should want to add timestamps");

            $this->runMigration($this->generator->generate($diff), $connection);

            $actualAfter = $reader->read('tags');
            $this->assertTrue($actualAfter->hasTimestamps(), "[{$driver}] Should have timestamps after migration");

            $actualAfterCanonical = $this->dbNorm->normalize($actualAfter);
            $diffFinal = $this->differ->diff($updatedCanonical, $actualAfterCanonical);
            $this->assertTrue(
                $diffFinal->isEmpty(),
                "[{$driver}] Should be in sync after adding timestamps"
            );

            $this->dropTable('tags', $connection);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Verify generated migration is valid PHP
    // ──────────────────────────────────────────────────────────────────────

    public function test_generated_migration_is_valid_php(): void
    {
        $scanner = new SchemaScanner(TagSchema::class);
        $desired = $scanner->scan();
        $desiredCanonical = $this->tableNorm->normalize($desired);

        $diff = $this->differ->diff($desiredCanonical, null);
        $code = $this->generator->generate($diff);

        $tmpFile = tempnam(sys_get_temp_dir(), 'lifecycle_php_check_');
        file_put_contents($tmpFile, $code);

        $output = [];
        $exitCode = 0;
        exec("php -l {$tmpFile} 2>&1", $output, $exitCode);
        unlink($tmpFile);

        $this->assertSame(0, $exitCode, 'Generated migration has PHP syntax errors: '.implode("\n", $output));
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Idempotency: running the same schema scan twice produces empty diff
    // ──────────────────────────────────────────────────────────────────────

    public function test_idempotent_diff_after_migration(): void
    {
        foreach ($this->connections() as $driver => $connection) {
            $this->dropTable('tags', $connection);

            $reader = new DatabaseReader($connection);
            $scanner = new SchemaScanner(TagSchema::class);
            $desired = $scanner->scan();
            $desiredCanonical = $this->tableNorm->normalize($desired);

            // Create
            $diff1 = $this->differ->diff($desiredCanonical, null);
            $this->runMigration($this->generator->generate($diff1), $connection);

            // Diff twice in a row — both should be empty
            $actual = $reader->read('tags');
            $actualCanonical = $this->dbNorm->normalize($actual);
            $diff2 = $this->differ->diff($desiredCanonical, $actualCanonical);
            $diff3 = $this->differ->diff($desiredCanonical, $actualCanonical);

            $this->assertTrue($diff2->isEmpty(), "[{$driver}] Second diff should be empty");
            $this->assertTrue($diff3->isEmpty(), "[{$driver}] Third diff should also be empty");

            $this->dropTable('tags', $connection);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Rename column: old_title → title (same type)
    // ──────────────────────────────────────────────────────────────────────

    public function test_rename_column_round_trip(): void
    {
        foreach ($this->connections() as $driver => $connection) {
            $this->dropTable('posts', $connection);

            $reader = new DatabaseReader($connection);

            // Step 1: Create table with old_title
            $initial = new \SchemaCraft\Scanner\TableDefinition(
                tableName: 'posts',
                schemaClass: 'App\Schemas\PostSchema',
                columns: [
                    new \SchemaCraft\Scanner\ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true, unsigned: true),
                    new \SchemaCraft\Scanner\ColumnDefinition(name: 'old_title', columnType: 'string'),
                ],
            );

            $initialCanonical = $this->tableNorm->normalize($initial);
            $this->runMigration($this->generator->generate($this->differ->diff($initialCanonical, null)), $connection);

            // Verify initial state
            $actual1 = $reader->read('posts');
            $this->assertNotNull($actual1->getColumn('old_title'), "[{$driver}] old_title should exist initially");

            // Step 2: Schema now has title with #[RenamedFrom('old_title')]
            $renamed = new \SchemaCraft\Scanner\TableDefinition(
                tableName: 'posts',
                schemaClass: 'App\Schemas\PostSchema',
                columns: [
                    new \SchemaCraft\Scanner\ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true, unsigned: true),
                    new \SchemaCraft\Scanner\ColumnDefinition(name: 'title', columnType: 'string', renamedFrom: 'old_title'),
                ],
            );

            $renamedCanonical = $this->tableNorm->normalize($renamed);
            $renameMap = TableDefinitionNormalizer::extractRenameMap($renamed);
            $actual1Canonical = $this->dbNorm->normalize($actual1);
            $diff = $this->differ->diff($renamedCanonical, $actual1Canonical, $renameMap);

            $renameDiffs = array_filter($diff->columnDiffs, fn ($d) => $d->action === 'rename');
            $addDiffs = array_filter($diff->columnDiffs, fn ($d) => $d->action === 'add');
            $dropDiffs = array_filter($diff->columnDiffs, fn ($d) => $d->action === 'drop');

            $this->assertCount(1, $renameDiffs, "[{$driver}] Should detect rename");
            $this->assertCount(0, $addDiffs, "[{$driver}] Should not have any adds");
            $this->assertCount(0, $dropDiffs, "[{$driver}] Should not have any drops");

            // Step 3: Run the rename migration
            $this->runMigration($this->generator->generate($diff), $connection);

            // Step 4: Verify — old_title gone, title exists
            $actual2 = $reader->read('posts');
            $this->assertNull($actual2->getColumn('old_title'), "[{$driver}] old_title should be gone after rename");
            $this->assertNotNull($actual2->getColumn('title'), "[{$driver}] title should exist after rename");
            $this->assertSame('string', $actual2->getColumn('title')->type, "[{$driver}] title type should be string");

            // Step 5: Diff again — should be empty (in sync)
            $actual2Canonical = $this->dbNorm->normalize($actual2);
            $diffAfter = $this->differ->diff($renamedCanonical, $actual2Canonical, $renameMap);
            $this->assertTrue(
                $diffAfter->isEmpty(),
                "[{$driver}] Should be in sync after rename. Got: "
                .json_encode(array_map(fn ($d) => "{$d->action}:{$d->columnName}", $diffAfter->columnDiffs))
            );

            $this->dropTable('posts', $connection);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Rename + type change: old_title (string) → title (text)
    // ──────────────────────────────────────────────────────────────────────

    public function test_rename_with_type_change_round_trip(): void
    {
        foreach ($this->connections() as $driver => $connection) {
            $this->dropTable('posts', $connection);

            $reader = new DatabaseReader($connection);

            // Step 1: Create table with old_title as string
            $initial = new \SchemaCraft\Scanner\TableDefinition(
                tableName: 'posts',
                schemaClass: 'App\Schemas\PostSchema',
                columns: [
                    new \SchemaCraft\Scanner\ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true, unsigned: true),
                    new \SchemaCraft\Scanner\ColumnDefinition(name: 'old_title', columnType: 'string'),
                ],
            );

            $initialCanonical = $this->tableNorm->normalize($initial);
            $this->runMigration($this->generator->generate($this->differ->diff($initialCanonical, null)), $connection);

            // Step 2: Schema renames to title and changes type to text
            $renamed = new \SchemaCraft\Scanner\TableDefinition(
                tableName: 'posts',
                schemaClass: 'App\Schemas\PostSchema',
                columns: [
                    new \SchemaCraft\Scanner\ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true, unsigned: true),
                    new \SchemaCraft\Scanner\ColumnDefinition(name: 'title', columnType: 'text', renamedFrom: 'old_title'),
                ],
            );

            $renamedCanonical = $this->tableNorm->normalize($renamed);
            $renameMap = TableDefinitionNormalizer::extractRenameMap($renamed);
            $actual1 = $reader->read('posts');
            $actual1Canonical = $this->dbNorm->normalize($actual1);
            $diff = $this->differ->diff($renamedCanonical, $actual1Canonical, $renameMap);

            $renameDiffs = array_filter($diff->columnDiffs, fn ($d) => $d->action === 'rename');
            $modifyDiffs = array_filter($diff->columnDiffs, fn ($d) => $d->action === 'modify');

            $this->assertCount(1, $renameDiffs, "[{$driver}] Should detect rename");
            $this->assertCount(1, $modifyDiffs, "[{$driver}] Should detect modify for type change");

            // Step 3: Run the rename+modify migration
            $this->runMigration($this->generator->generate($diff), $connection);

            // Step 4: Verify — title exists as text
            $actual2 = $reader->read('posts');
            $this->assertNull($actual2->getColumn('old_title'), "[{$driver}] old_title should be gone");
            $this->assertNotNull($actual2->getColumn('title'), "[{$driver}] title should exist");
            $this->assertSame('text', $actual2->getColumn('title')->type, "[{$driver}] title should be text type");

            // Step 5: Diff again — should be empty
            $actual2Canonical = $this->dbNorm->normalize($actual2);
            $diffAfter = $this->differ->diff($renamedCanonical, $actual2Canonical, $renameMap);
            $this->assertTrue(
                $diffAfter->isEmpty(),
                "[{$driver}] Should be in sync after rename+type change. Got: "
                .json_encode(array_map(fn ($d) => "{$d->action}:{$d->columnName}", $diffAfter->columnDiffs))
            );

            $this->dropTable('posts', $connection);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Stale attribute: RenamedFrom points to old_title but it's already gone
    // ──────────────────────────────────────────────────────────────────────

    public function test_stale_renamed_from_round_trip(): void
    {
        foreach ($this->connections() as $driver => $connection) {
            $this->dropTable('posts', $connection);

            $reader = new DatabaseReader($connection);

            // Step 1: Create table with title already named correctly
            $initial = new \SchemaCraft\Scanner\TableDefinition(
                tableName: 'posts',
                schemaClass: 'App\Schemas\PostSchema',
                columns: [
                    new \SchemaCraft\Scanner\ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true, unsigned: true),
                    new \SchemaCraft\Scanner\ColumnDefinition(name: 'title', columnType: 'string'),
                ],
            );

            $initialCanonical = $this->tableNorm->normalize($initial);
            $this->runMigration($this->generator->generate($this->differ->diff($initialCanonical, null)), $connection);

            // Step 2: Schema still has stale #[RenamedFrom('old_title')] — old_title doesn't exist
            $staleRenamed = new \SchemaCraft\Scanner\TableDefinition(
                tableName: 'posts',
                schemaClass: 'App\Schemas\PostSchema',
                columns: [
                    new \SchemaCraft\Scanner\ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true, unsigned: true),
                    new \SchemaCraft\Scanner\ColumnDefinition(name: 'title', columnType: 'string', renamedFrom: 'old_title'),
                ],
            );

            $staleCanonical = $this->tableNorm->normalize($staleRenamed);
            $renameMap = TableDefinitionNormalizer::extractRenameMap($staleRenamed);
            $actual = $reader->read('posts');
            $actualCanonical = $this->dbNorm->normalize($actual);
            $diff = $this->differ->diff($staleCanonical, $actualCanonical, $renameMap);

            // Should produce empty diff — stale attribute is harmless
            $this->assertTrue(
                $diff->isEmpty(),
                "[{$driver}] Stale RenamedFrom should produce empty diff. Got: "
                .json_encode(array_map(fn ($d) => "{$d->action}:{$d->columnName}", $diff->columnDiffs))
            );

            $this->dropTable('posts', $connection);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Round-trip test: create → read → normalize both → diff → assert empty
    // ──────────────────────────────────────────────────────────────────────

    public function test_canonical_round_trip_produces_empty_diff(): void
    {
        $scanner = new SchemaScanner(TagSchema::class);
        $desired = $scanner->scan();
        $desiredCanonical = $this->tableNorm->normalize($desired);

        foreach ($this->connections() as $driver => $connection) {
            $this->dropTable('tags', $connection);

            $reader = new DatabaseReader($connection);

            // Create table from canonical
            $createDiff = $this->differ->diff($desiredCanonical, null);
            $this->runMigration($this->generator->generate($createDiff), $connection);

            // Read back from DB and normalize
            $dbState = $reader->read('tags');
            $this->assertNotNull($dbState, "[{$driver}] Should read table state");

            $dbCanonical = $this->dbNorm->normalize($dbState);

            // Both sides are now CanonicalTable — diff should be empty
            $diff = $this->differ->diff($desiredCanonical, $dbCanonical);
            $this->assertTrue(
                $diff->isEmpty(),
                "[{$driver}] Canonical round-trip should produce empty diff. Got column diffs: "
                .json_encode(array_map(fn ($d) => "{$d->action}:{$d->columnName}", $diff->columnDiffs))
                .' index diffs: '.json_encode(array_map(fn ($d) => "{$d->action}:".implode(',', $d->columns), $diff->indexDiffs))
                .' fk diffs: '.json_encode(array_map(fn ($d) => "{$d->action}:{$d->column}", $diff->fkDiffs))
                .' addTimestamps: '.($diff->addTimestamps ? 'true' : 'false')
                .' dropTimestamps: '.($diff->dropTimestamps ? 'true' : 'false')
            );

            $this->dropTable('tags', $connection);
        }
    }
}
