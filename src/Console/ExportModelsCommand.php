<?php

namespace SchemaCraft\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use SchemaCraft\Config\ConfigResolver;
use SchemaCraft\Generator\Sdk\SdkModelExporter;
use SchemaCraft\Migration\SchemaDiscovery;

/**
 * Exports flat, self-contained, READ-ONLY Eloquent models into the SDK package directory.
 *
 * Deliberately separate from schema:generate-sdk: model selection is CONNECTION-driven (every schema,
 * not just those with documented API endpoints), so this command must not depend on routes or the
 * SDK context pipeline. It still writes into the SDK package path (sdk.path) so the consuming project
 * pulls API client + models from one package. Writes in that project go through the API; these models
 * are read-only (see SdkModelGenerator).
 */
class ExportModelsCommand extends Command
{
    protected $signature = 'schema:export-models
        {--api= : API configuration name from config/schema-craft.php}
        {--path= : Output directory for the package (overrides config sdk.path)}
        {--namespace= : PHP namespace for the package (overrides config sdk.namespace)}
        {--schema-path=* : Directories to scan for schema classes}
        {--force : Overwrite existing files}';

    protected $description = 'Export read-only Eloquent models from schemas into the SDK package';

    public function handle(Filesystem $files): int
    {
        $apiConfig = ConfigResolver::resolve($this->option('api'));

        $schemaDirectories = $this->option('schema-path') ?: ConfigResolver::schemaDirectories();
        $schemaClasses = (new SchemaDiscovery)->discover($schemaDirectories);

        // Connection-driven selection: every model on the connection. The optional schemas filter
        // (when an API pins a specific set) is honored, but there is NO route/endpoint filter — a
        // model exports because it exists, not because an endpoint does.
        if ($apiConfig->schemas !== null) {
            $schemaClasses = array_filter(
                $schemaClasses,
                fn (string $schemaClass): bool => in_array(class_basename($schemaClass), $apiConfig->schemas, true),
            );
        }

        if (empty($schemaClasses)) {
            $this->components->error('No schema classes found to export.');

            return self::FAILURE;
        }

        $namespace = $this->option('namespace') ?? $apiConfig->sdkNamespace;

        // Shared orchestration with the visualizer SDK build (no drift). The source model-namespace
        // root is stripped so each model's relative sub-namespace is preserved under the SDK base —
        // this is what keeps same-named models from different databases out of each other's way.
        $export = (new SdkModelExporter)->export(
            $schemaClasses,
            $namespace,
            $apiConfig->modelNamespace,
            schemasFilterActive: $apiConfig->schemas !== null,
        );

        foreach ($export['warnings'] as $warning) {
            $this->components->warn($warning['message']);
        }

        $generatedFiles = $export['files'];

        $outputPath = base_path($this->option('path') ?? $apiConfig->sdkPath);

        foreach ($generatedFiles as $file) {
            $absolutePath = $outputPath.'/'.$file->path;

            if ($files->exists($absolutePath) && ! $this->option('force')) {
                $this->components->warn("File [{$absolutePath}] already exists. Use --force to overwrite.");

                continue;
            }

            $files->ensureDirectoryExists(dirname($absolutePath));
            $files->put($absolutePath, $file->content);
            $this->components->info("Created [{$absolutePath}]");
        }

        // Model files minus the shared ReadOnlyModel base = number of models exported.
        $modelCount = max(0, count($generatedFiles) - 1);
        $this->components->info("Exported {$modelCount} model(s) to [{$outputPath}/src/Models].");

        return self::SUCCESS;
    }
}
