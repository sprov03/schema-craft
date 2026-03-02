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

    // ─── List Tables — dbConnection field ───────────────────

    public function test_list_tables_includes_db_connection_field(): void
    {
        $this->app['db']->connection()->getSchemaBuilder()->create('widgets', function ($table) {
            $table->id();
            $table->string('name');
        });

        $response = $this->getJson('/_schema-craft/api/database/tables');

        $response->assertOk();
        $tables = collect($response->json('tables'));
        $widget = $tables->firstWhere('name', 'widgets');
        $this->assertNotNull($widget);
        $this->assertArrayHasKey('dbConnection', $widget);
        $this->assertIsString($widget['dbConnection']);
    }

    // ─── List Tables — All Mode ─────────────────────────────

    public function test_list_tables_all_mode_returns_tables_per_connection(): void
    {
        // Set up multi-connection config
        $this->app['config']->set('schema-craft.db_connections', [
            'default' => [
                'connection' => 'default',
                'prefixes' => ['schema' => '', 'model' => '', 'service' => ''],
                'namespaces' => [
                    'schema' => 'App\\Schemas',
                    'model' => 'App\\Models',
                    'service' => 'App\\Models\\Services',
                    'factory' => 'Database\\Factories',
                    'test' => 'Tests\\Unit',
                ],
            ],
            'prefixed' => [
                'connection' => 'default',
                'prefixes' => ['schema' => 'Pfx', 'model' => 'Pfx', 'service' => 'Pfx'],
                'namespaces' => [
                    'schema' => 'App\\Schemas',
                    'model' => 'App\\Models',
                    'service' => 'App\\Models\\Services',
                    'factory' => 'Database\\Factories',
                    'test' => 'Tests\\Unit',
                ],
            ],
        ]);

        $this->app['db']->connection()->getSchemaBuilder()->create('orders', function ($table) {
            $table->id();
            $table->string('ref');
        });

        $response = $this->getJson('/_schema-craft/api/database/tables?db_connection=all');

        $response->assertOk();
        $tables = collect($response->json('tables'));

        // orders should appear twice — once per connection config
        $orderEntries = $tables->where('name', 'orders');
        $this->assertCount(2, $orderEntries);

        $connections = $orderEntries->pluck('dbConnection')->sort()->values()->all();
        $this->assertEquals(['default', 'prefixed'], $connections);
    }

    public function test_list_tables_all_mode_excludes_laravel_internals(): void
    {
        $this->app['config']->set('schema-craft.db_connections', [
            'default' => [
                'connection' => 'default',
                'prefixes' => ['schema' => '', 'model' => '', 'service' => ''],
                'namespaces' => [
                    'schema' => 'App\\Schemas',
                    'model' => 'App\\Models',
                    'service' => 'App\\Models\\Services',
                    'factory' => 'Database\\Factories',
                    'test' => 'Tests\\Unit',
                ],
            ],
        ]);

        $schema = $this->app['db']->connection()->getSchemaBuilder();
        $schema->create('customers', function ($table) {
            $table->id();
            $table->string('name');
        });
        $schema->create('migrations', function ($table) {
            $table->id();
            $table->string('migration');
        });

        $response = $this->getJson('/_schema-craft/api/database/tables?db_connection=all');

        $response->assertOk();
        $tableNames = collect($response->json('tables'))->pluck('name')->all();
        $this->assertContains('customers', $tableNames);
        $this->assertNotContains('migrations', $tableNames);
    }

    public function test_list_tables_all_mode_checks_badges_per_connection(): void
    {
        $this->app['config']->set('schema-craft.db_connections', [
            'default' => [
                'connection' => 'default',
                'prefixes' => ['schema' => '', 'model' => '', 'service' => ''],
                'namespaces' => [
                    'schema' => 'App\\Schemas',
                    'model' => 'App\\Models',
                    'service' => 'App\\Models\\Services',
                    'factory' => 'Database\\Factories',
                    'test' => 'Tests\\Unit',
                ],
            ],
            'prefixed' => [
                'connection' => 'default',
                'prefixes' => ['schema' => 'Pfx', 'model' => 'Pfx', 'service' => 'Pfx'],
                'namespaces' => [
                    'schema' => 'App\\Schemas',
                    'model' => 'App\\Models',
                    'service' => 'App\\Models\\Services',
                    'factory' => 'Database\\Factories',
                    'test' => 'Tests\\Unit',
                ],
            ],
        ]);

        $this->app['db']->connection()->getSchemaBuilder()->create('invoices', function ($table) {
            $table->id();
            $table->decimal('amount');
        });

        // Only create schema for default connection (InvoiceSchema), not prefixed (PfxInvoiceSchema)
        $this->files->put($this->tempDir.'/app/Schemas/InvoiceSchema.php', '<?php class InvoiceSchema {}');

        $response = $this->getJson('/_schema-craft/api/database/tables?db_connection=all');

        $tables = collect($response->json('tables'));
        $defaultInvoice = $tables->first(function ($t) {
            return $t['name'] === 'invoices' && $t['dbConnection'] === 'default';
        });
        $prefixedInvoice = $tables->first(function ($t) {
            return $t['name'] === 'invoices' && $t['dbConnection'] === 'prefixed';
        });

        $this->assertTrue($defaultInvoice['hasSchema']);
        $this->assertFalse($prefixedInvoice['hasSchema']);
    }

    // ─── Pivot Table Import ────────────────────────────────

    public function test_import_always_generates_pivot_table_files(): void
    {
        $schema = $this->app['db']->connection()->getSchemaBuilder();

        $schema->create('cats', function ($table) {
            $table->id();
            $table->string('name');
        });
        $schema->create('toys', function ($table) {
            $table->id();
            $table->string('name');
        });
        $schema->create('cat_toy', function ($table) {
            $table->id();
            $table->foreignId('cat_id')->constrained('cats');
            $table->foreignId('toy_id')->constrained('toys');
        });

        $response = $this->postJson('/_schema-craft/api/schema/import', [
            'tables' => ['cat_toy'],
            'createModel' => true,
        ]);

        $response->assertOk();
        $summary = $response->json('summary');
        $this->assertGreaterThanOrEqual(1, $summary['schemas']);
        $this->assertGreaterThanOrEqual(1, $summary['models']);
        $this->assertEquals(1, $summary['pivots']);

        // Files written for pivot table
        $this->assertFileExists($this->tempDir.'/app/Schemas/CatToySchema.php');
        $this->assertFileExists($this->tempDir.'/app/Models/CatToy.php');

        // Pivot model extends Pivot, not BaseModel
        $modelContent = $this->files->get($this->tempDir.'/app/Models/CatToy.php');
        $this->assertStringContainsString('extends Pivot', $modelContent);
    }

    public function test_import_generates_pivot_model_extending_pivot_class(): void
    {
        $schema = $this->app['db']->connection()->getSchemaBuilder();

        $schema->create('books', function ($table) {
            $table->id();
            $table->string('title');
        });
        $schema->create('authors', function ($table) {
            $table->id();
            $table->string('name');
        });
        $schema->create('author_book', function ($table) {
            $table->id();
            $table->foreignId('author_id')->constrained('authors');
            $table->foreignId('book_id')->constrained('books');
        });

        $response = $this->postJson('/_schema-craft/api/schema/import', [
            'tables' => ['author_book'],
            'createModel' => true,
        ]);

        $response->assertOk();
        $summary = $response->json('summary');
        $this->assertGreaterThanOrEqual(1, $summary['schemas']);
        $this->assertGreaterThanOrEqual(1, $summary['models']);
        $this->assertEquals(1, $summary['pivots']);

        // Files SHOULD be written for pivot table
        $this->assertFileExists($this->tempDir.'/app/Schemas/AuthorBookSchema.php');
        $this->assertFileExists($this->tempDir.'/app/Models/AuthorBook.php');

        // Pivot model should extend Pivot, not BaseModel
        $modelContent = $this->files->get($this->tempDir.'/app/Models/AuthorBook.php');
        $this->assertStringContainsString('extends Pivot', $modelContent);
        $this->assertStringNotContainsString('extends BaseModel', $modelContent);
        $this->assertStringNotContainsString('$schema', $modelContent);
    }

    public function test_import_with_pivots_emits_using_pivot_on_related_tables(): void
    {
        $schema = $this->app['db']->connection()->getSchemaBuilder();

        $schema->create('albums', function ($table) {
            $table->id();
            $table->string('title');
        });
        $schema->create('genres', function ($table) {
            $table->id();
            $table->string('name');
        });
        $schema->create('album_genre', function ($table) {
            $table->id();
            $table->foreignId('album_id')->constrained('albums');
            $table->foreignId('genre_id')->constrained('genres');
        });

        $response = $this->postJson('/_schema-craft/api/schema/import', [
            'tables' => ['albums', 'genres', 'album_genre'],
            'createModel' => true,
        ]);

        $response->assertOk();

        // Pivot model extends Pivot
        $pivotModel = $this->files->get($this->tempDir.'/app/Models/AlbumGenre.php');
        $this->assertStringContainsString('extends Pivot', $pivotModel);

        // Related table schemas should have UsingPivot attribute
        $albumSchema = $this->files->get($this->tempDir.'/app/Schemas/AlbumSchema.php');
        $this->assertStringContainsString('#[BelongsToMany(Genre::class)]', $albumSchema);
        $this->assertStringContainsString('#[UsingPivot(AlbumGenre::class)]', $albumSchema);

        $genreSchema = $this->files->get($this->tempDir.'/app/Schemas/GenreSchema.php');
        $this->assertStringContainsString('#[BelongsToMany(Album::class)]', $genreSchema);
        $this->assertStringContainsString('#[UsingPivot(AlbumGenre::class)]', $genreSchema);
    }

    public function test_import_preview_with_import_pivots_shows_pivot_files(): void
    {
        $schema = $this->app['db']->connection()->getSchemaBuilder();

        $schema->create('songs', function ($table) {
            $table->id();
            $table->string('title');
        });
        $schema->create('playlists', function ($table) {
            $table->id();
            $table->string('name');
        });
        $schema->create('playlist_song', function ($table) {
            $table->id();
            $table->foreignId('playlist_id')->constrained('playlists');
            $table->foreignId('song_id')->constrained('songs');
        });

        $response = $this->postJson('/_schema-craft/api/schema/import/preview', [
            'tables' => ['playlist_song'],
            'createModel' => true,
        ]);

        $response->assertOk();
        $files = $response->json('files');
        $this->assertNotEmpty($files);

        $types = collect($files)->pluck('type')->unique()->sort()->values()->all();
        $this->assertEquals(['model', 'schema'], $types);
    }

    // ─── Pivot with Extra Columns ──────────────────────────

    public function test_import_pivot_with_extra_columns_emits_pivot_columns_on_related(): void
    {
        $schema = $this->app['db']->connection()->getSchemaBuilder();

        $schema->create('students', function ($table) {
            $table->id();
            $table->string('name');
        });
        $schema->create('courses', function ($table) {
            $table->id();
            $table->string('title');
        });
        $schema->create('course_student', function ($table) {
            $table->id();
            $table->foreignId('course_id')->constrained('courses');
            $table->foreignId('student_id')->constrained('students');
            $table->integer('grade');
            $table->string('semester');
        });

        $response = $this->postJson('/_schema-craft/api/schema/import', [
            'tables' => ['students', 'courses', 'course_student'],
            'createModel' => true,
        ]);

        $response->assertOk();

        // Pivot model should still extend Pivot
        $this->assertFileExists($this->tempDir.'/app/Models/CourseStudent.php');
        $pivotModel = $this->files->get($this->tempDir.'/app/Models/CourseStudent.php');
        $this->assertStringContainsString('extends Pivot', $pivotModel);

        // Pivot schema should include extra columns
        $this->assertFileExists($this->tempDir.'/app/Schemas/CourseStudentSchema.php');
        $pivotSchema = $this->files->get($this->tempDir.'/app/Schemas/CourseStudentSchema.php');
        $this->assertStringContainsString('$grade', $pivotSchema);
        $this->assertStringContainsString('$semester', $pivotSchema);

        // Related schemas should have PivotColumns attribute
        $studentSchema = $this->files->get($this->tempDir.'/app/Schemas/StudentSchema.php');
        $this->assertStringContainsString('#[BelongsToMany(Course::class)]', $studentSchema);
        $this->assertStringContainsString('#[UsingPivot(CourseStudent::class)]', $studentSchema);
        $this->assertStringContainsString('#[PivotColumns(', $studentSchema);
        $this->assertStringContainsString("'grade' => 'integer'", $studentSchema);
        $this->assertStringContainsString("'semester' => 'string'", $studentSchema);

        $courseSchema = $this->files->get($this->tempDir.'/app/Schemas/CourseSchema.php');
        $this->assertStringContainsString('#[BelongsToMany(Student::class)]', $courseSchema);
        $this->assertStringContainsString('#[PivotColumns(', $courseSchema);
    }

    public function test_import_pivot_with_extra_columns_generates_pivot_and_related_schemas(): void
    {
        $schema = $this->app['db']->connection()->getSchemaBuilder();

        $schema->create('projects', function ($table) {
            $table->id();
            $table->string('name');
        });
        $schema->create('developers', function ($table) {
            $table->id();
            $table->string('name');
        });
        $schema->create('developer_project', function ($table) {
            $table->id();
            $table->foreignId('developer_id')->constrained('developers');
            $table->foreignId('project_id')->constrained('projects');
            $table->string('role');
        });

        $response = $this->postJson('/_schema-craft/api/schema/import', [
            'tables' => ['projects', 'developers', 'developer_project'],
            'createModel' => true,
        ]);

        $response->assertOk();
        $summary = $response->json('summary');
        $this->assertEquals(1, $summary['pivots']);

        // Pivot files created
        $this->assertFileExists($this->tempDir.'/app/Schemas/DeveloperProjectSchema.php');
        $this->assertFileExists($this->tempDir.'/app/Models/DeveloperProject.php');

        // Pivot model extends Pivot
        $pivotModel = $this->files->get($this->tempDir.'/app/Models/DeveloperProject.php');
        $this->assertStringContainsString('extends Pivot', $pivotModel);

        // Related schemas should have PivotColumns + UsingPivot
        $projectSchema = $this->files->get($this->tempDir.'/app/Schemas/ProjectSchema.php');
        $this->assertStringContainsString('#[BelongsToMany(Developer::class)]', $projectSchema);
        $this->assertStringContainsString('#[UsingPivot(DeveloperProject::class)]', $projectSchema);
        $this->assertStringContainsString('#[PivotColumns(', $projectSchema);
        $this->assertStringContainsString("'role' => 'string'", $projectSchema);
    }

    // ─── Import Preview with Extras ─────────────────────────

    public function test_import_preview_includes_factory_and_test_files(): void
    {
        $schema = $this->app['db']->connection()->getSchemaBuilder();

        $schema->create('widgets', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        $response = $this->postJson('/_schema-craft/api/schema/import/preview', [
            'tables' => ['widgets'],
            'createModel' => true,
            'createFactory' => true,
            'createModelTest' => true,
        ]);

        $response->assertOk();
        $files = $response->json('files');
        $types = collect($files)->pluck('type')->unique()->sort()->values()->all();
        $this->assertEquals(['factory', 'model', 'model_test', 'schema'], $types);

        // Nothing on disk (preview only)
        $this->assertFileDoesNotExist($this->tempDir.'/app/Schemas/WidgetSchema.php');
        $this->assertFileDoesNotExist($this->tempDir.'/database/factories/WidgetFactory.php');
        $this->assertFileDoesNotExist($this->tempDir.'/tests/Unit/WidgetModelTest.php');

        // Verify factory content references the model
        $factoryFile = collect($files)->firstWhere('type', 'factory');
        $this->assertNotEmpty($factoryFile['content']);
        $this->assertStringContainsString('WidgetFactory', $factoryFile['content']);

        // Verify model test content references the factory
        $testFile = collect($files)->firstWhere('type', 'model_test');
        $this->assertNotEmpty($testFile['content']);
        $this->assertStringContainsString('WidgetModelTest', $testFile['content']);
    }

    public function test_import_preview_includes_service_file(): void
    {
        $schema = $this->app['db']->connection()->getSchemaBuilder();

        $schema->create('gadgets', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        $response = $this->postJson('/_schema-craft/api/schema/import/preview', [
            'tables' => ['gadgets'],
            'createModel' => true,
            'createService' => true,
        ]);

        $response->assertOk();
        $files = $response->json('files');
        $types = collect($files)->pluck('type')->unique()->sort()->values()->all();
        $this->assertEquals(['model', 'schema', 'service'], $types);

        // Verify service content
        $serviceFile = collect($files)->firstWhere('type', 'service');
        $this->assertNotEmpty($serviceFile['content']);
        $this->assertStringContainsString('GadgetService', $serviceFile['content']);
    }

    public function test_import_preview_all_five_files(): void
    {
        $schema = $this->app['db']->connection()->getSchemaBuilder();

        $schema->create('gizmos', function ($table) {
            $table->id();
            $table->string('label');
            $table->timestamps();
        });

        $response = $this->postJson('/_schema-craft/api/schema/import/preview', [
            'tables' => ['gizmos'],
            'createModel' => true,
            'createFactory' => true,
            'createModelTest' => true,
            'createService' => true,
        ]);

        $response->assertOk();
        $files = $response->json('files');
        $types = collect($files)->pluck('type')->unique()->sort()->values()->all();
        $this->assertEquals(['factory', 'model', 'model_test', 'schema', 'service'], $types);

        // Nothing on disk
        $this->assertFileDoesNotExist($this->tempDir.'/app/Schemas/GizmoSchema.php');
        $this->assertFileDoesNotExist($this->tempDir.'/app/Models/Gizmo.php');
        $this->assertFileDoesNotExist($this->tempDir.'/database/factories/GizmoFactory.php');
        $this->assertFileDoesNotExist($this->tempDir.'/tests/Unit/GizmoModelTest.php');
    }

    public function test_import_preview_factory_includes_belongs_to_relationships(): void
    {
        $schema = $this->app['db']->connection()->getSchemaBuilder();

        $schema->create('warehouses', function ($table) {
            $table->id();
            $table->string('name');
        });
        $schema->create('items', function ($table) {
            $table->id();
            $table->string('name');
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->timestamps();
        });

        $response = $this->postJson('/_schema-craft/api/schema/import/preview', [
            'tables' => ['items'],
            'createModel' => true,
            'createFactory' => true,
            'createModelTest' => true,
        ]);

        $response->assertOk();
        $files = $response->json('files');

        // Factory should reference the BelongsTo relationship
        $factoryFile = collect($files)->firstWhere('type', 'factory');
        $this->assertStringContainsString('warehouse', $factoryFile['content']);
        $this->assertStringContainsString('WarehouseFactory', $factoryFile['content']);

        // Model test should test the BelongsTo relationship
        $testFile = collect($files)->firstWhere('type', 'model_test');
        $this->assertStringContainsString('warehouse', $testFile['content']);
    }

    // ─── Import (Write) with Extras ─────────────────────────

    public function test_import_writes_all_five_files_to_disk(): void
    {
        $schema = $this->app['db']->connection()->getSchemaBuilder();

        $schema->create('sprockets', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        $response = $this->postJson('/_schema-craft/api/schema/import', [
            'tables' => ['sprockets'],
            'createModel' => true,
            'createFactory' => true,
            'createModelTest' => true,
            'createService' => true,
        ]);

        $response->assertOk();
        $summary = $response->json('summary');
        $this->assertEquals(1, $summary['schemas']);
        $this->assertEquals(1, $summary['models']);
        $this->assertEquals(1, $summary['factories']);
        $this->assertEquals(1, $summary['tests']);
        $this->assertEquals(1, $summary['services']);

        $this->assertFileExists($this->tempDir.'/app/Schemas/SprocketSchema.php');
        $this->assertFileExists($this->tempDir.'/app/Models/Sprocket.php');
        $this->assertFileExists($this->tempDir.'/database/factories/SprocketFactory.php');
        $this->assertFileExists($this->tempDir.'/tests/Unit/SprocketModelTest.php');
        $this->assertFileExists($this->tempDir.'/app/Models/Services/SprocketService.php');
    }

    public function test_import_extras_skips_existing_without_force(): void
    {
        $schema = $this->app['db']->connection()->getSchemaBuilder();

        $schema->create('bolts', function ($table) {
            $table->id();
            $table->string('size');
            $table->timestamps();
        });

        // Pre-create factory file
        $factoryDir = $this->tempDir.'/database/factories';
        $this->files->makeDirectory($factoryDir, 0755, true);
        $this->files->put($factoryDir.'/BoltFactory.php', '<?php // existing factory');

        $response = $this->postJson('/_schema-craft/api/schema/import', [
            'tables' => ['bolts'],
            'createModel' => true,
            'createFactory' => true,
            'force' => false,
        ]);

        $response->assertOk();

        // Factory should NOT be overwritten
        $this->assertEquals('<?php // existing factory', $this->files->get($factoryDir.'/BoltFactory.php'));
        $this->assertGreaterThan(0, $response->json('summary.skipped'));
    }

    public function test_import_extras_overwrites_with_force(): void
    {
        $schema = $this->app['db']->connection()->getSchemaBuilder();

        $schema->create('screws', function ($table) {
            $table->id();
            $table->string('size');
            $table->timestamps();
        });

        // Pre-create factory file
        $factoryDir = $this->tempDir.'/database/factories';
        $this->files->makeDirectory($factoryDir, 0755, true);
        $this->files->put($factoryDir.'/ScrewFactory.php', '<?php // existing factory');

        $response = $this->postJson('/_schema-craft/api/schema/import', [
            'tables' => ['screws'],
            'createModel' => true,
            'createFactory' => true,
            'force' => true,
        ]);

        $response->assertOk();

        // Factory SHOULD be overwritten
        $content = $this->files->get($factoryDir.'/ScrewFactory.php');
        $this->assertStringContainsString('class ScrewFactory', $content);
    }

    // ─── Generate Extras (standalone endpoint) ─────────────

    public function test_generate_extras_includes_pivot_models(): void
    {
        $schema = $this->app['db']->connection()->getSchemaBuilder();

        $schema->create('galaxies', function ($table) {
            $table->id();
            $table->string('name');
        });
        $schema->create('nebulas', function ($table) {
            $table->id();
            $table->string('title');
        });
        $schema->create('galaxy_nebula', function ($table) {
            $table->id();
            $table->foreignId('galaxy_id')->constrained('galaxies');
            $table->foreignId('nebula_id')->constrained('nebulas');
        });

        // First import all three (pivots are always imported)
        $importResponse = $this->postJson('/_schema-craft/api/schema/import', [
            'tables' => ['galaxies', 'nebulas', 'galaxy_nebula'],
            'createModel' => true,
        ]);
        $importResponse->assertOk();

        // Verify the pivot model extends Pivot
        $pivotModel = $this->files->get($this->tempDir.'/app/Models/GalaxyNebula.php');
        $this->assertStringContainsString('extends Pivot', $pivotModel);

        // Require generated schema files so class_exists works in tests
        require_once $this->tempDir.'/app/Schemas/GalaxySchema.php';
        require_once $this->tempDir.'/app/Schemas/NebulaSchema.php';
        require_once $this->tempDir.'/app/Schemas/GalaxyNebulaSchema.php';

        // Generate extras for all three — pivot models get Factory/Test too
        $response = $this->postJson('/_schema-craft/api/schema/import/extras', [
            'tables' => ['galaxies', 'nebulas', 'galaxy_nebula'],
            'createFactory' => true,
            'createModelTest' => true,
        ]);

        $response->assertOk();
        $summary = $response->json('summary');

        // All 3 tables get factories and tests (including pivot)
        $this->assertEquals(3, $summary['factories']);
        $this->assertEquals(3, $summary['tests']);

        // Factory and test exist for regular models
        $this->assertFileExists($this->tempDir.'/database/factories/GalaxyFactory.php');
        $this->assertFileExists($this->tempDir.'/database/factories/NebulaFactory.php');

        // Factory and test also exist for pivot model
        $this->assertFileExists($this->tempDir.'/database/factories/GalaxyNebulaFactory.php');
        $this->assertFileExists($this->tempDir.'/tests/Unit/GalaxyNebulaModelTest.php');
    }

    // ─── Schema Detail (Editor) ─────────────────────────────

    public function test_schema_detail_returns_404_for_missing_schema(): void
    {
        $response = $this->getJson('/_schema-craft/api/schema/detail?schema=App\\Schemas\\NonExistentSchema');

        $response->assertNotFound();
        $response->assertJson(['success' => false]);
    }

    public function test_schema_detail_returns_404_without_schema_param(): void
    {
        $response = $this->getJson('/_schema-craft/api/schema/detail');

        $response->assertNotFound();
        $response->assertJson(['success' => false]);
    }

    public function test_schema_detail_returns_column_data(): void
    {
        // Create a real schema file via import so SchemaScanner can load it
        $schema = $this->app['db']->connection()->getSchemaBuilder();
        $schema->create('planets', function ($table) {
            $table->id();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->integer('population')->unsigned()->default(0);
            $table->boolean('habitable')->default(false);
            $table->timestamps();
        });

        $this->postJson('/_schema-craft/api/schema/import', [
            'tables' => ['planets'],
            'createModel' => true,
        ])->assertOk();

        require_once $this->tempDir.'/app/Schemas/PlanetSchema.php';

        $response = $this->getJson('/_schema-craft/api/schema/detail?schema=App\\Schemas\\PlanetSchema');

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'schemaName' => 'PlanetSchema',
            'hasTimestamps' => true,
        ]);

        $columns = $response->json('columns');
        $columnNames = array_column($columns, 'name');

        $this->assertContains('id', $columnNames);
        $this->assertContains('name', $columnNames);
        $this->assertContains('description', $columnNames);
        $this->assertContains('population', $columnNames);
        $this->assertContains('habitable', $columnNames);

        // Check specific column properties
        $nameCol = collect($columns)->firstWhere('name', 'name');
        $this->assertEquals('string', $nameCol['phpType']);
        $this->assertFalse($nameCol['nullable']);

        $descCol = collect($columns)->firstWhere('name', 'description');
        $this->assertTrue($descCol['nullable']);

        $popCol = collect($columns)->firstWhere('name', 'population');
        $this->assertTrue($popCol['hasDefault']);
    }

    public function test_schema_detail_returns_relationship_data(): void
    {
        $schema = $this->app['db']->connection()->getSchemaBuilder();
        $schema->create('solar_systems', function ($table) {
            $table->id();
            $table->string('name');
        });
        $schema->create('moons', function ($table) {
            $table->id();
            $table->string('name');
            $table->foreignId('solar_system_id')->constrained('solar_systems');
            $table->timestamps();
        });

        $this->postJson('/_schema-craft/api/schema/import', [
            'tables' => ['solar_systems', 'moons'],
            'createModel' => true,
        ])->assertOk();

        require_once $this->tempDir.'/app/Schemas/MoonSchema.php';

        $response = $this->getJson('/_schema-craft/api/schema/detail?schema=App\\Schemas\\MoonSchema');

        $response->assertOk();

        $relationships = $response->json('relationships');
        $this->assertNotEmpty($relationships);

        $belongsTo = collect($relationships)->firstWhere('type', 'belongsTo');
        $this->assertNotNull($belongsTo);
        $this->assertEquals('App\\Models\\SolarSystem', $belongsTo['relatedModel']);
    }

    public function test_schema_detail_returns_timestamps_and_soft_deletes(): void
    {
        // Write a schema file directly with SoftDeletes trait
        $content = <<<'PHP'
<?php

namespace App\Schemas;

use App\Models\BaseModel;
use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Schema;
use SchemaCraft\Traits\SoftDeletesSchema;
use SchemaCraft\Traits\TimestampsSchema;

class ArchiveSchema extends Schema
{
    use SoftDeletesSchema;
    use TimestampsSchema;

    #[Primary]
    #[AutoIncrement]
    public int $id;

    public string $title;
}
PHP;

        $this->files->put($this->tempDir.'/app/Schemas/ArchiveSchema.php', $content);
        require_once $this->tempDir.'/app/Schemas/ArchiveSchema.php';

        $response = $this->getJson('/_schema-craft/api/schema/detail?schema=App\\Schemas\\ArchiveSchema');

        $response->assertOk();
        $response->assertJson([
            'hasTimestamps' => true,
            'hasSoftDeletes' => true,
        ]);
    }

    // ─── Schema Save Preview ─────────────────────────────────

    public function test_schema_save_preview_returns_content_without_writing(): void
    {
        $response = $this->postJson('/_schema-craft/api/schema/save/preview', [
            'schemaName' => 'VehicleSchema',
            'schemaNamespace' => 'App\\Schemas',
            'modelNamespace' => 'App\\Models',
            'hasTimestamps' => true,
            'hasSoftDeletes' => false,
            'columns' => [
                [
                    'name' => 'id',
                    'phpType' => 'int',
                    'primary' => true,
                    'autoIncrement' => true,
                    'nullable' => false,
                    'unsigned' => false,
                    'unique' => false,
                    'index' => false,
                    'fillable' => false,
                    'hidden' => false,
                    'hasDefault' => false,
                ],
                [
                    'name' => 'make',
                    'phpType' => 'string',
                    'nullable' => false,
                    'primary' => false,
                    'autoIncrement' => false,
                    'unsigned' => false,
                    'unique' => false,
                    'index' => false,
                    'fillable' => true,
                    'hidden' => false,
                    'hasDefault' => false,
                ],
            ],
            'relationships' => [],
            'compositeIndexes' => [],
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $files = $response->json('files');
        $this->assertCount(1, $files);
        $this->assertEquals('schema', $files[0]['type']);
        $this->assertStringContainsString('VehicleSchema', $files[0]['content']);
        $this->assertStringContainsString('#[Primary]', $files[0]['content']);
        $this->assertStringContainsString('#[Fillable]', $files[0]['content']);
        $this->assertStringContainsString('public string $make', $files[0]['content']);

        // Nothing on disk
        $this->assertFileDoesNotExist($this->tempDir.'/app/Schemas/VehicleSchema.php');
    }

    public function test_schema_save_preview_includes_model_when_requested(): void
    {
        $response = $this->postJson('/_schema-craft/api/schema/save/preview', [
            'schemaName' => 'BikeSchema',
            'schemaNamespace' => 'App\\Schemas',
            'modelNamespace' => 'App\\Models',
            'hasTimestamps' => true,
            'columns' => [
                [
                    'name' => 'id',
                    'phpType' => 'int',
                    'primary' => true,
                    'autoIncrement' => true,
                    'nullable' => false,
                    'unsigned' => false,
                    'unique' => false,
                    'index' => false,
                    'fillable' => false,
                    'hidden' => false,
                    'hasDefault' => false,
                ],
            ],
            'relationships' => [],
            'compositeIndexes' => [],
            'createModel' => true,
        ]);

        $response->assertOk();

        $files = $response->json('files');
        $this->assertCount(2, $files);

        $types = collect($files)->pluck('type')->all();
        $this->assertContains('schema', $types);
        $this->assertContains('model', $types);

        $modelFile = collect($files)->firstWhere('type', 'model');
        $this->assertStringContainsString('class Bike', $modelFile['content']);
        $this->assertStringContainsString('BikeSchema', $modelFile['content']);
    }

    public function test_schema_save_preview_validates_required_fields(): void
    {
        $response = $this->postJson('/_schema-craft/api/schema/save/preview', [
            // Missing required fields
            'hasTimestamps' => true,
        ]);

        $response->assertUnprocessable();
    }

    public function test_schema_save_preview_with_relationships(): void
    {
        $response = $this->postJson('/_schema-craft/api/schema/save/preview', [
            'schemaName' => 'CommentSchema',
            'schemaNamespace' => 'App\\Schemas',
            'modelNamespace' => 'App\\Models',
            'hasTimestamps' => true,
            'columns' => [
                [
                    'name' => 'id',
                    'phpType' => 'int',
                    'primary' => true,
                    'autoIncrement' => true,
                    'nullable' => false,
                    'unsigned' => false,
                    'unique' => false,
                    'index' => false,
                    'fillable' => false,
                    'hidden' => false,
                    'hasDefault' => false,
                ],
                [
                    'name' => 'body',
                    'phpType' => 'string',
                    'typeOverride' => 'Text',
                    'nullable' => false,
                    'primary' => false,
                    'autoIncrement' => false,
                    'unsigned' => false,
                    'unique' => false,
                    'index' => false,
                    'fillable' => true,
                    'hidden' => false,
                    'hasDefault' => false,
                ],
            ],
            'relationships' => [
                [
                    'name' => 'post',
                    'type' => 'belongsTo',
                    'relatedModel' => 'App\\Models\\Post',
                    'nullable' => false,
                    'onDelete' => 'cascade',
                    'index' => true,
                ],
            ],
            'compositeIndexes' => [],
        ]);

        $response->assertOk();

        $schemaFile = collect($response->json('files'))->firstWhere('type', 'schema');
        $this->assertStringContainsString('#[BelongsTo(Post::class)]', $schemaFile['content']);
        $this->assertStringContainsString("#[OnDelete('cascade')]", $schemaFile['content']);
        $this->assertStringContainsString('#[Text]', $schemaFile['content']);
        $this->assertStringContainsString('#[Index]', $schemaFile['content']);
    }

    // ─── Schema Save (Write) ─────────────────────────────────

    public function test_schema_save_creates_new_file(): void
    {
        $response = $this->postJson('/_schema-craft/api/schema/save', [
            'schemaName' => 'RocketSchema',
            'schemaNamespace' => 'App\\Schemas',
            'modelNamespace' => 'App\\Models',
            'hasTimestamps' => true,
            'hasSoftDeletes' => false,
            'columns' => [
                [
                    'name' => 'id',
                    'phpType' => 'int',
                    'primary' => true,
                    'autoIncrement' => true,
                    'nullable' => false,
                    'unsigned' => false,
                    'unique' => false,
                    'index' => false,
                    'fillable' => false,
                    'hidden' => false,
                    'hasDefault' => false,
                ],
                [
                    'name' => 'name',
                    'phpType' => 'string',
                    'nullable' => false,
                    'primary' => false,
                    'autoIncrement' => false,
                    'unsigned' => false,
                    'unique' => true,
                    'index' => false,
                    'fillable' => true,
                    'hidden' => false,
                    'hasDefault' => false,
                ],
            ],
            'relationships' => [],
            'compositeIndexes' => [],
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $schemaPath = $this->tempDir.'/app/Schemas/RocketSchema.php';
        $this->assertFileExists($schemaPath);

        $content = $this->files->get($schemaPath);
        $this->assertStringContainsString('class RocketSchema extends Schema', $content);
        $this->assertStringContainsString('#[Primary]', $content);
        $this->assertStringContainsString('#[Unique]', $content);
        $this->assertStringContainsString('#[Fillable]', $content);
        $this->assertStringContainsString('public string $name', $content);
    }

    public function test_schema_save_overwrites_existing_file(): void
    {
        // Write an initial schema file
        $schemaPath = $this->tempDir.'/app/Schemas/ShuttleSchema.php';
        $this->files->put($schemaPath, '<?php // old content');

        $response = $this->postJson('/_schema-craft/api/schema/save', [
            'schemaName' => 'ShuttleSchema',
            'schemaNamespace' => 'App\\Schemas',
            'modelNamespace' => 'App\\Models',
            'hasTimestamps' => true,
            'columns' => [
                [
                    'name' => 'id',
                    'phpType' => 'int',
                    'primary' => true,
                    'autoIncrement' => true,
                    'nullable' => false,
                    'unsigned' => false,
                    'unique' => false,
                    'index' => false,
                    'fillable' => false,
                    'hidden' => false,
                    'hasDefault' => false,
                ],
            ],
            'relationships' => [],
            'compositeIndexes' => [],
        ]);

        $response->assertOk();

        $content = $this->files->get($schemaPath);
        $this->assertStringContainsString('class ShuttleSchema extends Schema', $content);
        $this->assertStringNotContainsString('old content', $content);
    }

    public function test_schema_save_with_model_creates_both_files(): void
    {
        $response = $this->postJson('/_schema-craft/api/schema/save', [
            'schemaName' => 'SatelliteSchema',
            'schemaNamespace' => 'App\\Schemas',
            'modelNamespace' => 'App\\Models',
            'hasTimestamps' => true,
            'columns' => [
                [
                    'name' => 'id',
                    'phpType' => 'int',
                    'primary' => true,
                    'autoIncrement' => true,
                    'nullable' => false,
                    'unsigned' => false,
                    'unique' => false,
                    'index' => false,
                    'fillable' => false,
                    'hidden' => false,
                    'hasDefault' => false,
                ],
            ],
            'relationships' => [],
            'compositeIndexes' => [],
            'createModel' => true,
        ]);

        $response->assertOk();
        $this->assertFileExists($this->tempDir.'/app/Schemas/SatelliteSchema.php');
        $this->assertFileExists($this->tempDir.'/app/Models/Satellite.php');

        $modelContent = $this->files->get($this->tempDir.'/app/Models/Satellite.php');
        $this->assertStringContainsString('class Satellite', $modelContent);
        $this->assertStringContainsString('SatelliteSchema', $modelContent);
    }

    public function test_schema_save_validates_required_fields(): void
    {
        $response = $this->postJson('/_schema-craft/api/schema/save', [
            // Missing schemaName, schemaNamespace, modelNamespace
            'hasTimestamps' => true,
        ]);

        $response->assertUnprocessable();
    }

    public function test_schema_save_with_custom_table_name_and_connection(): void
    {
        $response = $this->postJson('/_schema-craft/api/schema/save', [
            'schemaName' => 'ProbeSchema',
            'schemaNamespace' => 'App\\Schemas',
            'modelNamespace' => 'App\\Models',
            'tableName' => 'deep_space_probes',
            'connection' => 'mysql',
            'hasTimestamps' => false,
            'hasSoftDeletes' => false,
            'columns' => [
                [
                    'name' => 'id',
                    'phpType' => 'int',
                    'primary' => true,
                    'autoIncrement' => true,
                    'nullable' => false,
                    'unsigned' => false,
                    'unique' => false,
                    'index' => false,
                    'fillable' => false,
                    'hidden' => false,
                    'hasDefault' => false,
                ],
            ],
            'relationships' => [],
            'compositeIndexes' => [],
        ]);

        $response->assertOk();

        $content = $this->files->get($this->tempDir.'/app/Schemas/ProbeSchema.php');
        $this->assertStringContainsString("'deep_space_probes'", $content);
        $this->assertStringContainsString("'mysql'", $content);
        // Should NOT have TimestampsSchema since timestamps disabled
        $this->assertStringNotContainsString('TimestampsSchema', $content);
    }

    public function test_schema_save_with_composite_indexes(): void
    {
        $response = $this->postJson('/_schema-craft/api/schema/save', [
            'schemaName' => 'FlightSchema',
            'schemaNamespace' => 'App\\Schemas',
            'modelNamespace' => 'App\\Models',
            'hasTimestamps' => true,
            'columns' => [
                [
                    'name' => 'id',
                    'phpType' => 'int',
                    'primary' => true,
                    'autoIncrement' => true,
                    'nullable' => false,
                    'unsigned' => false,
                    'unique' => false,
                    'index' => false,
                    'fillable' => false,
                    'hidden' => false,
                    'hasDefault' => false,
                ],
                [
                    'name' => 'origin',
                    'phpType' => 'string',
                    'nullable' => false,
                    'primary' => false,
                    'autoIncrement' => false,
                    'unsigned' => false,
                    'unique' => false,
                    'index' => false,
                    'fillable' => true,
                    'hidden' => false,
                    'hasDefault' => false,
                ],
                [
                    'name' => 'destination',
                    'phpType' => 'string',
                    'nullable' => false,
                    'primary' => false,
                    'autoIncrement' => false,
                    'unsigned' => false,
                    'unique' => false,
                    'index' => false,
                    'fillable' => true,
                    'hidden' => false,
                    'hasDefault' => false,
                ],
            ],
            'relationships' => [],
            'compositeIndexes' => [['origin', 'destination']],
        ]);

        $response->assertOk();

        $content = $this->files->get($this->tempDir.'/app/Schemas/FlightSchema.php');
        $this->assertStringContainsString("'origin'", $content);
        $this->assertStringContainsString("'destination'", $content);
        $this->assertStringContainsString('#[Index(', $content);
    }

    public function test_schema_save_generates_correct_imports(): void
    {
        $response = $this->postJson('/_schema-craft/api/schema/save', [
            'schemaName' => 'SpaceStationSchema',
            'schemaNamespace' => 'App\\Schemas',
            'modelNamespace' => 'App\\Models',
            'hasTimestamps' => true,
            'hasSoftDeletes' => true,
            'columns' => [
                [
                    'name' => 'id',
                    'phpType' => 'int',
                    'primary' => true,
                    'autoIncrement' => true,
                    'nullable' => false,
                    'unsigned' => false,
                    'unique' => false,
                    'index' => false,
                    'fillable' => false,
                    'hidden' => false,
                    'hasDefault' => false,
                ],
                [
                    'name' => 'name',
                    'phpType' => 'string',
                    'nullable' => false,
                    'primary' => false,
                    'autoIncrement' => false,
                    'unsigned' => false,
                    'unique' => true,
                    'index' => false,
                    'fillable' => true,
                    'hidden' => false,
                    'hasDefault' => false,
                ],
                [
                    'name' => 'secret_code',
                    'phpType' => 'string',
                    'nullable' => false,
                    'primary' => false,
                    'autoIncrement' => false,
                    'unsigned' => false,
                    'unique' => false,
                    'index' => false,
                    'fillable' => false,
                    'hidden' => true,
                    'hasDefault' => false,
                ],
            ],
            'relationships' => [],
            'compositeIndexes' => [],
        ]);

        $response->assertOk();

        $content = $this->files->get($this->tempDir.'/app/Schemas/SpaceStationSchema.php');
        $this->assertStringContainsString('use SchemaCraft\\Attributes\\AutoIncrement;', $content);
        $this->assertStringContainsString('use SchemaCraft\\Attributes\\Fillable;', $content);
        $this->assertStringContainsString('use SchemaCraft\\Attributes\\Hidden;', $content);
        $this->assertStringContainsString('use SchemaCraft\\Attributes\\Primary;', $content);
        $this->assertStringContainsString('use SchemaCraft\\Attributes\\Unique;', $content);
        $this->assertStringContainsString('use SchemaCraft\\Traits\\SoftDeletesSchema;', $content);
        $this->assertStringContainsString('use SchemaCraft\\Traits\\TimestampsSchema;', $content);
    }

    // ─── Available Models ─────────────────────────────────────

    public function test_available_models_returns_model_list(): void
    {
        // Create some model files
        $this->files->put($this->tempDir.'/app/Models/Rocket.php', '<?php class Rocket {}');
        $this->files->put($this->tempDir.'/app/Models/Planet.php', '<?php class Planet {}');
        $this->files->put($this->tempDir.'/app/Models/BaseModel.php', '<?php class BaseModel {}');

        $response = $this->getJson('/_schema-craft/api/schema/available-models');

        $response->assertOk();

        $models = $response->json('models');
        $this->assertContains('App\\Models\\Rocket', $models);
        $this->assertContains('App\\Models\\Planet', $models);
        // BaseModel should be excluded
        $this->assertNotContains('App\\Models\\BaseModel', $models);
    }

    public function test_available_models_returns_empty_when_no_models(): void
    {
        // Remove the Models directory to test empty state
        $this->files->deleteDirectory($this->tempDir.'/app/Models');

        $response = $this->getJson('/_schema-craft/api/schema/available-models');

        $response->assertOk();
        $this->assertEmpty($response->json('models'));
    }

    public function test_available_models_returns_sorted(): void
    {
        $this->files->put($this->tempDir.'/app/Models/Zebra.php', '<?php class Zebra {}');
        $this->files->put($this->tempDir.'/app/Models/Alpha.php', '<?php class Alpha {}');
        $this->files->put($this->tempDir.'/app/Models/Middle.php', '<?php class Middle {}');

        $response = $this->getJson('/_schema-craft/api/schema/available-models');

        $models = $response->json('models');
        $this->assertEquals('App\\Models\\Alpha', $models[0]);
        $this->assertEquals('App\\Models\\Middle', $models[1]);
        $this->assertEquals('App\\Models\\Zebra', $models[2]);
    }
}
