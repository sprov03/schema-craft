<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Config\ApiConfig;

class ApiConfigTest extends TestCase
{
    // ─── fromArray with defaults ────────────────────────────────

    public function test_from_array_with_empty_config_uses_defaults(): void
    {
        $config = ApiConfig::fromArray('default', []);

        $this->assertSame('default', $config->name);
        $this->assertSame('App\\Http\\Controllers\\Api', $config->controllerNamespace);
        $this->assertSame('App\\Models\\Services', $config->serviceNamespace);
        $this->assertSame('App\\Http\\Requests', $config->requestNamespace);
        $this->assertSame('App\\Resources', $config->resourceNamespace);
        $this->assertSame('App\\Schemas', $config->schemaNamespace);
        $this->assertSame('App\\Models', $config->modelNamespace);
        $this->assertSame('routes/api.php', $config->routeFile);
        $this->assertSame('api', $config->routePrefix);
        $this->assertSame(['auth:sanctum'], $config->routeMiddleware);
        $this->assertNull($config->schemas);
        $this->assertSame('packages/sdk', $config->sdkPath);
        $this->assertSame('my-app/sdk', $config->sdkName);
        $this->assertSame('MyApp\\Sdk', $config->sdkNamespace);
        $this->assertSame('MyAppClient', $config->sdkClient);
        $this->assertSame('0.1.0', $config->sdkVersion);
    }

    public function test_from_array_with_custom_config(): void
    {
        $config = ApiConfig::fromArray('partner', [
            'namespaces' => [
                'controller' => 'App\\Http\\Controllers\\PartnerApi',
                'service' => 'App\\Services\\PartnerApi',
                'request' => 'App\\Http\\Requests\\PartnerApi',
                'resource' => 'App\\Resources\\PartnerApi',
                'schema' => 'App\\Schemas',
                'model' => 'App\\Models',
            ],
            'routes' => [
                'file' => 'routes/partner-api.php',
                'prefix' => 'partner-api',
                'middleware' => ['auth:sanctum', 'partner'],
            ],
            'schemas' => ['PostSchema', 'UserSchema'],
            'sdk' => [
                'path' => 'packages/partner-sdk',
                'name' => 'my-app/partner-sdk',
                'namespace' => 'MyApp\\PartnerSdk',
                'client' => 'PartnerClient',
                'version' => '1.2.3',
            ],
        ]);

        $this->assertSame('partner', $config->name);
        $this->assertSame('App\\Http\\Controllers\\PartnerApi', $config->controllerNamespace);
        $this->assertSame('App\\Services\\PartnerApi', $config->serviceNamespace);
        $this->assertSame('App\\Http\\Requests\\PartnerApi', $config->requestNamespace);
        $this->assertSame('App\\Resources\\PartnerApi', $config->resourceNamespace);
        $this->assertSame('routes/partner-api.php', $config->routeFile);
        $this->assertSame('partner-api', $config->routePrefix);
        $this->assertSame(['auth:sanctum', 'partner'], $config->routeMiddleware);
        $this->assertSame(['PostSchema', 'UserSchema'], $config->schemas);
        $this->assertSame('packages/partner-sdk', $config->sdkPath);
        $this->assertSame('my-app/partner-sdk', $config->sdkName);
        $this->assertSame('MyApp\\PartnerSdk', $config->sdkNamespace);
        $this->assertSame('PartnerClient', $config->sdkClient);
        $this->assertSame('1.2.3', $config->sdkVersion);
    }

    public function test_from_array_with_partial_config(): void
    {
        $config = ApiConfig::fromArray('partial', [
            'namespaces' => [
                'controller' => 'App\\Http\\Controllers\\CustomApi',
            ],
            'sdk' => [
                'name' => 'custom/sdk',
            ],
        ]);

        // Provided values
        $this->assertSame('App\\Http\\Controllers\\CustomApi', $config->controllerNamespace);
        $this->assertSame('custom/sdk', $config->sdkName);

        // Remaining values default
        $this->assertSame('App\\Models\\Services', $config->serviceNamespace);
        $this->assertSame('App\\Http\\Requests', $config->requestNamespace);
        $this->assertSame('MyApp\\Sdk', $config->sdkNamespace);
    }

    // ─── Directory and path helpers ─────────────────────────────

    public function test_controller_directory_converts_namespace_to_path(): void
    {
        $config = ApiConfig::fromArray('default', []);

        $this->assertSame('app/Http/Controllers/Api', $config->controllerDirectory());
    }

    public function test_controller_directory_for_custom_namespace(): void
    {
        $config = ApiConfig::fromArray('partner', [
            'namespaces' => [
                'controller' => 'App\\Http\\Controllers\\PartnerApi',
            ],
        ]);

        $this->assertSame('app/Http/Controllers/PartnerApi', $config->controllerDirectory());
    }

    public function test_controller_path_returns_absolute_path(): void
    {
        $config = ApiConfig::fromArray('default', []);

        $path = $config->controllerPath('Post');

        $this->assertStringEndsWith('app/Http/Controllers/Api/PostController.php', $path);
    }

    public function test_service_directory(): void
    {
        $config = ApiConfig::fromArray('default', []);

        $this->assertSame('app/Models/Services', $config->serviceDirectory());
    }

    public function test_service_path_returns_absolute_path(): void
    {
        $config = ApiConfig::fromArray('default', []);

        $path = $config->servicePath('Post');

        $this->assertStringEndsWith('app/Models/Services/PostService.php', $path);
    }

    public function test_request_directory(): void
    {
        $config = ApiConfig::fromArray('default', []);

        $this->assertSame('app/Http/Requests', $config->requestDirectory());
    }

    public function test_resource_directory(): void
    {
        $config = ApiConfig::fromArray('default', []);

        $this->assertSame('app/Resources', $config->resourceDirectory());
    }

    public function test_resource_directory_for_custom_namespace(): void
    {
        $config = ApiConfig::fromArray('partner', [
            'namespaces' => [
                'resource' => 'App\\Resources\\PartnerApi',
            ],
        ]);

        $this->assertSame('app/Resources/PartnerApi', $config->resourceDirectory());
    }
}
