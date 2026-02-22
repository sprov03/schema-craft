<?php

namespace SchemaCraft\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use SchemaCraft\Tests\TestCase;

class MakeSchemaCommandTest extends TestCase
{
    private Filesystem $files;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;

        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();

        parent::tearDown();
    }

    private function cleanUp(): void
    {
        $schemasDir = app_path('Schemas');
        $baseModelPath = app_path('Models/BaseModel.php');

        if ($this->files->isDirectory($schemasDir)) {
            $this->files->deleteDirectory($schemasDir);
        }

        // Clean up generated model files but not the Models directory itself
        foreach (['Dog', 'Owner', 'Walk', 'Cat'] as $name) {
            $modelPath = app_path("Models/{$name}.php");

            if ($this->files->exists($modelPath)) {
                $this->files->delete($modelPath);
            }
        }

        if ($this->files->exists($baseModelPath)) {
            $this->files->delete($baseModelPath);
        }
    }

    private function installBaseModel(): void
    {
        $this->artisan('schema-craft:install');
    }

    public function test_creates_schema_and_model_for_single_name(): void
    {
        $this->installBaseModel();

        $this->artisan('make:schema', ['name' => ['Dog'], '--no-interaction' => true])
            ->assertSuccessful();

        $this->assertTrue($this->files->exists(app_path('Schemas/DogSchema.php')));
        $this->assertTrue($this->files->exists(app_path('Models/Dog.php')));
    }

    public function test_creates_multiple_schemas_and_models(): void
    {
        $this->installBaseModel();

        $this->artisan('make:schema', ['name' => ['Owner', 'Dog', 'Walk'], '--no-interaction' => true])
            ->assertSuccessful();

        foreach (['Owner', 'Dog', 'Walk'] as $name) {
            $this->assertTrue(
                $this->files->exists(app_path("Schemas/{$name}Schema.php")),
                "Expected {$name}Schema.php to exist."
            );
            $this->assertTrue(
                $this->files->exists(app_path("Models/{$name}.php")),
                "Expected {$name}.php model to exist."
            );
        }
    }

    public function test_no_model_flag_skips_model(): void
    {
        $this->artisan('make:schema', ['name' => ['Dog'], '--no-model' => true, '--no-interaction' => true])
            ->assertSuccessful();

        $this->assertTrue($this->files->exists(app_path('Schemas/DogSchema.php')));
        $this->assertFalse($this->files->exists(app_path('Models/Dog.php')));
    }

    public function test_uuid_option_applies_to_all(): void
    {
        $this->installBaseModel();

        $this->artisan('make:schema', ['name' => ['Dog', 'Walk'], '--uuid' => true, '--no-interaction' => true])
            ->assertSuccessful();

        foreach (['Dog', 'Walk'] as $name) {
            $content = $this->files->get(app_path("Schemas/{$name}Schema.php"));

            $this->assertStringContainsString('#[Primary]', $content);
            $this->assertStringContainsString("#[ColumnType('uuid')]", $content);
            $this->assertStringContainsString('public string $id;', $content);
        }
    }

    public function test_soft_deletes_applies_to_all(): void
    {
        $this->installBaseModel();

        $this->artisan('make:schema', ['name' => ['Dog', 'Walk'], '--soft-deletes' => true, '--no-interaction' => true])
            ->assertSuccessful();

        foreach (['Dog', 'Walk'] as $name) {
            $schemaContent = $this->files->get(app_path("Schemas/{$name}Schema.php"));
            $modelContent = $this->files->get(app_path("Models/{$name}.php"));

            $this->assertStringContainsString('use SoftDeletesSchema;', $schemaContent);
            $this->assertStringContainsString('use SoftDeletes;', $modelContent);
        }
    }

    public function test_warns_when_base_model_missing(): void
    {
        $this->artisan('make:schema', ['name' => ['Dog'], '--no-interaction' => true])
            ->expectsOutputToContain('BaseModel not found')
            ->assertSuccessful();

        // Files should still be created
        $this->assertTrue($this->files->exists(app_path('Schemas/DogSchema.php')));
        $this->assertTrue($this->files->exists(app_path('Models/Dog.php')));
    }

    public function test_no_warning_when_no_model_flag_set(): void
    {
        $this->artisan('make:schema', ['name' => ['Dog'], '--no-model' => true, '--no-interaction' => true])
            ->doesntExpectOutputToContain('BaseModel not found')
            ->assertSuccessful();
    }

    public function test_model_extends_base_model(): void
    {
        $this->installBaseModel();

        $this->artisan('make:schema', ['name' => ['Dog'], '--no-interaction' => true])
            ->assertSuccessful();

        $content = $this->files->get(app_path('Models/Dog.php'));

        $this->assertStringContainsString('use App\Models\BaseModel;', $content);
        $this->assertStringContainsString('extends BaseModel', $content);
        $this->assertStringNotContainsString('SchemaCraft\SchemaModel', $content);
    }

    public function test_generated_files_are_valid_php(): void
    {
        $this->installBaseModel();

        $this->artisan('make:schema', ['name' => ['Dog'], '--soft-deletes' => true, '--no-interaction' => true])
            ->assertSuccessful();

        $schemaPath = app_path('Schemas/DogSchema.php');
        $modelPath = app_path('Models/Dog.php');

        exec("php -l {$schemaPath} 2>&1", $schemaOutput, $schemaExit);
        exec("php -l {$modelPath} 2>&1", $modelOutput, $modelExit);

        $this->assertEquals(0, $schemaExit, "DogSchema.php has syntax errors:\n".implode("\n", $schemaOutput));
        $this->assertEquals(0, $modelExit, "Dog.php has syntax errors:\n".implode("\n", $modelOutput));
    }

    public function test_schema_contains_correct_class_and_namespace(): void
    {
        $this->installBaseModel();

        $this->artisan('make:schema', ['name' => ['Dog'], '--no-interaction' => true])
            ->assertSuccessful();

        $content = $this->files->get(app_path('Schemas/DogSchema.php'));

        $this->assertStringContainsString('namespace App\Schemas;', $content);
        $this->assertStringContainsString('class DogSchema extends Schema', $content);
        $this->assertStringContainsString('use TimestampsSchema;', $content);
        $this->assertStringContainsString('#[Primary]', $content);
        $this->assertStringContainsString('#[AutoIncrement]', $content);
        $this->assertStringContainsString('public int $id;', $content);
    }

    public function test_model_references_correct_schema(): void
    {
        $this->installBaseModel();

        $this->artisan('make:schema', ['name' => ['Dog'], '--no-interaction' => true])
            ->assertSuccessful();

        $content = $this->files->get(app_path('Models/Dog.php'));

        $this->assertStringContainsString('namespace App\Models;', $content);
        $this->assertStringContainsString('use App\Schemas\DogSchema;', $content);
        $this->assertStringContainsString('@mixin DogSchema', $content);
        $this->assertStringContainsString('protected static string $schema = DogSchema::class;', $content);
    }
}
