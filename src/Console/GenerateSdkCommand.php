<?php

namespace SchemaCraft\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use SchemaCraft\Config\ApiConfig;
use SchemaCraft\Config\ConfigResolver;
use SchemaCraft\Generator\Sdk\SdkContextBuilder;
use SchemaCraft\Generator\Sdk\SdkGenerator;
use SchemaCraft\Generator\StubResolver;
use SchemaCraft\Migration\SchemaDiscovery;

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

        // Build SDK contexts via the shared SdkContextBuilder — the exact same pipeline the
        // visualizer uses, so the CLI-generated SDK is byte-for-byte identical to the GUI one.
        $result = (new SdkContextBuilder)->build($apiConfig, $schemaClasses);

        // Surface every collected warning/error in console idiom. Warnings are non-fatal
        // (e.g. an undocumented endpoint excluded from the SDK); errors flag misconfigured
        // resources the author should fix, but generation still proceeds for the rest.
        foreach ($result->warnings as $warning) {
            $this->components->warn($warning['message']);
        }

        foreach ($result->errors as $error) {
            $this->components->error($error['message']);
        }

        $schemas = $result->schemas;

        if (empty($schemas)) {
            $this->components->error('No schemas with registered API routes found. Ensure your routes are registered and the route prefix matches your API config.');

            return self::FAILURE;
        }

        $this->components->info('Found '.count($schemas).' API schema(s): '.implode(', ', array_keys($schemas)));

        // Resolve values: CLI options override config
        $sdkPath = $this->option('path') ?? $apiConfig->sdkPath;
        $sdkName = $this->option('name') ?? $apiConfig->sdkName;
        $sdkNamespace = $this->option('namespace') ?? $apiConfig->sdkNamespace;
        $sdkClient = $this->option('client') ?? $apiConfig->sdkClient;
        $sdkVersion = $this->option('sdk-version') ?? $apiConfig->sdkVersion;

        // Generate SDK files
        $stubsPath = StubResolver::basePath();
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
}
