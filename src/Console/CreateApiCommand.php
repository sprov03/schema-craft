<?php

namespace SchemaCraft\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class CreateApiCommand extends Command
{
    protected $signature = 'schema:api:create
        {name : The API name (e.g., partner, internal)}
        {--prefix= : URL prefix for the API routes}
        {--setup-sanctum : Install and configure Laravel Sanctum}';

    protected $description = 'Create a new API configuration with isolated directories and route file';

    public function handle(Filesystem $files): int
    {
        $name = $this->argument('name');
        $studlyName = Str::studly($name);
        $prefix = $this->option('prefix') ?? Str::kebab($name).'-api';

        // Validate name doesn't conflict
        $existingApis = config('schema-craft.apis', []);
        if (isset($existingApis[$name])) {
            $this->components->error("API configuration [{$name}] already exists.");

            return self::FAILURE;
        }

        // Ensure config file exists
        $configPath = config_path('schema-craft.php');
        if (! $files->exists($configPath)) {
            $this->call('vendor:publish', ['--tag' => 'schema-craft-config', '--no-interaction' => true]);
            $this->components->info('Published schema-craft config file.');
        }

        // Setup Sanctum if requested
        if ($this->option('setup-sanctum')) {
            $result = $this->setupSanctum($files);
            if ($result === self::FAILURE) {
                return self::FAILURE;
            }
        }

        // Create route file
        $routeFile = "routes/{$name}-api.php";
        $routeAbsolutePath = base_path($routeFile);

        if (! $files->exists($routeAbsolutePath)) {
            $stubsPath = $this->resolveStubsPath();
            $stub = file_get_contents($stubsPath.'/api/route-file.stub');

            $content = str_replace(
                ['{{ apiName }}', '{{ prefix }}'],
                [$studlyName, $prefix],
                $stub,
            );

            $files->ensureDirectoryExists(dirname($routeAbsolutePath));
            $files->put($routeAbsolutePath, $content);
            $this->components->info("Created route file [{$routeAbsolutePath}]");
        }

        // Create isolated directories
        $directories = [
            app_path("Http/Controllers/{$studlyName}Api"),
            app_path("Http/Requests/{$studlyName}Api"),
            app_path("Resources/{$studlyName}Api"),
        ];

        foreach ($directories as $dir) {
            if (! is_dir($dir)) {
                $files->makeDirectory($dir, 0755, true);
                $files->put($dir.'/.gitkeep', '');
                $this->components->info("Created directory [{$dir}]");
            }
        }

        // Add the API entry to the config file
        $this->addApiToConfig(
            $files,
            $configPath,
            $name,
            $studlyName,
            $routeFile,
            $prefix,
        );

        // Register route in bootstrap/app.php
        $this->registerRouteInBootstrap($files, $routeFile, $prefix, $name);

        $this->components->info("API [{$name}] created successfully.");
        $this->newLine();
        $this->components->info('Next steps:');
        $this->components->bulletList([
            "Generate schemas: <comment>php artisan schema:generate PostSchema --api={$name}</comment>",
            "Generate SDK: <comment>php artisan schema:generate-sdk --api={$name}</comment>",
        ]);

        return self::SUCCESS;
    }

    private function setupSanctum(Filesystem $files): int
    {
        $composerJsonPath = base_path('composer.json');

        if (! $files->exists($composerJsonPath)) {
            $this->components->error('composer.json not found.');

            return self::FAILURE;
        }

        $composerJson = json_decode($files->get($composerJsonPath), true);
        $require = $composerJson['require'] ?? [];

        if (! isset($require['laravel/sanctum'])) {
            $this->components->info('Installing Sanctum via install:api...');
            $this->call('install:api', ['--no-interaction' => true]);
            $this->components->info('Sanctum installed successfully.');
        } else {
            $this->components->info('Sanctum is already installed.');
        }

        return self::SUCCESS;
    }

    /**
     * Add a new API entry to the config file.
     */
    private function addApiToConfig(
        Filesystem $files,
        string $configPath,
        string $name,
        string $studlyName,
        string $routeFile,
        string $prefix,
    ): void {
        $configContent = $files->get($configPath);

        // Build the new API entry
        $entry = $this->buildConfigEntry($name, $studlyName, $routeFile, $prefix);

        // Find the closing of the 'apis' array and insert before it
        // Look for the last "]," that closes an API entry, then the final "]" closing the apis array
        $lastBracketPos = strrpos($configContent, "    ],\n\n];");

        if ($lastBracketPos !== false) {
            // Insert the new entry after the last API entry's closing bracket
            $insertPos = $lastBracketPos + strlen("    ],\n");
            $configContent = substr($configContent, 0, $insertPos)."\n".$entry."\n".substr($configContent, $insertPos);
        }

        $files->put($configPath, $configContent);
        $this->components->info("Added [{$name}] API to config/schema-craft.php");
    }

