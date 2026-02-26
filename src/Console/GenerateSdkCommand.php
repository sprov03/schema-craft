<?php

namespace SchemaCraft\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use SchemaCraft\Config\ApiConfig;
use SchemaCraft\Config\ConfigResolver;
use SchemaCraft\Generator\DependencyResolver;
use SchemaCraft\Generator\Sdk\ControllerActionScanner;
use SchemaCraft\Generator\Sdk\SdkGenerator;
use SchemaCraft\Generator\Sdk\SdkSchemaContext;
use SchemaCraft\Migration\SchemaDiscovery;
use SchemaCraft\Scanner\SchemaScanner;

class GenerateSdkCommand extends Command
{
    protected $signature = 'schema:generate-sdk
        {--api= : API configuration name from config/schema-craft.php}
        {--all : Generate SDKs for all configured APIs}
        {--path= : Output directory for the SDK package (overrides config)}
        {--name= : Composer package name (overrides config)}
        {--namespace= : PHP namespace for the SDK (overrides config)}
        {--client= : Client class name (overrides config)}
        {--sdk-version= : SDK package version (overrides config)}
        {--schema-path=* : Directories to scan for schema classes}
        {--force : Overwrite existing files}';

    protected $description = 'Generate an API client SDK package from schema classes';

    public function handle(Filesystem $files): int
    {
        if ($this->option('all')) {
            return $this->handleAll($files);
        }

        $apiConfig = ConfigResolver::resolve($this->option('api'));

        return $this->generateForApi($files, $apiConfig);
    }

    private function handleAll(Filesystem $files): int
    {
        $apiNames = ConfigResolver::allApiNames();

        $this->components->info('Generating SDKs for '.count($apiNames).' API(s): '.implode(', ', $apiNames));

        $failed = false;
        foreach ($apiNames as $apiName) {
            $this->components->info("--- Generating SDK for [{$apiName}] ---");
            $apiConfig = ConfigResolver::resolve($apiName);
            $result = $this->generateForApi($files, $apiConfig);

            if ($result !== self::SUCCESS) {
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function generateForApi(Filesystem $files, ApiConfig $apiConfig): int
    {
        $schemaDirectories = $this->getSchemaDirectories();
        $discovery = new SchemaDiscovery;
        $actionScanner = new ControllerActionScanner;

        $schemaClasses = $discovery->discover($schemaDirectories);

        if (empty($schemaClasses)) {
            $this->components->error('No schema classes found.');

            return self::FAILURE;
        }

        // Filter schemas if the API config specifies specific schemas
        if ($apiConfig->schemas !== null) {
            $schemaClasses = array_filter($schemaClasses, function (string $schemaClass) use ($apiConfig) {
                $className = class_basename($schemaClass);

                return in_array($className, $apiConfig->schemas);
            });

            if (empty($schemaClasses)) {
                $this->components->error('No matching schema classes found for configured schemas filter.');

                return self::FAILURE;
            }
        }

        // Build SDK contexts for schemas that have API controllers
        $schemas = $this->buildSchemaContexts($schemaClasses, $actionScanner, $files, $apiConfig);

        if (empty($schemas)) {
            $this->components->error('No schemas with generated API controllers found. Run schema:generate first.');

            return self::FAILURE;
        }

        $this->components->info('Found '.count($schemas).' API schema(s): '.implode(', ', array_keys($schemas)));

        // Resolve dependency schemas (related models that need Data DTOs)
        $schemas = $this->resolveDependencySchemas($schemas);

        // Resolve values: CLI options override config
        $sdkPath = $this->option('path') ?? $apiConfig->sdkPath;
        $sdkName = $this->option('name') ?? $apiConfig->sdkName;
        $sdkNamespace = $this->option('namespace') ?? $apiConfig->sdkNamespace;
        $sdkClient = $this->option('client') ?? $apiConfig->sdkClient;
        $sdkVersion = $this->option('sdk-version') ?? $apiConfig->sdkVersion;

        // Generate SDK files
        $stubsPath = $this->resolveStubsPath();
        $generator = new SdkGenerator;

        $generatedFiles = $generator->generate(
            schemas: $schemas,
            packageName: $sdkName,
            namespace: $sdkNamespace,
            clientClassName: $sdkClient,
            stubsPath: $stubsPath,
            version: $sdkVersion,
        );

        // Write files
        $outputPath = base_path($sdkPath);
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
        ApiConfig $apiConfig,
    ): array {
        $schemas = [];

        foreach ($schemaClasses as $schemaClass) {
            $modelName = $this->resolveModelName($schemaClass);
            $controllerPath = $apiConfig->controllerPath($modelName);

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

    /**
     * Resolve dependency schemas for all primary schemas.
     *
     * Walks the relationship tree to find models referenced by child
     * relationships (HasMany, HasOne, etc.) that need Data DTOs in the SDK.
     *
     * @param  array<string, SdkSchemaContext>  $schemas
     * @return array<string, SdkSchemaContext>
     */
    private function resolveDependencySchemas(array $schemas): array
    {
        $resolver = new DependencyResolver;
        $dependencySchemas = [];

        foreach ($schemas as $modelName => $context) {
            $deps = $resolver->resolveDependencies($context->table);

            foreach ($deps as $depModelName => $depTable) {
                if (! isset($schemas[$depModelName]) && ! isset($dependencySchemas[$depModelName])) {
                    $dependencySchemas[$depModelName] = new SdkSchemaContext(
                        table: $depTable,
                        isDependencyOnly: true,
                    );
                }
            }

            foreach ($resolver->getWarnings() as $warning) {
                $this->components->warn($warning);
            }
        }

        if (! empty($dependencySchemas)) {
            $this->components->info('Resolved '.count($dependencySchemas).' dependency schema(s): '.implode(', ', array_keys($dependencySchemas)));
        }

        return array_merge($schemas, $dependencySchemas);
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

        return ConfigResolver::schemaDirectories();
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
