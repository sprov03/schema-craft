<?php

namespace SchemaCraft\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class InstallCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'schema-craft:install';

    /**
     * @var string
     */
    protected $description = 'Install SchemaCraft by publishing the BaseModel class';

    public function handle(Filesystem $files): int
    {
        $path = app_path('Models/BaseModel.php');

        if ($files->exists($path)) {
            $this->components->warn('BaseModel already exists. Skipping.');

            return self::SUCCESS;
        }

        $stub = $files->get($this->resolveStubPath('base-model.stub'));

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $stub);

        $this->components->info("BaseModel [{$path}] created successfully.");

        return self::SUCCESS;
    }

    /**
     * Resolve a stub file path, preferring published stubs over package defaults.
     */
    private function resolveStubPath(string $filename): string
    {
        $publishedPath = base_path("stubs/schema-craft/{$filename}");

        if (file_exists($publishedPath)) {
            return $publishedPath;
        }

        return __DIR__."/stubs/{$filename}";
    }
}
