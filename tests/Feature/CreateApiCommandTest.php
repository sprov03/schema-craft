<?php

namespace SchemaCraft\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Orchestra\Testbench\TestCase;
use SchemaCraft\SchemaCraftServiceProvider;

class CreateApiCommandTest extends TestCase
{
    private Filesystem $files;

    private array $createdFiles = [];

    private array $createdDirs = [];

    private ?string $originalBootstrap = null;

    private ?string $originalConfig = null;

    protected function getPackageProviders($app): array
    {
        return [SchemaCraftServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->files = new Filesystem;

        // Back up bootstrap/app.php
        $bootstrapPath = base_path('bootstrap/app.php');
        if ($this->files->exists($bootstrapPath)) {
            $this->originalBootstrap = $this->files->get($bootstrapPath);
        }

        // Back up config/schema-craft.php if it exists
        $configPath = config_path('schema-craft.php');
        if ($this->files->exists($configPath)) {
            $this->originalConfig = $this->files->get($configPath);
        }
    }

    protected function tearDown(): void
    {
        // Clean up created files
        foreach ($this->createdFiles as $file) {
            if ($this->files->exists($file)) {
                $this->files->delete($file);
            }
        }

        // Clean up created directories
        foreach ($this->createdDirs as $dir) {
            if (is_dir($dir)) {
                $this->files->deleteDirectory($dir);
            }
        }

        // Restore bootstrap/app.php
        $bootstrapPath = base_path('bootstrap/app.php');
        if ($this->originalBootstrap !== null) {
            $this->files->put($bootstrapPath, $this->originalBootstrap);
        }

        // Restore or remove config/schema-craft.php
        $configPath = config_path('schema-craft.php');
        if ($this->originalConfig !== null) {
            $this->files->put($configPath, $this->originalConfig);
        } elseif ($this->files->exists($configPath)) {
            $this->files->delete($configPath);
        }

        parent::tearDown();
    }

    private function trackFile(string $path): void
    {
        $this->createdFiles[] = $path;
    }

    private function trackDir(string $path): void
    {
        $this->createdDirs[] = $path;
    }

    private function ensureConfigFileExists(): void
    {
        $configPath = config_path('schema-craft.php');
        if (! $this->files->exists($configPath)) {
            $this->files->ensureDirectoryExists(dirname($configPath));
            $sourcePath = dirname(__DIR__, 2).'/config/schema-craft.php';
            $this->files->copy($sourcePath, $configPath);
        }
    }

    /**
     * Write a realistic Laravel bootstrap/app.php so the route registration logic can be tested.
     */
    private function writeRealisticBootstrap(): void
    {
        $bootstrapPath = base_path('bootstrap/app.php');
        $this->files->put($bootstrapPath, <<<'PHP'
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
PHP);
    }

    // ─── Basic creation ─────────────────────────────────────────────

    public function test_creates_route_file(): void
    {
        $this->ensureConfigFileExists();

        $routeFile = base_path('routes/partner-api.php');
        $this->trackFile($routeFile);
        $this->trackDir(app_path('Http/Controllers/PartnerApi'));
        $this->trackDir(app_path('Http/Requests/PartnerApi'));
        $this->trackDir(app_path('Resources/PartnerApi'));

        $this->artisan('schema:api:create', ['name' => 'partner'])
            ->assertSuccessful();

        $this->assertFileExists($routeFile);
        $content = $this->files->get($routeFile);
        $this->assertStringContainsString('Partner API Routes', $content);
        $this->assertStringContainsString('use Illuminate\\Support\\Facades\\Route;', $content);
    }

    public function test_creates_isolated_directories(): void
    {
        $this->ensureConfigFileExists();

        $this->trackDir(app_path('Http/Controllers/PartnerApi'));
        $this->trackDir(app_path('Http/Requests/PartnerApi'));
        $this->trackDir(app_path('Resources/PartnerApi'));
        $this->trackFile(base_path('routes/partner-api.php'));

        $this->artisan('schema:api:create', ['name' => 'partner'])
            ->assertSuccessful();

        $this->assertDirectoryExists(app_path('Http/Controllers/PartnerApi'));
        $this->assertDirectoryExists(app_path('Http/Requests/PartnerApi'));
        $this->assertDirectoryExists(app_path('Resources/PartnerApi'));

        // .gitkeep files should exist
        $this->assertFileExists(app_path('Http/Controllers/PartnerApi/.gitkeep'));
        $this->assertFileExists(app_path('Http/Requests/PartnerApi/.gitkeep'));
        $this->assertFileExists(app_path('Resources/PartnerApi/.gitkeep'));
    }

    public function test_adds_api_entry_to_config(): void
    {
        $this->ensureConfigFileExists();

        $this->trackDir(app_path('Http/Controllers/PartnerApi'));
        $this->trackDir(app_path('Http/Requests/PartnerApi'));
        $this->trackDir(app_path('Resources/PartnerApi'));
        $this->trackFile(base_path('routes/partner-api.php'));

        $this->artisan('schema:api:create', ['name' => 'partner'])
            ->assertSuccessful();

        $configContent = $this->files->get(config_path('schema-craft.php'));
        $this->assertStringContainsString("'partner'", $configContent);
        $this->assertStringContainsString('Http\\\\Controllers\\\\PartnerApi', $configContent);
        $this->assertStringContainsString('Http\\\\Requests\\\\PartnerApi', $configContent);
        $this->assertStringContainsString('Resources\\\\PartnerApi', $configContent);
        $this->assertStringContainsString('routes/partner-api.php', $configContent);
    }

    public function test_registers_route_in_bootstrap(): void
    {
        $this->ensureConfigFileExists();
        $this->writeRealisticBootstrap();

        $this->trackDir(app_path('Http/Controllers/PartnerApi'));
        $this->trackDir(app_path('Http/Requests/PartnerApi'));
        $this->trackDir(app_path('Resources/PartnerApi'));
        $this->trackFile(base_path('routes/partner-api.php'));

        $this->artisan('schema:api:create', ['name' => 'partner'])
            ->assertSuccessful();

        $bootstrapContent = $this->files->get(base_path('bootstrap/app.php'));
        $this->assertStringContainsString('routes/partner-api.php', $bootstrapContent);
        $this->assertStringContainsString('partner-api', $bootstrapContent);
        $this->assertStringContainsString('then:', $bootstrapContent);
    }

    // ─── Custom prefix ──────────────────────────────────────────────

    public function test_uses_custom_prefix(): void
    {
        $this->ensureConfigFileExists();
        $this->writeRealisticBootstrap();

        $routeFile = base_path('routes/external-api.php');
        $this->trackFile($routeFile);
        $this->trackDir(app_path('Http/Controllers/ExternalApi'));
        $this->trackDir(app_path('Http/Requests/ExternalApi'));
        $this->trackDir(app_path('Resources/ExternalApi'));

        $this->artisan('schema:api:create', ['name' => 'external', '--prefix' => 'v1/external'])
            ->assertSuccessful();

        $configContent = $this->files->get(config_path('schema-craft.php'));
        $this->assertStringContainsString('v1/external', $configContent);

        $bootstrapContent = $this->files->get(base_path('bootstrap/app.php'));
        $this->assertStringContainsString('v1/external', $bootstrapContent);
    }

    // ─── Conflict detection ─────────────────────────────────────────

    public function test_fails_for_existing_api_name(): void
    {
        // 'default' is already configured
        $this->artisan('schema:api:create', ['name' => 'default'])
            ->assertFailed();
    }

    // ─── Multiple APIs ──────────────────────────────────────────────

    public function test_creates_second_api_alongside_first(): void
    {
        $this->ensureConfigFileExists();
        $this->writeRealisticBootstrap();

        // Create first API
        $this->trackDir(app_path('Http/Controllers/PartnerApi'));
        $this->trackDir(app_path('Http/Requests/PartnerApi'));
        $this->trackDir(app_path('Resources/PartnerApi'));
        $this->trackFile(base_path('routes/partner-api.php'));

        $this->artisan('schema:api:create', ['name' => 'partner'])
            ->assertSuccessful();

        // Create second API
        $this->trackDir(app_path('Http/Controllers/InternalApi'));
        $this->trackDir(app_path('Http/Requests/InternalApi'));
        $this->trackDir(app_path('Resources/InternalApi'));
        $this->trackFile(base_path('routes/internal-api.php'));

        $this->artisan('schema:api:create', ['name' => 'internal'])
            ->assertSuccessful();

        // Both should exist in config
        $configContent = $this->files->get(config_path('schema-craft.php'));
        $this->assertStringContainsString("'partner'", $configContent);
        $this->assertStringContainsString("'internal'", $configContent);

        // Both route files should exist
        $this->assertFileExists(base_path('routes/partner-api.php'));
        $this->assertFileExists(base_path('routes/internal-api.php'));

        // Bootstrap should have both routes
        $bootstrapContent = $this->files->get(base_path('bootstrap/app.php'));
        $this->assertStringContainsString('routes/partner-api.php', $bootstrapContent);
        $this->assertStringContainsString('routes/internal-api.php', $bootstrapContent);
    }

    public function test_second_api_appends_to_existing_then_closure(): void
    {
        $this->ensureConfigFileExists();
        $this->writeRealisticBootstrap();

        $this->trackDir(app_path('Http/Controllers/PartnerApi'));
        $this->trackDir(app_path('Http/Requests/PartnerApi'));
        $this->trackDir(app_path('Resources/PartnerApi'));
        $this->trackFile(base_path('routes/partner-api.php'));
        $this->trackDir(app_path('Http/Controllers/InternalApi'));
        $this->trackDir(app_path('Http/Requests/InternalApi'));
        $this->trackDir(app_path('Resources/InternalApi'));
        $this->trackFile(base_path('routes/internal-api.php'));

        // Create first — establishes `then:` closure
        $this->artisan('schema:api:create', ['name' => 'partner'])
            ->assertSuccessful();

        // Create second — appends to existing `then:` closure
        $this->artisan('schema:api:create', ['name' => 'internal'])
            ->assertSuccessful();

        $bootstrapContent = $this->files->get(base_path('bootstrap/app.php'));

        // Both routes should be within the same `then:` block
        $this->assertSame(1, substr_count($bootstrapContent, 'then:'));
        $this->assertStringContainsString('routes/partner-api.php', $bootstrapContent);
        $this->assertStringContainsString('routes/internal-api.php', $bootstrapContent);
    }

    // ─── Sanctum detection ──────────────────────────────────────────

    public function test_sanctum_flag_detects_existing_sanctum(): void
    {
        $this->ensureConfigFileExists();

        $this->trackDir(app_path('Http/Controllers/PartnerApi'));
        $this->trackDir(app_path('Http/Requests/PartnerApi'));
        $this->trackDir(app_path('Resources/PartnerApi'));
        $this->trackFile(base_path('routes/partner-api.php'));

        // Sanctum is already in composer.json for this project
        $composerJson = json_decode($this->files->get(base_path('composer.json')), true);
        $hasSanctum = isset($composerJson['require']['laravel/sanctum']);

        if ($hasSanctum) {
            // Should succeed — Sanctum already installed
            $this->artisan('schema:api:create', ['name' => 'partner', '--setup-sanctum' => true])
                ->assertSuccessful();
        } else {
            // Just create without sanctum for this test
            $this->artisan('schema:api:create', ['name' => 'partner'])
                ->assertSuccessful();
        }

        $this->assertDirectoryExists(app_path('Http/Controllers/PartnerApi'));
    }
}
