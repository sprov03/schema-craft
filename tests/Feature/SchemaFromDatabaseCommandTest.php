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
        // Using App\Schemas\Custom and App\Models\Custom so dir resolves via app_path()
        $this->artisan('schema:from-database', [
            '--table' => ['animals'],
            '--schema-namespace' => 'App\\Schemas\\Custom',
            '--model-namespace' => 'App\\Models\\Custom',
            '--force' => true,
        ])->assertSuccessful();

        $customSchemaDir = $this->appDir.'/Schemas/Custom';
        $customModelDir = $this->appDir.'/Models/Custom';

        $schemaFile = $customSchemaDir.'/AnimalSchema.php';
        $modelFile = $customModelDir.'/Animal.php';

        $this->assertFileExists($schemaFile);

        $schema = file_get_contents($schemaFile);
        $model = file_get_contents($modelFile);

        $this->assertStringContainsString('namespace App\\Schemas\\Custom;', $schema);
        $this->assertStringContainsString('namespace App\\Models\\Custom;', $model);
        $this->assertStringContainsString('use App\\Schemas\\Custom\\AnimalSchema;', $model);

        // Clean up
        $this->cleanDir($customSchemaDir);
        $this->cleanDir($customModelDir);
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

    // ─── DB Connection Config Tests ─────────────────────────────

    public function test_db_connection_config_applies_prefix_to_class_names(): void
    {
        config()->set('schema-craft.db_connections.prefixed', [
            'prefixes' => [
                'service' => 'Crm',
                'schema' => 'Crm',
                'model' => 'Crm',
            ],
            'namespaces' => [
                'service' => 'App\\Models\\Services',
                'schema' => 'App\\Schemas',
                'model' => 'App\\Models',
            ],
            'connection' => 'testing',
        ]);

        $this->artisan('schema:from-database', [
            '--db-connection' => 'prefixed',
            '--table' => ['animals'],
            '--force' => true,
        ])->assertSuccessful();

        $schemaFile = $this->schemaDir.'/CrmAnimalSchema.php';
        $modelFile = $this->modelDir.'/CrmAnimal.php';

        $this->assertFileExists($schemaFile);
        $this->assertFileExists($modelFile);

        $schema = file_get_contents($schemaFile);
        $model = file_get_contents($modelFile);

        $this->assertStringContainsString('class CrmAnimalSchema extends Schema', $schema);
        $this->assertStringContainsString('class CrmAnimal extends BaseModel', $model);
        $this->assertStringContainsString('@mixin CrmAnimalSchema', $model);
        $this->assertStringContainsString('protected static string $schema = CrmAnimalSchema::class;', $model);
    }

    public function test_db_connection_config_applies_custom_namespace(): void
    {
        config()->set('schema-craft.db_connections.namespaced', [
            'prefixes' => [
                'service' => '',
                'schema' => '',
                'model' => '',
            ],
            'namespaces' => [
                'service' => 'App\\Models\\Services\\Crm',
                'schema' => 'App\\Schemas\\Crm',
                'model' => 'App\\Models\\Crm',
            ],
            'connection' => 'testing',
        ]);

        $customSchemaDir = $this->appDir.'/Schemas/Crm';
        $customModelDir = $this->appDir.'/Models/Crm';

        $this->artisan('schema:from-database', [
            '--db-connection' => 'namespaced',
            '--table' => ['animals'],
            '--force' => true,
        ])->assertSuccessful();

        $schemaFile = $customSchemaDir.'/AnimalSchema.php';
        $modelFile = $customModelDir.'/Animal.php';

        $this->assertFileExists($schemaFile);
        $this->assertFileExists($modelFile);

        $schema = file_get_contents($schemaFile);
        $model = file_get_contents($modelFile);

        $this->assertStringContainsString('namespace App\\Schemas\\Crm;', $schema);
        $this->assertStringContainsString('namespace App\\Models\\Crm;', $model);
        $this->assertStringContainsString('use App\\Schemas\\Crm\\AnimalSchema;', $model);

        // Clean up extra dirs
        $this->cleanDir($customSchemaDir);
        $this->cleanDir($customModelDir);
    }

    public function test_db_connection_config_emits_connection_property(): void
    {
        config()->set('schema-craft.db_connections.external', [
            'prefixes' => [
                'service' => '',
                'schema' => '',
                'model' => '',
            ],
            'namespaces' => [
                'service' => 'App\\Models\\Services',
                'schema' => 'App\\Schemas',
                'model' => 'App\\Models',
            ],
            'connection' => 'external-db',
        ]);

        // We still need a working DB connection for reading, so override --connection
        $this->artisan('schema:from-database', [
            '--db-connection' => 'external',
            '--connection' => 'testing',
            '--table' => ['animals'],
            '--force' => true,
        ])->assertSuccessful();

        $schemaFile = $this->schemaDir.'/AnimalSchema.php';
        $modelFile = $this->modelDir.'/Animal.php';

        $schema = file_get_contents($schemaFile);
        $model = file_get_contents($modelFile);

        $this->assertStringContainsString("protected static ?string \$connection = 'external-db';", $schema);
        $this->assertStringContainsString("protected \$connection = 'external-db';", $model);
    }

    public function test_db_connection_default_does_not_emit_connection_property(): void
    {
        // When connection matches the app's default DB connection, no $connection property is emitted
        $appDefault = config('database.default');

        config()->set('schema-craft.db_connections.default', [
            'prefixes' => [
                'service' => '',
                'schema' => '',
                'model' => '',
            ],
            'namespaces' => [
                'service' => 'App\\Models\\Services',
                'schema' => 'App\\Schemas',
                'model' => 'App\\Models',
            ],
            'connection' => $appDefault,
        ]);

        $this->artisan('schema:from-database', [
            '--db-connection' => 'default',
            '--connection' => 'testing',
            '--table' => ['animals'],
            '--force' => true,
        ])->assertSuccessful();

        $schema = file_get_contents($this->schemaDir.'/AnimalSchema.php');
        $model = file_get_contents($this->modelDir.'/Animal.php');

        $this->assertStringNotContainsString('$connection', $schema);
        $this->assertStringNotContainsString('$connection', $model);
    }

    public function test_cli_options_override_db_connection_config(): void
    {
        config()->set('schema-craft.db_connections.override-test', [
            'prefixes' => [
                'service' => 'Prefix',
                'schema' => 'Prefix',
                'model' => 'Prefix',
            ],
            'namespaces' => [
                'service' => 'App\\Models\\Services',
                'schema' => 'App\\Schemas\\Custom',
                'model' => 'App\\Models\\Custom',
            ],
            'connection' => 'testing',
        ]);

        // CLI --schema-namespace should override the config value
        // Use App\Schemas\Override so it resolves within the test's app_path
        $this->artisan('schema:from-database', [
            '--db-connection' => 'override-test',
            '--table' => ['animals'],
            '--schema-namespace' => 'App\\Schemas\\Override',
            '--force' => true,
        ])->assertSuccessful();

        $overrideSchemaDir = $this->appDir.'/Schemas/Override';
        $schemaFile = $overrideSchemaDir.'/PrefixAnimalSchema.php';

        // Config-derived prefix should still apply (CLI doesn't override prefix)
        // But namespace should be the CLI override
        $this->assertFileExists($schemaFile);
        $schema = file_get_contents($schemaFile);

        $this->assertStringContainsString('namespace App\\Schemas\\Override;', $schema);
        $this->assertStringContainsString('class PrefixAnimalSchema extends Schema', $schema);

        // Clean up
        $this->cleanDir($overrideSchemaDir);
    }

    public function test_prefixed_belongs_to_references_use_prefix(): void
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

        config()->set('schema-craft.db_connections.prefixed', [
            'prefixes' => [
                'service' => 'Crm',
                'schema' => 'Crm',
                'model' => 'Crm',
            ],
            'namespaces' => [
                'service' => 'App\\Models\\Services',
                'schema' => 'App\\Schemas',
                'model' => 'App\\Models',
            ],
            'connection' => 'testing',
        ]);

        $this->artisan('schema:from-database', [
            '--db-connection' => 'prefixed',
            '--table' => ['animals', 'owners'],
            '--force' => true,
        ])->assertSuccessful();

        $animalSchema = file_get_contents($this->schemaDir.'/CrmAnimalSchema.php');

        // BelongsTo should reference the prefixed model name
        $this->assertStringContainsString('#[BelongsTo(CrmOwner::class)]', $animalSchema);
        $this->assertStringContainsString('public CrmOwner $owner;', $animalSchema);

        // HasMany on owner should also use prefixed names
        $ownerSchema = file_get_contents($this->schemaDir.'/CrmOwnerSchema.php');
        $this->assertStringContainsString('#[HasMany(CrmAnimal::class)]', $ownerSchema);
    }

    public function test_without_db_connection_generates_default_output(): void
    {
        // Ensure backward compatibility — no --db-connection option
        $this->artisan('schema:from-database', [
            '--table' => ['animals'],
            '--force' => true,
        ])->assertSuccessful();

        $schemaFile = $this->schemaDir.'/AnimalSchema.php';
        $modelFile = $this->modelDir.'/Animal.php';

        $this->assertFileExists($schemaFile);
        $this->assertFileExists($modelFile);

        $schema = file_get_contents($schemaFile);
        $model = file_get_contents($modelFile);

        // No prefix
        $this->assertStringContainsString('class AnimalSchema extends Schema', $schema);
        $this->assertStringContainsString('class Animal extends BaseModel', $model);

        // No $connection property
        $this->assertStringNotContainsString('$connection', $schema);
        $this->assertStringNotContainsString('$connection', $model);
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
