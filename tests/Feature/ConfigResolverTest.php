<?php

namespace SchemaCraft\Tests\Feature;

use InvalidArgumentException;
use Orchestra\Testbench\TestCase;
use SchemaCraft\Config\ApiConfig;
use SchemaCraft\Config\ConfigResolver;
use SchemaCraft\SchemaCraftServiceProvider;

class ConfigResolverTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [SchemaCraftServiceProvider::class];
    }

    // ─── resolve() ──────────────────────────────────────────────

    public function test_resolve_returns_default_api_when_no_name_given(): void
    {
        $config = ConfigResolver::resolve();

        $this->assertInstanceOf(ApiConfig::class, $config);
        $this->assertSame('default', $config->name);
    }

    public function test_resolve_returns_named_api(): void
    {
        config()->set('schema-craft.apis.partner', [
            'namespaces' => [
                'controller' => 'App\\Http\\Controllers\\PartnerApi',
            ],
            'sdk' => [
                'name' => 'my-app/partner-sdk',
            ],
        ]);

        $config = ConfigResolver::resolve('partner');

        $this->assertSame('partner', $config->name);
        $this->assertSame('App\\Http\\Controllers\\PartnerApi', $config->controllerNamespace);
        $this->assertSame('my-app/partner-sdk', $config->sdkName);
    }

    public function test_resolve_throws_for_invalid_api_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('API configuration [nonexistent] not found');

        ConfigResolver::resolve('nonexistent');
    }

    public function test_resolve_uses_configured_default(): void
    {
        config()->set('schema-craft.default', 'partner');
        config()->set('schema-craft.apis.partner', [
            'namespaces' => [
                'controller' => 'App\\Http\\Controllers\\PartnerApi',
            ],
        ]);

        $config = ConfigResolver::resolve();

        $this->assertSame('partner', $config->name);
        $this->assertSame('App\\Http\\Controllers\\PartnerApi', $config->controllerNamespace);
    }

    public function test_resolve_returns_defaults_when_no_config_file(): void
    {
        // Simulate no config file by removing the apis key
        config()->set('schema-craft.apis', null);

        $config = ConfigResolver::resolve();

        $this->assertSame('default', $config->name);
        $this->assertSame('App\\Http\\Controllers\\Api', $config->controllerNamespace);
        $this->assertSame('App\\Models\\Services', $config->serviceNamespace);
        $this->assertSame('App\\Http\\Requests', $config->requestNamespace);
        $this->assertSame('App\\Resources', $config->resourceNamespace);
    }

    // ─── allApiNames() ──────────────────────────────────────────

    public function test_all_api_names_returns_configured_names(): void
    {
        config()->set('schema-craft.apis.partner', []);
        config()->set('schema-craft.apis.internal', []);

        $names = ConfigResolver::allApiNames();

        $this->assertContains('default', $names);
        $this->assertContains('partner', $names);
        $this->assertContains('internal', $names);
    }

    public function test_all_api_names_returns_default_when_no_config(): void
    {
        config()->set('schema-craft.apis', null);

        $names = ConfigResolver::allApiNames();

        $this->assertSame(['default'], $names);
    }

    // ─── defaults() ─────────────────────────────────────────────

    public function test_defaults_returns_hardcoded_defaults(): void
    {
        $config = ConfigResolver::defaults();

        $this->assertSame('default', $config->name);
        $this->assertSame('App\\Http\\Controllers\\Api', $config->controllerNamespace);
        $this->assertSame('App\\Models\\Services', $config->serviceNamespace);
        $this->assertSame('packages/sdk', $config->sdkPath);
        $this->assertSame('my-app/sdk', $config->sdkName);
        $this->assertSame('0.1.0', $config->sdkVersion);
    }

    // ─── schemaDirectories() ────────────────────────────────────

    public function test_schema_directories_returns_configured_paths(): void
    {
        config()->set('schema-craft.schema_paths', [
            app_path('Schemas'),
            app_path('Domain/Schemas'),
        ]);

        $dirs = ConfigResolver::schemaDirectories();

        $this->assertCount(2, $dirs);
        $this->assertStringEndsWith('Schemas', $dirs[0]);
        $this->assertStringEndsWith('Domain/Schemas', $dirs[1]);
    }

    public function test_schema_directories_returns_default_when_no_config(): void
    {
        config()->set('schema-craft.schema_paths', null);

        $dirs = ConfigResolver::schemaDirectories();

        $this->assertCount(1, $dirs);
        $this->assertStringEndsWith('Schemas', $dirs[0]);
    }
}
