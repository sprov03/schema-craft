<?php

namespace SchemaCraft\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use SchemaCraft\Config\ApiConfig;
use SchemaCraft\Config\ConfigResolver;
use SchemaCraft\Generator\Api\ApiCodeGenerator;
use SchemaCraft\Generator\Api\ApiFileWriter;
use SchemaCraft\Generator\Api\GeneratedFile;
use SchemaCraft\Generator\Api\ResourceGenerator;
use SchemaCraft\Generator\ControllerTestGenerator;
use SchemaCraft\Generator\DependencyResolver;
use SchemaCraft\Generator\FactoryGenerator;
use SchemaCraft\Generator\ModelTestGenerator;
use SchemaCraft\Scanner\SchemaScanner;
use SchemaCraft\Scanner\TableDefinition;

class GenerateApiCommand extends Command
{
    protected $signature = 'schema:generate
        {schema : The schema class name (e.g., PostSchema or App\\Schemas\\PostSchema)}
        {--action= : Add a new action to an existing API (e.g., --action=cancel)}
        {--api= : API configuration name from config/schema-craft.php}
        {--no-factory : Skip factory generation}
        {--no-test : Skip test generation}
        {--force : Overwrite existing files}';

    protected $description = 'Generate a full API stack (controller, service, requests, resource) from a schema class';

    public function handle(Filesystem $files): int
    {
        $apiConfig = ConfigResolver::resolve($this->option('api'));
        $schemaClass = $this->resolveSchemaClass($this->argument('schema'), $apiConfig);

        if (! class_exists($schemaClass)) {
            $this->components->error("Schema class [{$schemaClass}] not found.");

            return self::FAILURE;
        }

        $modelName = $this->resolveModelName($schemaClass);

        if ($this->option('action')) {
            return $this->handleAction($files, $modelName, $apiConfig);
        }

        return $this->handleGenerate($files, $schemaClass, $modelName, $apiConfig);
    }

    private function handleGenerate(Filesystem $files, string $schemaClass, string $modelName, ApiConfig $apiConfig): int
    {
        $scanner = new SchemaScanner($schemaClass);
        $table = $scanner->scan();

        $stubsPath = $this->resolveStubsPath();
        $generator = new ApiCodeGenerator($stubsPath);

        $generatedFiles = $generator->generate(
            table: $table,
            modelName: $modelName,
            modelNamespace: $apiConfig->modelNamespace,
            controllerNamespace: $apiConfig->controllerNamespace,
            serviceNamespace: $apiConfig->serviceNamespace,
            requestNamespace: $apiConfig->requestNamespace,
            resourceNamespace: $apiConfig->resourceNamespace,
            schemaNamespace: $apiConfig->schemaNamespace,
        );

        // Resolve dependency schemas and generate their Resources
        $dependencyFiles = $this->resolveDependencyResources($table, $apiConfig->resourceNamespace);
        $generatedFiles = array_merge($generatedFiles, $dependencyFiles);

        // Generate factory
        if (! $this->option('no-factory')) {
            $factoryFile = $this->generateFactory($table, $modelName, $apiConfig->modelNamespace);
            $generatedFiles['factory'] = $factoryFile;
        }

        // Generate model test
        if (! $this->option('no-test')) {
            $modelTestFile = $this->generateModelTest($table, $modelName, $apiConfig->modelNamespace);
            $generatedFiles['model_test'] = $modelTestFile;
        }

        // Generate controller test
        if (! $this->option('no-test')) {
            $controllerTestFile = $this->generateControllerTest($table, $modelName, $apiConfig);
            $generatedFiles['controller_test'] = $controllerTestFile;
        }

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

    private function handleAction(Filesystem $files, string $modelName, ApiConfig $apiConfig): int
    {
        $actionName = $this->option('action');
        $controllerClass = $modelName.'Controller';
        $controllerPath = $apiConfig->controllerPath($modelName);
        $servicePath = $apiConfig->servicePath($modelName);

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
        $requestFqcn = "{$apiConfig->requestNamespace}\\{$requestClass}";

        // Generate action request
        $stubsPath = $this->resolveStubsPath();
        $generator = new ApiCodeGenerator($stubsPath);
        $requestFile = $generator->generateAction($actionName, $modelName, $apiConfig->requestNamespace);
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
     * Generate an Akceli-style static factory for the model.
     */
    private function generateFactory(TableDefinition $table, string $modelName, string $modelNamespace): GeneratedFile
    {
        $generator = new FactoryGenerator;
        $content = $generator->generate($table, $modelName, $modelNamespace);

        return new GeneratedFile(
            path: "database/factories/{$modelName}Factory.php",
            content: $content,
        );
    }

    /**
     * Generate a PHPUnit model relationship test.
     */
    private function generateModelTest(TableDefinition $table, string $modelName, string $modelNamespace): GeneratedFile
    {
        $generator = new ModelTestGenerator;
        $content = $generator->generate($table, $modelName, $modelNamespace);

        return new GeneratedFile(
            path: "tests/Unit/{$modelName}ModelTest.php",
            content: $content,
        );
    }

    /**
     * Generate a PHPUnit controller CRUD test.
     */
    private function generateControllerTest(TableDefinition $table, string $modelName, ApiConfig $apiConfig): GeneratedFile
    {
        $generator = new ControllerTestGenerator;
        $content = $generator->generate(
            $table,
            $modelName,
            $apiConfig->modelNamespace,
            routePrefix: $apiConfig->routePrefix,
        );

        // Determine test directory based on API name
        $testDir = $apiConfig->name === 'default'
            ? 'tests/Feature/Controllers'
            : 'tests/Feature/Controllers/'.ucfirst($apiConfig->name).'Api';

        return new GeneratedFile(
            path: "{$testDir}/{$modelName}ControllerTest.php",
            content: $content,
        );
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
        string $resourceNamespace,
    ): array {
        $resolver = new DependencyResolver;
        $deps = $resolver->resolveDependencies($table);
        $files = [];

        $resourceDir = str_replace('\\', '/', $resourceNamespace);
        if (str_starts_with($resourceDir, 'App/')) {
            $resourceDir = 'app/'.substr($resourceDir, 4);
        }

        foreach ($deps as $depModelName => $depTable) {
            $files["dependency_resource_{$depModelName}"] = new GeneratedFile(
                path: "{$resourceDir}/{$depModelName}Resource.php",
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

    private function resolveSchemaClass(string $input, ApiConfig $apiConfig): string
    {
        if (str_contains($input, '\\')) {
            return $input;
        }

        // Add Schema suffix if not present
        if (! str_ends_with($input, 'Schema')) {
            $input .= 'Schema';
        }

        return "{$apiConfig->schemaNamespace}\\{$input}";
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
