<?php

namespace SchemaCraft\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use SchemaCraft\Tests\TestCase;

class InstallCommandTest extends TestCase
{
    private Filesystem $files;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;

        // Ensure clean state
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();

        parent::tearDown();
    }

    private function cleanUp(): void
    {
        $path = app_path('Models/BaseModel.php');

        if ($this->files->exists($path)) {
            $this->files->delete($path);
        }
    }

    public function test_install_creates_base_model(): void
    {
        $this->artisan('schema-craft:install')
            ->assertSuccessful();

        $path = app_path('Models/BaseModel.php');

        $this->assertTrue($this->files->exists($path));

        $content = $this->files->get($path);

        $this->assertStringContainsString('namespace App\Models;', $content);
        $this->assertStringContainsString('use SchemaCraft\SchemaModel;', $content);
        $this->assertStringContainsString('abstract class BaseModel extends SchemaModel', $content);
    }

    public function test_install_skips_when_base_model_exists(): void
    {
        $path = app_path('Models/BaseModel.php');

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, '<?php // custom content');

        $this->artisan('schema-craft:install')
            ->assertSuccessful()
            ->expectsOutputToContain('already exists');

        $this->assertEquals('<?php // custom content', $this->files->get($path));
    }

    public function test_install_returns_success_code(): void
    {
        $this->artisan('schema-craft:install')
            ->assertExitCode(0);
    }
}
