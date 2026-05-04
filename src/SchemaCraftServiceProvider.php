<?php

namespace SchemaCraft;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use SchemaCraft\Console\GenerateApiCommand;
use SchemaCraft\Console\GenerateFilamentCommand;
use SchemaCraft\Console\GenerateSdkCommand;
use SchemaCraft\Console\InstallCommand;
use SchemaCraft\Console\MakeSchemaCommand;
use SchemaCraft\Console\PublishFilamentActionCommand;
use SchemaCraft\Console\RelationshipCommand;
use SchemaCraft\Console\SchemaFromDatabaseCommand;
use SchemaCraft\Console\SchemaMigrateCommand;
use SchemaCraft\Console\SchemaStatusCommand;
use SchemaCraft\Generators\GeneratorRegistry;
use SchemaCraft\Generators\GeneratorRunner;
use SchemaCraft\Generators\InputTypeRegistry;
use SchemaCraft\Generators\InputTypes\BooleanInputType;
use SchemaCraft\Generators\InputTypes\FilamentPlacementsInputType;
use SchemaCraft\Generators\InputTypes\NestedFieldSelectorInputType;
use SchemaCraft\Generators\InputTypes\SchemaColumnComboboxInputType;
use SchemaCraft\Generators\InputTypes\SchemaColumnInputType;
use SchemaCraft\Generators\InputTypes\SchemaColumnsInputType;
use SchemaCraft\Generators\InputTypes\SchemaFieldPickerInputType;
use SchemaCraft\Generators\InputTypes\SchemaSelectorInputType;
use SchemaCraft\Generators\InputTypes\SelectInputType;
use SchemaCraft\Generators\InputTypes\SelectResourceDirectoryInputType;
use SchemaCraft\Generators\InputTypes\TextInputType;
use SchemaCraft\Visualizer\ActionsController;
use SchemaCraft\Visualizer\DocsController;
use SchemaCraft\Visualizer\GenerateController;
use SchemaCraft\Visualizer\GeneratorController;
use SchemaCraft\Visualizer\SchemaController;
use SchemaCraft\Visualizer\StatusController;
use SchemaCraft\Visualizer\VisualizerController;

class SchemaCraftServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/schema-craft.php', 'schema-craft'
        );

        $this->app->singleton(GeneratorRegistry::class, function () {
            $registry = new GeneratorRegistry;

            $path = config('schema-craft.generators_path');

            if ($path && is_dir($path)) {
                $registry->discover($path);
            }

            foreach (config('schema-craft.generators', []) as $class) {
                $registry->register($class);
            }

            return $registry;
        });

        $this->app->bind(GeneratorRunner::class);

        $this->app->singleton(InputTypeRegistry::class, function () {
            $registry = new InputTypeRegistry;

            $registry->register('text', TextInputType::class);
            $registry->register('select', SelectInputType::class);
            $registry->register('boolean', BooleanInputType::class);
            $registry->register('schemaSelector', SchemaSelectorInputType::class);
            $registry->register('schemaColumn', SchemaColumnInputType::class);
            $registry->register('schemaColumns', SchemaColumnsInputType::class);
            $registry->register('selectResourceDirectory', SelectResourceDirectoryInputType::class);
            $registry->register('filamentPlacements', FilamentPlacementsInputType::class);
            $registry->register('fieldPicker', SchemaFieldPickerInputType::class);
            $registry->register('schemaColumnCombobox', SchemaColumnComboboxInputType::class);
            $registry->register('nestedFieldSelector', NestedFieldSelectorInputType::class);

            return $registry;
        });
    }

    public function boot(): void
    {
        $this->registerVisualizerRoutes();

        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateApiCommand::class,
                GenerateFilamentCommand::class,
                GenerateSdkCommand::class,
                InstallCommand::class,
                MakeSchemaCommand::class,
                PublishFilamentActionCommand::class,
                RelationshipCommand::class,
                SchemaFromDatabaseCommand::class,
                SchemaMigrateCommand::class,
                SchemaStatusCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/schema-craft.php' => config_path('schema-craft.php'),
            ], 'schema-craft-config');

            $stubPublishes = $this->buildStubPublishes();

            $this->publishes($stubPublishes, 'schema-craft-stubs');

            $this->publishes(
                array_merge(
                    [__DIR__.'/../config/schema-craft.php' => config_path('schema-craft.php')],
                    $stubPublishes,
                ),
                'schema-craft',
            );
        }
    }

    /**
     * Build the individual stub file publish mappings.
     *
     * Publishing individual files (instead of directories) ensures that
     * `vendor:publish` without --force skips existing customized stubs
     * while still copying any new stubs added to the package.
     *
     * @return array<string, string>
     */
    private function buildStubPublishes(): array
    {
        $packageStubs = __DIR__.'/Console/stubs';
        $publishBase = base_path('stubs/schema-craft');
        $publishes = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($packageStubs, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'stub') {
                continue;
            }

            $relativePath = str_replace($packageStubs.'/', '', $file->getPathname());
            $publishes[$packageStubs.'/'.$relativePath] = $publishBase.'/'.$relativePath;
        }

        return $publishes;
    }

    private function registerVisualizerRoutes(): void
    {
        $noCsrf = \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class;

        Route::prefix('_schema-craft')
            ->middleware([Visualizer\RequireLocalEnvironment::class, Visualizer\SchemaCraftErrorHandler::class])
            ->group(function () use ($noCsrf) {
                Route::get('/', [VisualizerController::class, 'index']);
                Route::get('/api/schema', [VisualizerController::class, 'api']);
                Route::post('/api/apply-relationship', [VisualizerController::class, 'applyRelationship'])
                    ->withoutMiddleware($noCsrf);

                // Query Builder API
                Route::get('/api/queries', [VisualizerController::class, 'listQueries']);
                Route::get('/api/queries/{name}', [VisualizerController::class, 'loadQuery']);
                Route::post('/api/queries', [VisualizerController::class, 'saveQuery'])
                    ->withoutMiddleware($noCsrf);
                Route::delete('/api/queries/{name}', [VisualizerController::class, 'deleteQuery'])
                    ->withoutMiddleware($noCsrf);
                Route::get('/api/query-config', [VisualizerController::class, 'queryConfig']);
                Route::post('/api/generate-query', [VisualizerController::class, 'generateQuery'])
                    ->withoutMiddleware($noCsrf);
                Route::post('/api/preview-sql', [VisualizerController::class, 'previewSql'])
                    ->withoutMiddleware($noCsrf);

                // Schema Management API
                Route::get('/api/connections', [SchemaController::class, 'connections']);
                Route::get('/api/install/status', [SchemaController::class, 'installStatus']);
                Route::post('/api/install', [SchemaController::class, 'install'])
                    ->withoutMiddleware($noCsrf);
                Route::post('/api/schema/create/preview', [SchemaController::class, 'createPreview'])
                    ->withoutMiddleware($noCsrf);
                Route::post('/api/schema/create', [SchemaController::class, 'create'])
                    ->withoutMiddleware($noCsrf);
                Route::get('/api/database/tables', [SchemaController::class, 'listTables']);
                Route::post('/api/schema/import/preview', [SchemaController::class, 'importPreview'])
                    ->withoutMiddleware($noCsrf);
                Route::post('/api/schema/import', [SchemaController::class, 'import'])
                    ->withoutMiddleware($noCsrf);
                Route::post('/api/schema/import/extras', [SchemaController::class, 'generateExtras'])
                    ->withoutMiddleware($noCsrf);

                // String inflection API
                Route::post('/api/str/inflect', [SchemaController::class, 'strInflect'])
                    ->withoutMiddleware($noCsrf);

                // Schema Editor API
                Route::get('/api/schema/detail', [SchemaController::class, 'schemaDetail']);
                Route::get('/api/schema/available-models', [SchemaController::class, 'availableModels']);
                Route::post('/api/schema/save/preview', [SchemaController::class, 'schemaSavePreview'])
                    ->withoutMiddleware($noCsrf);
                Route::post('/api/schema/save', [SchemaController::class, 'schemaSave'])
                    ->withoutMiddleware($noCsrf);

                // Status & Migrate API
                Route::get('/api/status', [StatusController::class, 'status']);
                Route::post('/api/migrate/preview', [StatusController::class, 'migratePreview'])
                    ->withoutMiddleware($noCsrf);
                Route::post('/api/migrate', [StatusController::class, 'migrate'])
                    ->withoutMiddleware($noCsrf);
                Route::post('/api/migrate/run', [StatusController::class, 'migrateAndRun'])
                    ->withoutMiddleware($noCsrf);

                // Generate API
                Route::get('/api/generate/config', [GenerateController::class, 'config']);
                Route::get('/api/generate/stack-detail', [GenerateController::class, 'stackDetail']);
                Route::get('/api/generate/resource-detail', [GenerateController::class, 'resourceDetail']);
                Route::post('/api/generate/preview', [GenerateController::class, 'generatePreview'])
                    ->withoutMiddleware($noCsrf);
                Route::post('/api/generate', [GenerateController::class, 'generate'])
                    ->withoutMiddleware($noCsrf);
                Route::post('/api/generate/action/preview', [GenerateController::class, 'actionPreview'])
                    ->withoutMiddleware($noCsrf);
                Route::post('/api/generate/action', [GenerateController::class, 'action'])
                    ->withoutMiddleware($noCsrf);
                Route::post('/api/generate/service', [GenerateController::class, 'createService'])
                    ->withoutMiddleware($noCsrf);
                Route::post('/api/generate/test', [GenerateController::class, 'createTest'])
                    ->withoutMiddleware($noCsrf);

                // Filament Generation API
                Route::get('/api/filament/install-status', [GenerateController::class, 'filamentInstallStatus']);
                Route::post('/api/filament/install', [GenerateController::class, 'filamentInstall'])
                    ->withoutMiddleware($noCsrf);
                Route::post('/api/filament/preview', [GenerateController::class, 'filamentPreview'])
                    ->withoutMiddleware($noCsrf);
                Route::post('/api/filament/generate', [GenerateController::class, 'filamentGenerate'])
                    ->withoutMiddleware($noCsrf);

                // Actions API
                Route::get('/api/actions/config', [ActionsController::class, 'config']);
                Route::get('/api/actions/detail', [ActionsController::class, 'detail']);
                Route::get('/api/actions/related-schema', [ActionsController::class, 'relatedSchema']);
                Route::post('/api/actions/create/preview', [ActionsController::class, 'createPreview'])
                    ->withoutMiddleware($noCsrf);
                Route::post('/api/actions/create', [ActionsController::class, 'create'])
                    ->withoutMiddleware($noCsrf);
                Route::get('/api/actions/service-preview', [ActionsController::class, 'servicePreview']);
                Route::post('/api/actions/generate-service', [ActionsController::class, 'generateService'])
                    ->withoutMiddleware($noCsrf);
                Route::post('/api/actions/update/preview', [ActionsController::class, 'updatePreview'])
                    ->withoutMiddleware($noCsrf);
                Route::post('/api/actions/update', [ActionsController::class, 'update'])
                    ->withoutMiddleware($noCsrf);
                Route::get('/api/actions/filament-action-preview', [ActionsController::class, 'filamentActionPreview']);
                Route::post('/api/actions/publish-filament-action', [ActionsController::class, 'publishFilamentAction'])
                    ->withoutMiddleware($noCsrf);

                // Custom Generators API
                Route::get('/api/generators/config', [GeneratorController::class, 'config']);
                Route::post('/api/generators/next-step', [GeneratorController::class, 'nextStep'])
                    ->withoutMiddleware($noCsrf);
                Route::get('/api/generators/related-schema', [GeneratorController::class, 'relatedSchema']);
                Route::get('/api/generators/schema-info', [GeneratorController::class, 'schemaInfo']);
                Route::post('/api/generators/preview', [GeneratorController::class, 'preview'])
                    ->withoutMiddleware($noCsrf);
                Route::post('/api/generators/run', [GeneratorController::class, 'run'])
                    ->withoutMiddleware($noCsrf);
                Route::get('/api/generators/docs', [GeneratorController::class, 'generatorsDocs']);

                Route::get('/api/available-actions', [GenerateController::class, 'availableActions']);
                Route::get('/api/api-routes', [GenerateController::class, 'apiRoutes']);
                Route::post('/api/import-actions', [GenerateController::class, 'importActions'])
                    ->withoutMiddleware($noCsrf);
                Route::post('/api/generate-resources', [GenerateController::class, 'generateResources'])
                    ->withoutMiddleware($noCsrf);
                Route::post('/api/create-resource/preview', [GenerateController::class, 'createResourcePreview'])
                    ->withoutMiddleware($noCsrf);
                Route::post('/api/create-resource', [GenerateController::class, 'createResource'])
                    ->withoutMiddleware($noCsrf);
                Route::get('/api/sdk/config', [GenerateController::class, 'sdkConfig']);
                Route::post('/api/sdk/preview', [GenerateController::class, 'sdkPreview'])
                    ->withoutMiddleware($noCsrf);
                Route::post('/api/sdk/generate', [GenerateController::class, 'sdkGenerate'])
                    ->withoutMiddleware($noCsrf);

                // Docs API
                Route::get('/api/docs', [DocsController::class, 'index']);
            });
    }
}