    /**
     * Build the PHP array string for a new API config entry.
     */
    private function buildConfigEntry(
        string $name,
        string $studlyName,
        string $routeFile,
        string $prefix,
    ): string {
        $sdkName = config('app.name', 'my-app');
        $sdkName = Str::kebab($sdkName).'/'.$name.'-sdk';
        $sdkNamespace = Str::studly(config('app.name', 'MyApp')).'\\'.Str::studly($name).'Sdk';
        $clientName = Str::studly($name).'Client';

        return <<<PHP
        '{$name}' => [
            'namespaces' => [
                'controller' => 'App\\\\Http\\\\Controllers\\\\{$studlyName}Api',
                'service'    => 'App\\\\Models\\\\Services',
                'request'    => 'App\\\\Http\\\\Requests\\\\{$studlyName}Api',
                'resource'   => 'App\\\\Resources\\\\{$studlyName}Api',
                'schema'     => 'App\\\\Schemas',
                'model'      => 'App\\\\Models',
            ],
            'routes' => [
                'file'       => '{$routeFile}',
                'prefix'     => '{$prefix}',
                'middleware'  => ['auth:sanctum'],
            ],
            'schemas' => null,
            'sdk' => [
                'path'      => 'packages/{$name}-sdk',
                'name'      => '{$sdkName}',
                'namespace' => '{$sdkNamespace}',
                'client'    => '{$clientName}',
                'version'   => '0.1.0',
            ],
        ],
PHP;
    }

    /**
     * Register the new route file in bootstrap/app.php.
     */
    private function registerRouteInBootstrap(
        Filesystem $files,
        string $routeFile,
        string $prefix,
        string $name,
    ): void {
        $bootstrapPath = base_path('bootstrap/app.php');

        if (! $files->exists($bootstrapPath)) {
            $this->components->warn('bootstrap/app.php not found. Skipping route registration.');

            return;
        }

        $content = $files->get($bootstrapPath);

        // Check if this route file is already registered
        if (str_contains($content, $routeFile)) {
            $this->components->info("Route file [{$routeFile}] is already registered in bootstrap/app.php");

            return;
        }

        // Find the ->withRouting() call and add a `then:` closure
        if (str_contains($content, '->withRouting(')) {
            $content = $this->addRouteToWithRouting($content, $routeFile, $prefix, $name);
            $files->put($bootstrapPath, $content);
            $this->components->info("Registered [{$routeFile}] in bootstrap/app.php");
        } else {
            $this->components->warn('Could not find withRouting() in bootstrap/app.php. Register the route manually.');
        }
    }

    /**
     * Add a route registration to the withRouting() call via a `then:` closure.
     */
    private function addRouteToWithRouting(string $content, string $routeFile, string $prefix, string $name): string
    {
        // If there's already a `then:` parameter, append to its closure body
        if (preg_match('/then:\s*function\s*\(\)\s*\{/s', $content, $matches, \PREG_OFFSET_CAPTURE)) {
            $openBracePos = $matches[0][1] + strlen($matches[0][0]);
            $routeRegistration = "\n            \\Illuminate\\Support\\Facades\\Route::middleware('auth:sanctum')\n"
                ."                ->prefix('{$prefix}')\n"
                ."                ->group(base_path('{$routeFile}'));\n";

            return substr($content, 0, $openBracePos).$routeRegistration.substr($content, $openBracePos);
        }

        // No existing `then:` — add one before the closing paren of withRouting()
        // Find `health: '/up',\n    )` or the last parameter before closing
        $thenClosure = "        then: function () {\n"
            ."            \\Illuminate\\Support\\Facades\\Route::middleware('auth:sanctum')\n"
            ."                ->prefix('{$prefix}')\n"
            ."                ->group(base_path('{$routeFile}'));\n"
            ."        },\n";

        // Look for the closing of withRouting: we find the last parameter line and insert after it
        if (preg_match("/(\s*health:\s*'[^']*',?\s*\n)/", $content, $matches, \PREG_OFFSET_CAPTURE)) {
            $insertPos = $matches[0][1] + strlen($matches[0][0]);

            return substr($content, 0, $insertPos).$thenClosure.substr($content, $insertPos);
        }

        // Fallback: look for closing paren of withRouting
        if (preg_match('/->withRouting\([^)]*\)/s', $content, $matches, \PREG_OFFSET_CAPTURE)) {
            $closingParenPos = $matches[0][1] + strlen($matches[0][0]) - 1;

            return substr($content, 0, $closingParenPos).$thenClosure.substr($content, $closingParenPos);
        }

        return $content;
    }

    private function resolveStubsPath(): string
    {
        $publishedPath = base_path('stubs/schema-craft');

        if (is_dir($publishedPath.'/api')) {
            return $publishedPath;
        }

        return dirname(__DIR__).'/Console/stubs';
    }
}
