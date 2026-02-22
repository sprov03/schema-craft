<?php

namespace SchemaCraft\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use SchemaCraft\Generator\Api\ApiCodeGenerator;
use SchemaCraft\Generator\Api\ApiFileWriter;
use SchemaCraft\Generator\Api\GeneratedFile;
use SchemaCraft\Generator\Api\ResourceGenerator;
use SchemaCraft\Generator\DependencyResolver;
use SchemaCraft\Scanner\SchemaScanner;
use SchemaCraft\Scanner\TableDefinition;

class GenerateApiCommand extends Command
{
    protected $signature = 'schema:generate
        {schema : The schema class name (e.g., PostSchema or App\\Schemas\\PostSchema)}
        {--action= : Add a new action to an existing API (e.g., --action=cancel)}
        {--force : Overwrite existing files}';

    protected $description = 'Generate a full API stack (controller, service, requests, resource) from a schema class';

    public function handle(Filesystem $files): int
    {
        $schemaClass = $this->resolveSchemaClass($this->argument('schema'));

        if (! class_exists($schemaClass)) {
            $this->components->error("Schema class [{$schemaClass}] not found.");

            return self::FAILURE;
        }

        $modelName = $this->resolveModelName($schemaClass);

        if ($this->option('action')) {
            return $this->handleAction($files, $modelName);
        }

        return $this->handleGenerate($files, $schemaClass, $modelName);
    }

    private function handleGenerate(Filesystem $files, string $schemaClass, string $modelName): int
    {
        $scanner = new SchemaScanner($schemaClass);
        $table = $scanner->scan();

        $stubsPath = $this->resolveStubsPath();
        $generator = new ApiCodeGenerator($stubsPath);

        $generatedFiles = $generator->generate($table, $modelName);

        // Resolve dependency schemas and generate their Resources
        $dependencyFiles = $this->resolveDependencyResources($table);
        $generatedFiles = array_merge($generatedFiles, $dependencyFiles);

        $hasSkipped = false;

        foreach ($generatedFiles as $key => $file) {
            $absolutePath = base_path($file->path);

            if ($files->exists($absolutePath) && ! $this->option('force')) {
                $this->components->warn("File [{$absolutePath}] already exists. Use --force to overwrite.");
                $hasSkipped = true;

                continue;
            }

            $files->ensureDirectoryExists(dirname($absolutePath));
            $files->put($absolutePath, $file->content);

            $label = str_starts_with($key, 'dependency_resource_') ? ' (dependency)' : '';
            $this->components->info("Created [{$absolutePath}]{$label}");
        }

        if ($hasSkipped) {
            $this->components->warn('Some files were skipped. Use --force to overwrite existing files.');
        }

        $this->components->info("API stack for [{$modelName}] generated successfully.");

        return self::SUCCESS;
    }

    private function handleAction(Filesystem $files, string $modelName): int
    {
        $actionName = $this->option('action');
        $controllerClass = $modelName.'Controller';
        $controllerPath = app_path("Http/Controllers/Api/{$controllerClass}.php");
        $servicePath = app_path("Models/Services/{$modelName}Service.php");

        if (! $files->exists($controllerPath)) {
            $this->components->error("Controller [{$controllerPath}] not found. Generate the API first.");

            return self::FAILURE;
        }

        if (! $files->exists($servicePath)) {
            $this->components->error("Service [{$servicePath}] not found. Generate the API first.");

            return self::FAILURE;
        }

        $writer = new ApiFileWriter;
        $modelVariable = Str::camel($modelName);
        $routePrefix = Str::snake(Str::pluralStudly($modelName), '-');
        $routeParam = $modelVariable;
        $requestClass = ucfirst($actionName).$modelName.'Request';
        $requestFqcn = "App\\Http\\Requests\\{$requestClass}";

        // Generate action request
        $stubsPath = $this->resolveStubsPath();
        $generator = new ApiCodeGenerator($stubsPath);
        $requestFile = $generator->generateAction($actionName, $modelName);
        $requestAbsolutePath = base_path($requestFile->path);
        $files->ensureDirectoryExists(dirname($requestAbsolutePath));
        $files->put($requestAbsolutePath, $requestFile->content);
        $this->components->info("Created [{$requestAbsolutePath}]");

        // Update controller: add import, route, and method
        $controllerContent = $files->get($controllerPath);
        $controllerContent = $writer->addImport($controllerContent, $requestFqcn);
        $controllerContent = $writer->addRoute(
            $controllerContent,
            'put',
            $routePrefix,
            $actionName,
            $controllerClass,
            $routeParam,
        );
        $controllerContent = $writer->addControllerMethod(
            $controllerContent,
            $actionName,
            $modelName,
            $modelVariable,
            $requestClass,
        );
        $files->put($controllerPath, $controllerContent);
        $this->components->info("Updated [{$controllerPath}] with [{$actionName}] action.");

        // Update service: add method
        $serviceContent = $files->get($servicePath);
        $serviceContent = $writer->addServiceMethod(
            $serviceContent,
            $actionName,
            $modelName,
            $modelVariable,
        );
        $files->put($servicePath, $serviceContent);
        $this->components->info("Updated [{$servicePath}] with [{$actionName}] method.");

        return self::SUCCESS;
    }

    /**
     * Resolve dependency schemas and generate Resource-only files for them.
     *
     * Walks the relationship tree to find models referenced by child
     * relationships that need Resource classes in the API layer.
     *
     * @return array<string, GeneratedFile>
     */
    private function resolveDependencyResources(
        TableDefinition $table,
        string $resourceNamespace = 'App\\Resources',
    ): array {
        $resolver = new DependencyResolver;
        $deps = $resolver->resolveDependencies($table);
        $files = [];

        foreach ($deps as $depModelName => $depTable) {
            $files["dependency_resource_{$depModelName}"] = new GeneratedFile(
                path: "app/Resources/{$depModelName}Resource.php",
                content: (new ResourceGenerator)->generate($depTable, $resourceNamespace),
            );
        }

        foreach ($resolver->getWarnings() as $warning) {
            $this->components->warn($warning);
        }

        if (! empty($deps)) {
            $this->components->info('Resolved '.count($deps).' dependency resource(s): '.implode(', ', array_keys($deps)));
        }

        return $files;
    }

    private function resolveSchemaClass(string $input): string
    {
        if (str_contains($input, '\\')) {
            return $input;
        }

        // Add Schema suffix if not present
        if (! str_ends_with($input, 'Schema')) {
            $input .= 'Schema';
        }

        return "App\\Schemas\\{$input}";
    }

    private function resolveModelName(string $schemaClass): string
    {
        $className = class_basename($schemaClass);

        return Str::beforeLast($className, 'Schema') ?: $className;
    }

    /**
     * Resolve the stubs path, preferring published stubs over package defaults.
     */
    private function resolveStubsPath(): string
    {
        $publishedPath = base_path('stubs/schema-craft');

        if (is_dir($publishedPath.'/api')) {
            return $publishedPath;
        }

        return dirname(__DIR__).'/Console/stubs';
    }
}
