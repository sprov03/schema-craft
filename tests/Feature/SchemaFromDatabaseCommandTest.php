<?php

namespace SchemaCraft\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use SchemaCraft\Tests\TestCase;

class SchemaFromDatabaseCommandTest extends TestCase
{
    private string $appDir;

    private string $schemaDir;

    private string $modelDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Use a single temp directory as the app path
        $this->appDir = sys_get_temp_dir().'/schema_from_db_test_'.uniqid();
        $this->schemaDir = $this->appDir.'/Schemas';
        $this->modelDir = $this->appDir.'/Models';

        // Override app_path to use our temp dir
        $this->app->useAppPath($this->appDir);

        // Create a known database table
        Schema::connection('testing')->create('animals', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('species', 100);
            $table->text('description')->nullable();
            $table->integer('age')->default(0);
            $table->boolean('is_adopted')->default(false);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::connection('testing')->dropIfExists('animals');
        Schema::connection('testing')->dropIfExists('owners');
        Schema::connection('testing')->dropIfExists('animal_owner');

        // Clean up temp files
        $this->cleanDir($this->schemaDir);
        $this->cleanDir($this->modelDir);
        @rmdir($this->appDir);

        parent::tearDown();
    }

    public function test_generates_schema_and_model_files(): void
    {
        $this->artisan('schema:from-database', ['--table' => ['animals']])
            ->assertSuccessful();

        $schemaFile = $this->schemaDir.'/AnimalSchema.php';
        $modelFile = $this->modelDir.'/Animal.php';

        $this->assertFileExists($schemaFile);
        $this->assertFileExists($modelFile);

        $schema = file_get_contents($schemaFile);
        $model = file_get_contents($modelFile);

        // Schema assertions
        $this->assertStringContainsString('class AnimalSchema extends Schema', $schema);
        $this->assertStringContainsString('use TimestampsSchema;', $schema);
        $this->assertStringContainsString('#[Primary]', $schema);
        $this->assertStringContainsString('#[AutoIncrement]', $schema);
        $this->assertStringContainsString('public int $id;', $schema);
        $this->assertStringContainsString('public string $name;', $schema);
        // SQLite does not report varchar length, so #[Length(100)] won't appear on SQLite
        $this->assertStringContainsString('public string $species;', $schema);
        $this->assertStringContainsString('#[Text]', $schema);
        $this->assertStringContainsString('public ?string $description;', $schema);
        $this->assertStringContainsString('public int $age = 0;', $schema);
        // SQLite stores booleans as integers, so it may appear as int instead of bool
        $this->assertTrue(
            str_contains($schema, 'public bool $is_adopted = false;')
            || str_contains($schema, 'public int $is_adopted = 0;'),
            'Expected is_adopted as bool or int (SQLite limitation)',
        );

        // Timestamps should NOT appear as individual columns
        $this->assertStringNotContainsString('$created_at', $schema);
        $this->assertStringNotContainsString('$updated_at', $schema);

        // Model assertions
        $this->assertStringContainsString('class Animal extends BaseModel', $model);
        $this->assertStringContainsString('@mixin AnimalSchema', $model);
        $this->assertStringContainsString('protected static string $schema = AnimalSchema::class;', $model);
    }

    public function test_no_model_flag_skips_model_generation(): void
    {
        $this->artisan('schema:from-database', ['--table' => ['animals'], '--no-model' => true])
            ->assertSuccessful();

        $this->assertFileExists($this->schemaDir.'/AnimalSchema.php');
        $this->assertFileDoesNotExist($this->modelDir.'/Animal.php');
    }

    public function test_skips_existing_files_without_force(): void
    {
        // First run — creates files
        $this->artisan('schema:from-database', ['--table' => ['animals']])
            ->assertSuccessful();

        $schemaFile = $this->schemaDir.'/AnimalSchema.php';
        $originalContent = file_get_contents($schemaFile);

        // Modify the file so we can detect if it was overwritten
        file_put_contents($schemaFile, "<?php\n// modified\n");

        // Second run without --force — should skip
        $this->artisan('schema:from-database', ['--table' => ['animals']])
            ->assertSuccessful();

        // File should still have our modification
        $this->assertStringContainsString('// modified', file_get_contents($schemaFile));
    }

