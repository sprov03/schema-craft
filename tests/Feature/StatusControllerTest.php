<?php

namespace SchemaCraft\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use SchemaCraft\Tests\TestCase;

class StatusControllerTest extends TestCase
{
    private Filesystem $files;

    private string $tempDir;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['env'] = 'local';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->tempDir = sys_get_temp_dir().'/status-ctrl-test-'.uniqid();
        $this->files->makeDirectory($this->tempDir.'/app/Schemas', 0755, true);
        $this->files->makeDirectory($this->tempDir.'/database/migrations', 0755, true);

        $this->app->useAppPath($this->tempDir.'/app');
        $this->app->setBasePath($this->tempDir);

        // Point schema discovery at our temp schemas directory
        $this->app['config']->set('schema-craft.schema_paths', [$this->tempDir.'/app/Schemas']);
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    private function createSchemaFile(string $name, array $columns = []): void
    {
        $colDefs = '';
        foreach ($columns as $col) {
            $attrs = '';
            if (isset($col['type'])) {
                $attrs .= "    #[ColumnType('{$col['type']}')]\n";
            }
            if (! empty($col['nullable'])) {
                $attrs .= "    #[\\SchemaCraft\\Attributes\\Nullable]\n";
            }
            $phpType = $col['phpType'] ?? 'string';
            $colDefs .= "{$attrs}    public {$phpType} \${$col['name']};\n\n";
        }

        $content = <<<PHP
<?php

namespace App\Schemas;

use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\ColumnType;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Schema;
use SchemaCraft\Traits\TimestampsSchema;

class {$name}Schema extends Schema
{
    use TimestampsSchema;

    #[Primary]
    #[AutoIncrement]
    public int \$id;

{$colDefs}}
PHP;

        $this->files->put($this->tempDir."/app/Schemas/{$name}Schema.php", $content);

        require_once $this->tempDir."/app/Schemas/{$name}Schema.php";
    }

    private function createDbTable(string $tableName, \Closure $callback): void
    {
        $this->app['db']->connection()->getSchemaBuilder()->create($tableName, $callback);
    }

    // ─── GET /api/status ──────────────────────────────

    public function test_status_detects_new_table(): void
    {
        $this->createSchemaFile('StatusDog', [
            ['name' => 'name', 'phpType' => 'string'],
        ]);

        $response = $this->getJson('/_schema-craft/api/status');

        $response->assertOk();

        $schemas = $response->json('schemas');
        $dog = collect($schemas)->firstWhere('tableName', 'status_dogs');
        $this->assertNotNull($dog);
        $this->assertEquals('new_table', $dog['status']);
        $this->assertNotNull($dog['diff']);
        $this->assertEquals('create', $dog['diff']['type']);
    }

    public function test_status_returns_in_sync_when_matching(): void
    {
        $this->createDbTable('status_cats', function ($table) {
            $table->id();
            $table->timestamps();
        });

        $this->createSchemaFile('StatusCat');

        $response = $this->getJson('/_schema-craft/api/status');

        $response->assertOk();

        $schemas = $response->json('schemas');
        $cat = collect($schemas)->firstWhere('tableName', 'status_cats');
        $this->assertNotNull($cat);
        $this->assertEquals('in_sync', $cat['status']);
        $this->assertNull($cat['diff']);
    }

    public function test_status_detects_changes(): void
    {
        $this->createDbTable('status_birds', function ($table) {
            $table->id();
            $table->timestamps();
        });

        $this->createSchemaFile('StatusBird', [
            ['name' => 'species', 'phpType' => 'string'],
        ]);

        $response = $this->getJson('/_schema-craft/api/status');

        $response->assertOk();

        $schemas = $response->json('schemas');
        $bird = collect($schemas)->firstWhere('tableName', 'status_birds');
        $this->assertNotNull($bird);
        $this->assertEquals('has_changes', $bird['status']);
        $this->assertNotNull($bird['diff']);
        $this->assertEquals('update', $bird['diff']['type']);

        // Should have an add column diff for 'species'
        $addedCols = array_filter($bird['diff']['columns'], function ($c) {
            return $c['action'] === 'add' && $c['column'] === 'species';
        });
        $this->assertNotEmpty($addedCols);
    }

