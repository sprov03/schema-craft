<?php

namespace SchemaCraft\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use SchemaCraft\Generator\Filament\FilamentCodeGenerator;
use SchemaCraft\Generator\Filament\FilamentPolicyGenerator;
use SchemaCraft\Scanner\SchemaScanner;

class GenerateFilamentCommand extends Command
{
    protected $signature = 'schema:filament
        {schema? : The schema class name (e.g., PostSchema or App\\Schemas\\PostSchema)}
        {--force : Overwrite existing files}
        {--all : Generate for all discovered schemas}
        {--with-policy : Also generate a policy class}
        {--resource-namespace=App\\Filament\\Resources : Filament resource namespace}
        {--model-namespace=App\\Models : Model namespace}
        {--policy-namespace=App\\Policies : Policy namespace}';

    protected $description = 'Generate Filament v5 resource files (resource, pages, relation managers) from a schema class';

    public function handle(Filesystem $files): int
    {
        if ($this->option('all')) {
            return $this->handleAll($files);
        }

        if (! $this->argument('schema')) {
            $this->components->error('Please provide a schema class name or use --all.');

            return self::FAILURE;
        }

        $schemaClass = $this->resolveSchemaClass($this->argument('schema'));

        if (! class_exists($schemaClass)) {
            $this->components->error("Schema class [{$schemaClass}] not found.");

            return self::FAILURE;
        }

        return $this->generateForSchema($files, $schemaClass);
    }

    private function handleAll(Filesystem $files): int
    {
        $schemas = $this->discoverSchemas();

        if (empty($schemas)) {
            $this->components->warn('No schema classes found.');

            return self::FAILURE;
        }

        $this->components->info('Generating Filament resources for '.count($schemas).' schema(s)...');

        $failed = false;
        foreach ($schemas as $schemaClass) {
            $result = $this->generateForSchema($files, $schemaClass);
            if ($result === self::FAILURE) {
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function generateForSchema(Filesystem $files, string $schemaClass): int
    {
        $modelName = $this->resolveModelName($schemaClass);

        $scanner = new SchemaScanner($schemaClass);
        $table = $scanner->scan();

        $stubsPath = $this->resolveStubsPath();
        $generator = new FilamentCodeGenerator($stubsPath);

        $resourceNamespace = $this->option('resource-namespace');
        $modelNamespace = $this->option('model-namespace');

        $generatedFiles = $generator->generate(
            table: $table,
            modelName: $modelName,
            modelNamespace: $modelNamespace,
            resourceNamespace: $resourceNamespace,
        );

        // Optionally generate policy
        if ($this->option('with-policy')) {
            $policyGenerator = new FilamentPolicyGenerator($stubsPath);
            $generatedFiles['policy'] = $policyGenerator->generate(
                modelName: $modelName,
                modelNamespace: $modelNamespace,
                policyNamespace: $this->option('policy-namespace'),
            );
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

            $this->components->info("Created [{$absolutePath}]");
        }

        if ($hasSkipped) {
            $this->components->warn('Some files were skipped. Use --force to overwrite existing files.');
        }

        $this->components->info("Filament resource for [{$modelName}] generated successfully.");

        return self::SUCCESS;
    }

    /**
     * Discover all schema classes in the app's schema namespace.
     *
     * @return string[]
     */
    private function discoverSchemas(): array
    {
        $schemaNamespace = 'App\\Schemas';
        $schemaDir = app_path('Schemas');

        if (! is_dir($schemaDir)) {
            return [];
        }

        $schemas = [];
        $files = glob($schemaDir.'/*Schema.php');

        foreach ($files as $file) {
            $className = pathinfo($file, PATHINFO_FILENAME);
            $fqcn = $schemaNamespace.'\\'.$className;

            if (class_exists($fqcn) && is_subclass_of($fqcn, \SchemaCraft\Schema::class)) {
                $schemas[] = $fqcn;
            }
        }

        return $schemas;
    }

    private function resolveSchemaClass(string $input): string
    {
        if (str_contains($input, '\\')) {
            return $input;
        }

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

    private function resolveStubsPath(): string
    {
        $publishedPath = base_path('stubs/schema-craft');

        if (is_dir($publishedPath.'/filament')) {
            return $publishedPath;
        }

        return dirname(__DIR__).'/Console/stubs';
    }
}
