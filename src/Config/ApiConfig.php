<?php

namespace SchemaCraft\Config;

/**
 * Value object representing the configuration for a single API.
 */
class ApiConfig
{
    public function __construct(
        public string $name,
        public string $controllerNamespace,
        public string $serviceNamespace,
        public string $requestNamespace,
        public string $resourceNamespace,
        public string $schemaNamespace,
        public string $modelNamespace,
        public string $routeFile,
        public string $routePrefix,
        /** @var string[] */
        public array $routeMiddleware,
        /** @var string[]|null */
        public ?array $schemas,
        public string $sdkPath,
        public string $sdkName,
        public string $sdkNamespace,
        public string $sdkClient,
        public string $sdkVersion,
    ) {}

    /**
     * Create an ApiConfig from a raw config array.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(string $name, array $config): self
    {
        $namespaces = $config['namespaces'] ?? [];
        $routes = $config['routes'] ?? [];
        $sdk = $config['sdk'] ?? [];

        return new self(
            name: $name,
            controllerNamespace: $namespaces['controller'] ?? 'App\\Http\\Controllers\\Api',
            serviceNamespace: $namespaces['service'] ?? 'App\\Models\\Services',
            requestNamespace: $namespaces['request'] ?? 'App\\Http\\Requests',
            resourceNamespace: $namespaces['resource'] ?? 'App\\Resources',
            schemaNamespace: $namespaces['schema'] ?? 'App\\Schemas',
            modelNamespace: $namespaces['model'] ?? 'App\\Models',
            routeFile: $routes['file'] ?? 'routes/api.php',
            routePrefix: $routes['prefix'] ?? 'api',
            routeMiddleware: $routes['middleware'] ?? ['auth:sanctum'],
            schemas: $config['schemas'] ?? null,
            sdkPath: $sdk['path'] ?? 'packages/sdk',
            sdkName: $sdk['name'] ?? 'my-app/sdk',
            sdkNamespace: $sdk['namespace'] ?? 'MyApp\\Sdk',
            sdkClient: $sdk['client'] ?? 'MyAppClient',
            sdkVersion: $sdk['version'] ?? '0.1.0',
        );
    }

    /**
     * Get the controller directory path relative to base_path().
     *
     * e.g., App\Http\Controllers\Api → app/Http/Controllers/Api
     */
    public function controllerDirectory(): string
    {
        return $this->namespaceToDirectory($this->controllerNamespace);
    }

    /**
     * Get the absolute path to a specific controller file.
     */
    public function controllerPath(string $modelName): string
    {
        return base_path($this->controllerDirectory().'/'.$modelName.'Controller.php');
    }

    /**
     * Get the service directory path relative to base_path().
     */
    public function serviceDirectory(): string
    {
        return $this->namespaceToDirectory($this->serviceNamespace);
    }

    /**
     * Get the absolute path to a specific service file.
     */
    public function servicePath(string $modelName): string
    {
        return base_path($this->serviceDirectory().'/'.$modelName.'Service.php');
    }

    /**
     * Get the request directory path relative to base_path().
     */
    public function requestDirectory(): string
    {
        return $this->namespaceToDirectory($this->requestNamespace);
    }

    /**
     * Get the resource directory path relative to base_path().
     */
    public function resourceDirectory(): string
    {
        return $this->namespaceToDirectory($this->resourceNamespace);
    }

    /**
     * Get the test directory path relative to base_path().
     */
    public function testDirectory(): string
    {
        return $this->name === 'default'
            ? 'tests/Feature/Controllers'
            : 'tests/Feature/Controllers/'.ucfirst($this->name).'Api';
    }

    /**
     * Get the absolute path to a specific controller test file.
     */
    public function testPath(string $modelName): string
    {
        return base_path($this->testDirectory().'/'.$modelName.'ControllerTest.php');
    }

    /**
     * Convert a namespace to a directory path relative to base_path().
     *
     * App\Http\Controllers\Api → app/Http/Controllers/Api
     */
    private function namespaceToDirectory(string $namespace): string
    {
        $path = str_replace('\\', '/', $namespace);

        if (str_starts_with($path, 'App/')) {
            $path = 'app/'.substr($path, 4);
        }

        return $path;
    }
}