    public function test_status_returns_summary_counts(): void
    {
        $this->createSchemaFile('StatusFish');
        $this->createSchemaFile('StatusSnake', [
            ['name' => 'length', 'phpType' => 'int'],
        ]);

        $response = $this->getJson('/_schema-craft/api/status');

        $response->assertOk();

        $summary = $response->json('summary');
        $this->assertEquals(2, $summary['total']);
        $this->assertArrayHasKey('inSync', $summary);
        $this->assertArrayHasKey('hasChanges', $summary);
        $this->assertArrayHasKey('newTables', $summary);
        // Both are new tables (no DB tables exist)
        $this->assertEquals(2, $summary['newTables']);
    }

    // ─── POST /api/migrate/preview ────────────────────

    public function test_migrate_preview_returns_code_without_writing(): void
    {
        $this->createSchemaFile('StatusLizard', [
            ['name' => 'color', 'phpType' => 'string'],
        ]);

        $response = $this->postJson('/_schema-craft/api/migrate/preview');

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $files = $response->json('files');
        $this->assertNotEmpty($files);

        $lizardFile = collect($files)->first(function ($f) {
            return str_contains($f['tableName'], 'status_lizards');
        });
        $this->assertNotNull($lizardFile);
        $this->assertNotEmpty($lizardFile['content']);
        $this->assertEquals('create', $lizardFile['type']);

        // No files on disk
        $migrations = glob($this->tempDir.'/database/migrations/*.php');
        $this->assertEmpty($migrations);
    }

    public function test_migrate_preview_empty_when_in_sync(): void
    {
        $this->createDbTable('status_rocks', function ($table) {
            $table->id();
            $table->timestamps();
        });

        $this->createSchemaFile('StatusRock');

        $response = $this->postJson('/_schema-craft/api/migrate/preview');

        $response->assertOk();
        $this->assertEmpty($response->json('files'));
    }

    public function test_migrate_preview_filters_by_table(): void
    {
        $this->createSchemaFile('StatusAlpha', [['name' => 'val', 'phpType' => 'string']]);
        $this->createSchemaFile('StatusBeta', [['name' => 'val', 'phpType' => 'string']]);

        $response = $this->postJson('/_schema-craft/api/migrate/preview', [
            'tables' => ['status_alphas'],
        ]);

        $response->assertOk();
        $files = $response->json('files');
        $this->assertNotEmpty($files);

        // All returned files should be for status_alphas only
        foreach ($files as $f) {
            $this->assertEquals('status_alphas', $f['tableName']);
        }
    }

    // ─── POST /api/migrate ────────────────────────────

    public function test_migrate_writes_files_to_disk(): void
    {
        $this->createSchemaFile('StatusFrog', [
            ['name' => 'habitat', 'phpType' => 'string'],
        ]);

        $response = $this->postJson('/_schema-craft/api/migrate');

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $files = $response->json('files');
        $this->assertNotEmpty($files);

        // Migration file should exist on disk
        $frogFile = collect($files)->first(function ($f) {
            return str_contains($f['tableName'], 'status_frogs');
        });
        $this->assertNotNull($frogFile);
        $this->assertFileExists($this->tempDir.'/'.$frogFile['path']);
    }

    // ─── POST /api/migrate/run ────────────────────────

    public function test_migrate_and_run_writes_and_returns_output(): void
    {
        $this->createSchemaFile('StatusOwl', [
            ['name' => 'wingspan', 'phpType' => 'int'],
        ]);

        $response = $this->postJson('/_schema-craft/api/migrate/run');

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $files = $response->json('files');
        $this->assertNotEmpty($files);

        // Should have migration output (even if migration itself has issues on SQLite)
        $this->assertArrayHasKey('migrateOutput', $response->json());

        // Migration file should exist on disk
        $owlFile = collect($files)->first(function ($f) {
            return str_contains($f['tableName'], 'status_owls');
        });
        $this->assertNotNull($owlFile);
        $this->assertFileExists($this->tempDir.'/'.$owlFile['path']);
    }
}
