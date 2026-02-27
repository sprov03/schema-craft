<?php

namespace SchemaCraft\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use SchemaCraft\Tests\TestCase;

class SchemaControllerTest extends TestCase
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
        $this->tempDir = sys_get_temp_dir().'/schema-ctrl-test-'.uniqid();
        $this->files->makeDirectory($this->tempDir.'/app/Models', 0755, true);
        $this->files->makeDirectory($this->tempDir.'/app/Schemas', 0755, true);

        $this->app->useAppPath($this->tempDir.'/app');
        $this->app->setBasePath($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    // ─── Install Status ─────────────────────────────────────

    public function test_install_status_when_not_installed(): void
    {
        $response = $this->getJson('/_schema-craft/api/install/status');

        $response->assertOk();
        $response->assertJson([
            'installed' => false,
            'path' => 'app/Models/BaseModel.php',
        ]);
    }

    public function test_install_status_when_installed(): void
    {
        $this->files->put($this->tempDir.'/app/Models/BaseModel.php', '<?php class BaseModel {}');

        $response = $this->getJson('/_schema-craft/api/install/status');

        $response->assertOk();
        $response->assertJson(['installed' => true]);
    }

    // ─── Install ────────────────────────────────────────────

    public function test_install_creates_base_model(): void
    {
        $response = $this->postJson('/_schema-craft/api/install');

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertFileExists($this->tempDir.'/app/Models/BaseModel.php');

        $content = $this->files->get($this->tempDir.'/app/Models/BaseModel.php');
        $this->assertStringContainsString('class BaseModel', $content);
    }

    public function test_install_skips_if_already_exists(): void
    {
        $this->files->put($this->tempDir.'/app/Models/BaseModel.php', '<?php class BaseModel {}');

        $response = $this->postJson('/_schema-craft/api/install');

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertStringContainsString('already exists', $response->json('message'));
    }

    // ─── Create Preview ─────────────────────────────────────

    public function test_create_preview_returns_files_without_writing(): void
    {
        $response = $this->postJson('/_schema-craft/api/schema/create/preview', [
            'names' => 'Dog',
            'primaryKey' => 'auto',
            'createModel' => true,
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $files = $response->json('files');
        $this->assertCount(2, $files); // schema + model

        // Files should NOT be written to disk
        $this->assertFileDoesNotExist($this->tempDir.'/app/Schemas/DogSchema.php');
        $this->assertFileDoesNotExist($this->tempDir.'/app/Models/Dog.php');

        // Content should be present in response
        $schemaFile = collect($files)->firstWhere('type', 'schema');
        $this->assertNotEmpty($schemaFile['content']);
        $this->assertStringContainsString('DogSchema', $schemaFile['content']);
    }

    public function test_create_preview_with_uuid(): void
    {
        $response = $this->postJson('/_schema-craft/api/schema/create/preview', [
            'names' => 'Cat',
            'primaryKey' => 'uuid',
        ]);

        $response->assertOk();
        $schemaFile = collect($response->json('files'))->firstWhere('type', 'schema');
        $this->assertStringContainsString("ColumnType('uuid')", $schemaFile['content']);
    }

    public function test_create_preview_with_ulid(): void
    {
        $response = $this->postJson('/_schema-craft/api/schema/create/preview', [
            'names' => 'Cat',
            'primaryKey' => 'ulid',
        ]);

        $response->assertOk();
        $schemaFile = collect($response->json('files'))->firstWhere('type', 'schema');
        $this->assertStringContainsString("ColumnType('ulid')", $schemaFile['content']);
    }

    public function test_create_preview_with_soft_deletes(): void
    {
        $response = $this->postJson('/_schema-craft/api/schema/create/preview', [
            'names' => 'Cat',
            'softDeletes' => true,
        ]);

        $response->assertOk();
        $schemaFile = collect($response->json('files'))->firstWhere('type', 'schema');
        $this->assertStringContainsString('SoftDeletesSchema', $schemaFile['content']);
    }

    public function test_create_preview_multiple_names(): void
    {
        $response = $this->postJson('/_schema-craft/api/schema/create/preview', [
            'names' => 'Dog, Cat',
            'createModel' => true,
        ]);

        $response->assertOk();
        $files = $response->json('files');
        $this->assertCount(4, $files); // 2 schemas + 2 models
    }

    public function test_create_preview_without_model(): void
    {
        $response = $this->postJson('/_schema-craft/api/schema/create/preview', [
            'names' => 'Fish',
            'createModel' => false,
        ]);

        $response->assertOk();
        $files = $response->json('files');
        $this->assertCount(1, $files); // schema only
        $this->assertEquals('schema', $files[0]['type']);
    }

    public function test_create_preview_validates_names_required(): void
    {
        $response = $this->postJson('/_schema-craft/api/schema/create/preview', [
            'primaryKey' => 'auto',
        ]);

        $response->assertUnprocessable();
    }

    // ─── Create (Write) ─────────────────────────────────────

    public function test_create_writes_files_to_disk(): void
    {
        $response = $this->postJson('/_schema-craft/api/schema/create', [
            'names' => 'Bird',
            'primaryKey' => 'auto',
            'createModel' => true,
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $this->assertFileExists($this->tempDir.'/app/Schemas/BirdSchema.php');
        $this->assertFileExists($this->tempDir.'/app/Models/Bird.php');

        $schemaContent = $this->files->get($this->tempDir.'/app/Schemas/BirdSchema.php');
        $this->assertStringContainsString('class BirdSchema', $schemaContent);

        $modelContent = $this->files->get($this->tempDir.'/app/Models/Bird.php');
        $this->assertStringContainsString('class Bird', $modelContent);
    }

    public function test_create_auto_installs_base_model(): void
    {
        $this->assertFileDoesNotExist($this->tempDir.'/app/Models/BaseModel.php');

        $this->postJson('/_schema-craft/api/schema/create', [
            'names' => 'Lizard',
            'createModel' => true,
        ]);

        $this->assertFileExists($this->tempDir.'/app/Models/BaseModel.php');
    }

    public function test_create_without_model_does_not_install_base_model(): void
    {
        $this->postJson('/_schema-craft/api/schema/create', [
            'names' => 'Rock',
            'createModel' => false,
        ]);

        $this->assertFileDoesNotExist($this->tempDir.'/app/Models/BaseModel.php');
    }

    // ─── List Tables ────────────────────────────────────────

    public function test_list_tables_returns_json(): void
    {
        // Create some tables in the SQLite testing DB
        $this->app['db']->connection()->getSchemaBuilder()->create('posts', function ($table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });

        $response = $this->getJson('/_schema-craft/api/database/tables');

        $response->assertOk();
        $response->assertJsonStructure(['tables']);

        $tables = collect($response->json('tables'));
        $postTable = $tables->firstWhere('name', 'posts');
        $this->assertNotNull($postTable);
        $this->assertEquals('Post', $postTable['modelName']);
    }

    public function test_list_tables_excludes_laravel_internals(): void
    {
        $schema = $this->app['db']->connection()->getSchemaBuilder();

        $schema->create('users', function ($table) {
            $table->id();
            $table->string('name');
        });
        $schema->create('migrations', function ($table) {
            $table->id();
            $table->string('migration');
        });
        $schema->create('sessions', function ($table) {
            $table->string('id')->primary();
            $table->text('payload');
        });

        $response = $this->getJson('/_schema-craft/api/database/tables');

        $tableNames = collect($response->json('tables'))->pluck('name')->all();
        $this->assertContains('users', $tableNames);
        $this->assertNotContains('migrations', $tableNames);
        $this->assertNotContains('sessions', $tableNames);
    }

    public function test_list_tables_shows_existing_schema_status(): void
    {
        $this->app['db']->connection()->getSchemaBuilder()->create('articles', function ($table) {
            $table->id();
            $table->string('title');
        });

        // Create a schema file
        $this->files->put($this->tempDir.'/app/Schemas/ArticleSchema.php', '<?php class ArticleSchema {}');

        $response = $this->getJson('/_schema-craft/api/database/tables');

        $tables = collect($response->json('tables'));
        $article = $tables->firstWhere('name', 'articles');
        $this->assertNotNull($article);
        $this->assertTrue($article['hasSchema']);
        $this->assertFalse($article['hasModel']);
    }

    // ─── Import Preview ─────────────────────────────────────

    public function test_import_preview_returns_files_without_writing(): void
    {
        $this->app['db']->connection()->getSchemaBuilder()->create('products', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        $response = $this->postJson('/_schema-craft/api/schema/import/preview', [
            'tables' => ['products'],
            'createModel' => true,
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $files = $response->json('files');
        $this->assertNotEmpty($files);

        // Should have schema + model file previews
        $types = collect($files)->pluck('type')->unique()->sort()->values()->all();
        $this->assertEquals(['model', 'schema'], $types);

        // Nothing on disk
        $this->assertFileDoesNotExist($this->tempDir.'/app/Schemas/ProductSchema.php');
    }

    // ─── Import (Write) ─────────────────────────────────────

    public function test_import_writes_files_to_disk(): void
    {
        $this->app['db']->connection()->getSchemaBuilder()->create('categories', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        $response = $this->postJson('/_schema-craft/api/schema/import', [
            'tables' => ['categories'],
            'createModel' => true,
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $this->assertFileExists($this->tempDir.'/app/Schemas/CategorySchema.php');
        $this->assertFileExists($this->tempDir.'/app/Models/Category.php');

        $summary = $response->json('summary');
        $this->assertGreaterThanOrEqual(1, $summary['schemas']);
        $this->assertGreaterThanOrEqual(1, $summary['models']);
    }

    public function test_import_skips_existing_without_force(): void
    {
        $this->app['db']->connection()->getSchemaBuilder()->create('tags', function ($table) {
            $table->id();
            $table->string('label');
        });

        // Pre-create schema file
        $schemaPath = $this->tempDir.'/app/Schemas/TagSchema.php';
        $this->files->put($schemaPath, '<?php // existing');

        $response = $this->postJson('/_schema-craft/api/schema/import', [
            'tables' => ['tags'],
            'createModel' => false,
            'force' => false,
        ]);

        $response->assertOk();

        // File should NOT be overwritten
        $this->assertEquals('<?php // existing', $this->files->get($schemaPath));
        $this->assertGreaterThan(0, $response->json('summary.skipped'));
    }

    public function test_import_overwrites_with_force(): void
    {
        $this->app['db']->connection()->getSchemaBuilder()->create('labels', function ($table) {
            $table->id();
            $table->string('text');
        });

        // Pre-create schema file
        $schemaPath = $this->tempDir.'/app/Schemas/LabelSchema.php';
        $this->files->put($schemaPath, '<?php // existing');

        $response = $this->postJson('/_schema-craft/api/schema/import', [
            'tables' => ['labels'],
            'createModel' => false,
            'force' => true,
        ]);

        $response->assertOk();

        // File should be overwritten with generated content
        $content = $this->files->get($schemaPath);
        $this->assertStringContainsString('class LabelSchema', $content);
    }

    public function test_import_validates_tables_required(): void
    {
        $response = $this->postJson('/_schema-craft/api/schema/import', [
            'createModel' => true,
        ]);

        $response->assertUnprocessable();
    }
}
