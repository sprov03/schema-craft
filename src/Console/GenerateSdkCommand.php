<?php

namespace SchemaCraft\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use SchemaCraft\Generator\Sdk\ControllerActionScanner;
use SchemaCraft\Generator\Sdk\SdkGenerator;
use SchemaCraft\Generator\Sdk\SdkSchemaContext;
use SchemaCraft\Migration\SchemaDiscovery;
use SchemaCraft\Scanner\SchemaScanner;

class GenerateSdkCommand extends Command
{
    protected $signature = 'schema:generate-sdk
        {--path=packages/sdk : Output directory for the SDK package}
        {--name=my-app/sdk : Composer package name}
        {--namespace=MyApp\\Sdk : PHP namespace for the SDK}
        {--client=MyAppClient : Client class name}
        {--schema-path=* : Directories to scan for schema classes}
        {--force : Overwrite existing files}';

    protected $description = 'Generate an API client SDK package from schema classes';

    public function handle(Filesystem $files): int
    {
        $schemaDirectories = $this->getSchemaDirectories();
        $discovery = new SchemaDiscovery;
        $actionScanner = new ControllerActionScanner;

        $schemaClasses = $discovery->discover($schemaDirectories);

        if (empty($schemaClasses)) {
            $this->components->error('No schema classes found.');

            return self::FAILURE;
        }

        // Build SDK contexts for schemas that have API controllers
        $schemas = $this->buildSchemaContexts($schemaClasses, $actionScanner, $files);

        if (empty($schemas)) {
            $this->components->error('No schemas with generated API controllers found. Run schema:generate first.');

            return self::FAILURE;
        }

        $this->components->info('Found '.count($schemas).' API schema(s): '.implode(', ', array_keys($schemas)));

        // Generate SDK files
        $stubsPath = $this->resolveStubsPath();
        $generator = new SdkGenerator;

        $generatedFiles = $generator->generate(
            schemas: $schemas,
            packageName: $this->option('name'),
            namespace: $this->option('namespace'),
            clientClassName: $this->option('client'),
            stubsPath: $stubsPath,
        );

        // Write files
        $outputPath = base_path($this->option('path'));
        $hasSkipped = false;

        foreach ($generatedFiles as $file) {
            $absolutePath = $outputPath.'/'.$file->path;

            if ($files->exists($absolutePath) && ! $this->option('force')) {
                $this->components->warn("File [{$absolutePath}] already exists. Use --force to overwrite.");
                $hasSkipped = true;

                continue;
            }

            $files->ensureDirectoryExists(dirname($absolutePath));
            $files->put($absolutePath, $file->content);
            $this->components->info("Created [{$absolutePath}]");
        }

        if ($hasSkipped) {
            $this->components->warn('Some files were skipped. Use --force to overwrite existing files.');
        }

        $this->components->info("SDK package generated at [{$outputPath}]");

        return self::SUCCESS;
    }

    /**
     * Build SdkSchemaContext for each schema that has an API controller.
     *
     * @param  class-string[]  $schemaClasses
     * @return array<string, SdkSchemaContext>
     */
    private function buildSchemaContexts(
        array $schemaClasses,
        ControllerActionScanner $actionScanner,
        Filesystem $files,
    ): array {
        $schemas = [];

        foreach ($schemaClasses as $schemaClass) {
            $modelName = $this->resolveModelName($schemaClass);
            $controllerPath = app_path("Http/Controllers/Api/{$modelName}Controller.php");

            if (! $files->exists($controllerPath)) {
                continue;
            }

            $scanner = new SchemaScanner($schemaClass);
            $table = $scanner->scan();

            $customActions = $actionScanner->scanFile($controllerPath);

            $schemas[$modelName] = new SdkSchemaContext(
                table: $table,
                customActions: $customActions,
            );
        }

        return $schemas;
    }

    private function resolveModelName(string $schemaClass): string
    {
        $className = class_basename($schemaClass);

        return Str::beforeLast($className, 'Schema') ?: $className;
    }

    /**
     * @return string[]
     */
    private function getSchemaDirectories(): array
    {
        $paths = $this->option('schema-path');

        if (! empty($paths)) {
            return $paths;
        }

        return [app_path('Schemas')];
    }

    /**
     * Resolve the stubs path, preferring published stubs over package defaults.
     */
    private function resolveStubsPath(): string
    {
        $publishedPath = base_path('stubs/schema-craft');

        if (is_dir($publishedPath.'/sdk')) {
            return $publishedPath;
        }

        return dirname(__DIR__).'/Console/stubs';
    }
}