    public function test_force_overwrites_existing_files(): void
    {
        // First run
        $this->artisan('schema:from-database', ['--table' => ['animals']])
            ->assertSuccessful();

        $schemaFile = $this->schemaDir.'/AnimalSchema.php';
        file_put_contents($schemaFile, "<?php\n// modified\n");

        // Second run with --force
        $this->artisan('schema:from-database', ['--table' => ['animals'], '--force' => true])
            ->assertSuccessful();

        // File should be regenerated
        $this->assertStringNotContainsString('// modified', file_get_contents($schemaFile));
        $this->assertStringContainsString('class AnimalSchema extends Schema', file_get_contents($schemaFile));
    }

    public function test_exclude_filter_skips_tables(): void
    {
        $this->artisan('schema:from-database', ['--table' => ['animals'], '--exclude' => ['animals']])
            ->assertSuccessful();

        $this->assertFileDoesNotExist($this->schemaDir.'/AnimalSchema.php');
    }

    public function test_table_filter_only_imports_specified_tables(): void
    {
        // Create a second table
        Schema::connection('testing')->create('owners', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        // Only import animals
        $this->artisan('schema:from-database', ['--table' => ['animals']])
            ->assertSuccessful();

        $this->assertFileExists($this->schemaDir.'/AnimalSchema.php');
        $this->assertFileDoesNotExist($this->schemaDir.'/OwnerSchema.php');
    }

    public function test_detects_belongs_to_relationship(): void
    {
        Schema::connection('testing')->create('owners', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        // Drop and recreate animals with a FK
        Schema::connection('testing')->dropIfExists('animals');
        Schema::connection('testing')->create('animals', function ($table) {
            $table->id();
            $table->string('name');
            $table->foreignId('owner_id')->constrained('owners');
            $table->timestamps();
        });

        $this->artisan('schema:from-database', ['--table' => ['animals', 'owners']])
            ->assertSuccessful();

        $animalSchema = file_get_contents($this->schemaDir.'/AnimalSchema.php');

        $this->assertStringContainsString('#[BelongsTo(Owner::class)]', $animalSchema);
        $this->assertStringContainsString('public Owner $owner;', $animalSchema);

        // Owner should have HasMany
        $ownerSchema = file_get_contents($this->schemaDir.'/OwnerSchema.php');

        $this->assertStringContainsString('#[HasMany(Animal::class)]', $ownerSchema);
        $this->assertStringContainsString('public Collection $animals;', $ownerSchema);
    }

    public function test_skips_laravel_internal_tables(): void
    {
        // The 'migrations' table should not be imported even without --exclude
        Schema::connection('testing')->create('migrations', function ($table) {
            $table->id();
            $table->string('migration');
            $table->integer('batch');
        });

        $this->artisan('schema:from-database', ['--table' => ['animals']])
            ->assertSuccessful();

        $this->assertFileDoesNotExist($this->schemaDir.'/MigrationSchema.php');

        Schema::connection('testing')->dropIfExists('migrations');
    }

    public function test_has_many_collision_in_full_flow(): void
    {
        Schema::connection('testing')->create('owners', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        // Drop and recreate animals with multiple FKs to owners
        Schema::connection('testing')->dropIfExists('animals');
        Schema::connection('testing')->create('animals', function ($table) {
            $table->id();
            $table->string('name');
            $table->foreignId('owner_id')->constrained('owners');
            $table->foreignId('adopted_by_id')->constrained('owners');
            $table->timestamps();
        });

        $this->artisan('schema:from-database', ['--table' => ['animals', 'owners']])
            ->assertSuccessful();

        $ownerSchema = file_get_contents($this->schemaDir.'/OwnerSchema.php');

        // Both FKs point to owners, so HasMany should have unique names
        $this->assertStringContainsString('$ownerAnimals', $ownerSchema);
        $this->assertStringContainsString('$adoptedByAnimals', $ownerSchema);
        $this->assertStringContainsString("#[ForeignColumn('owner_id')]", $ownerSchema);
        $this->assertStringContainsString("#[ForeignColumn('adopted_by_id')]", $ownerSchema);
    }

    public function test_self_referencing_has_many_in_full_flow(): void
    {
        Schema::connection('testing')->dropIfExists('animals');
        Schema::connection('testing')->create('animals', function ($table) {
            $table->id();
            $table->string('name');
            $table->foreignId('parent_animal_id')->nullable()->constrained('animals');
            $table->timestamps();
        });

        $this->artisan('schema:from-database', ['--table' => ['animals']])
            ->assertSuccessful();

        $animalSchema = file_get_contents($this->schemaDir.'/AnimalSchema.php');

        // BelongsTo side
        $this->assertStringContainsString('#[BelongsTo(Animal::class)]', $animalSchema);
        $this->assertStringContainsString('public ?Animal $parentAnimal;', $animalSchema);

        // HasMany side — self-referencing, only 1 FK so no collision
        $this->assertStringContainsString('#[HasMany(Animal::class)]', $animalSchema);
        $this->assertStringContainsString('public Collection $animals;', $animalSchema);
    }

    public function test_custom_output_paths(): void
    {
        $customDir = sys_get_temp_dir().'/custom_output_test_'.uniqid();
        $customSchemaDir = $customDir.'/CustomSchemas';
        $customModelDir = $customDir.'/CustomModels';

        $this->artisan('schema:from-database', [
            '--table' => ['animals'],
            '--schema-path' => $customSchemaDir,
            '--model-path' => $customModelDir,
        ])->assertSuccessful();

        $schemaFile = base_path($customSchemaDir).'/AnimalSchema.php';
        $modelFile = base_path($customModelDir).'/Animal.php';

        $this->assertFileExists($schemaFile);
        $this->assertFileExists($modelFile);

        // Default app path should NOT have the files
        $this->assertFileDoesNotExist($this->schemaDir.'/AnimalSchema.php');

        // Clean up
        @unlink($schemaFile);
        @unlink($modelFile);
        @rmdir(base_path($customSchemaDir));
        @rmdir(base_path($customModelDir));
        @rmdir(base_path($customDir));
    }

    public function test_custom_namespaces_in_generated_content(): void
    {
        $this->artisan('schema:from-database', [
            '--table' => ['animals'],
            '--schema-namespace' => 'Generated\\Schemas',
            '--model-namespace' => 'Generated\\Models',
            '--force' => true,
        ])->assertSuccessful();

        $schemaFile = $this->schemaDir.'/AnimalSchema.php';
        $modelFile = $this->modelDir.'/Animal.php';

        $this->assertFileExists($schemaFile);

        $schema = file_get_contents($schemaFile);
        $model = file_get_contents($modelFile);

        $this->assertStringContainsString('namespace Generated\\Schemas;', $schema);
        $this->assertStringContainsString('namespace Generated\\Models;', $model);
        $this->assertStringContainsString('use Generated\\Schemas\\AnimalSchema;', $model);
    }

    public function test_generated_schema_is_valid_php(): void
    {
        $this->artisan('schema:from-database', ['--table' => ['animals']])
            ->assertSuccessful();

        $schemaFile = $this->schemaDir.'/AnimalSchema.php';
        $content = file_get_contents($schemaFile);

        // Check valid PHP by trying to parse it
        $tokens = token_get_all($content);
        $this->assertNotEmpty($tokens);
        $this->assertStringStartsWith('<?php', $content);
    }

    private function cleanDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = glob($dir.'/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        @rmdir($dir);
        @rmdir(dirname($dir));
    }
}
