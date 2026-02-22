<?php

namespace SchemaCraft\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use SchemaCraft\Tests\TestCase;

class ApplyRelationshipEndpointTest extends TestCase
{
    private Filesystem $files;

    private string $schemasDir;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['env'] = 'local';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->schemasDir = app_path('Schemas');
        $this->files->ensureDirectoryExists($this->schemasDir);
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->schemasDir);

        parent::tearDown();
    }

    private function createSchemaFileAndClass(string $name): string
    {
        $path = $this->schemasDir."/{$name}Schema.php";
        $content = <<<PHP
<?php

namespace App\Schemas;

use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Schema;
use SchemaCraft\Traits\TimestampsSchema;

class {$name}Schema extends Schema
{
    use TimestampsSchema;

    #[Primary]
    #[AutoIncrement]
    public int \$id;
}
PHP;

        $this->files->put($path, $content);

        require_once $path;

        return $path;
    }

    public function test_apply_relationship_returns_success(): void
    {
        $path = $this->createSchemaFileAndClass('TestApplyDog');

        $response = $this->postJson('/_schema-craft/api/apply-relationship', [
            'schemaClass' => 'App\Schemas\TestApplyDogSchema',
            'relationshipType' => 'belongsTo',
            'relatedModel' => 'App\Models\Owner',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $content = $this->files->get($path);
        $this->assertStringContainsString('#[BelongsTo(Owner::class)]', $content);
    }

    public function test_apply_relationship_rejects_invalid_type(): void
    {
        $this->createSchemaFileAndClass('TestInvalidType');

        $response = $this->postJson('/_schema-craft/api/apply-relationship', [
            'schemaClass' => 'App\Schemas\TestInvalidTypeSchema',
            'relationshipType' => 'invalidType',
            'relatedModel' => 'App\Models\Owner',
        ]);

        $response->assertUnprocessable();
    }

    public function test_apply_relationship_rejects_missing_schema(): void
    {
        $response = $this->postJson('/_schema-craft/api/apply-relationship', [
            'schemaClass' => 'App\Schemas\NonExistentSchema',
            'relationshipType' => 'belongsTo',
            'relatedModel' => 'App\Models\Owner',
        ]);

        $response->assertNotFound();
        $response->assertJson(['success' => false]);
    }

    public function test_apply_relationship_requires_schema_class(): void
    {
        $response = $this->postJson('/_schema-craft/api/apply-relationship', [
            'relationshipType' => 'belongsTo',
            'relatedModel' => 'App\Models\Owner',
        ]);

        $response->assertUnprocessable();
    }
}
