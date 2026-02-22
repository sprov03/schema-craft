<?php

namespace SchemaCraft\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class MakeSchemaCommand extends Command
{
    protected $signature = 'make:schema
        {name* : One or more schema names}
        {--no-model : Do not create a model class}
        {--uuid : Use UUID primary key}
        {--ulid : Use ULID primary key}
        {--soft-deletes : Include soft deletes}';

    protected $description = 'Create a new SchemaCraft schema class';

    public function handle(Filesystem $files): int
    {
        $names = $this->argument('name');

        $idConfig = $this->resolveIdConfig();

        $softDeletes = $this->option('soft-deletes');

        if (! $this->option('no-model') && ! $files->exists(app_path('Models/BaseModel.php'))) {
            $this->components->warn('BaseModel not found. Run [php artisan schema-craft:install] to create it.');

            if ($this->input->isInteractive() && $this->components->confirm('Would you like to run it now?', true)) {
                $this->call('schema-craft:install');
            }
        }

        foreach ($names as $name) {
            $schemaName = $name.'Schema';

            $this->createSchema($files, $schemaName, $idConfig, $softDeletes);

            if (! $this->option('no-model')) {
                $this->createModel($files, $name, $schemaName, $softDeletes);
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array{imports: string, property: string}
     */
    private function resolveIdConfig(): array
    {
        if ($this->option('uuid')) {
            return [
                'imports' => "use SchemaCraft\\Attributes\\ColumnType;\nuse SchemaCraft\\Attributes\\Primary;",
                'property' => "    #[Primary]\n    #[ColumnType('uuid')]\n    public string \$id;",
            ];
        }

        if ($this->option('ulid')) {
            return [
                'imports' => "use SchemaCraft\\Attributes\\ColumnType;\nuse SchemaCraft\\Attributes\\Primary;",
                'property' => "    #[Primary]\n    #[ColumnType('ulid')]\n    public string \$id;",
            ];
        }

        return [
            'imports' => "use SchemaCraft\\Attributes\\AutoIncrement;\nuse SchemaCraft\\Attributes\\Primary;",
            'property' => "    #[Primary]\n    #[AutoIncrement]\n    public int \$id;",
        ];
    }

    /**
     * @param  array{imports: string, property: string}  $idConfig
     */
    private function createSchema(Filesystem $files, string $schemaName, array $idConfig, bool $softDeletes): void
    {
        $stub = $files->get(__DIR__.'/stubs/schema.stub');

        $content = str_replace(
            [
                '{{ namespace }}',
                '{{ class }}',
                '{{ idImports }}',
                '{{ idProperty }}',
                '{{ softDeletesImport }}',
                '{{ softDeletesTrait }}',
            ],
            [
                'App\\Schemas',
                $schemaName,
                $idConfig['imports'],
                $idConfig['property'],
                $softDeletes ? 'use SchemaCraft\\Traits\\SoftDeletesSchema;' : '',
                $softDeletes ? "    use SoftDeletesSchema;\n" : '',
            ],
            $stub,
        );

        $content = preg_replace('/\n{3,}/', "\n\n", $content);

        $path = app_path("Schemas/{$schemaName}.php");
        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $content);

        $this->components->info("Schema [{$path}] created successfully.");
    }

    private function createModel(Filesystem $files, string $name, string $schemaName, bool $softDeletes): void
    {
        $stub = $files->get(__DIR__.'/stubs/model.stub');

        $schemaFqcn = "App\\Schemas\\{$schemaName}";

        $content = str_replace(
            [
                '{{ namespace }}',
                '{{ class }}',
                '{{ schemaFqcn }}',
                '{{ schemaClass }}',
                '{{ softDeletesImport }}',
                '{{ softDeletesTrait }}',
            ],
            [
                'App\\Models',
                $name,
                $schemaFqcn,
                $schemaName,
                $softDeletes ? 'use Illuminate\\Database\\Eloquent\\SoftDeletes;' : '',
                $softDeletes ? "    use SoftDeletes;\n\n" : '',
            ],
            $stub,
        );

        $content = preg_replace('/\n{3,}/', "\n\n", $content);

        $path = app_path("Models/{$name}.php");
        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $content);

        $this->components->info("Model [{$path}] created successfully.");
    }
}
