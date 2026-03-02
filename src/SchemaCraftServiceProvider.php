<?php

namespace SchemaCraft;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use SchemaCraft\Console\CreateApiCommand;
use SchemaCraft\Console\GenerateApiCommand;
use SchemaCraft\Console\GenerateFilamentCommand;
use SchemaCraft\Console\GenerateSdkCommand;
use SchemaCraft\Console\InstallCommand;
use SchemaCraft\Console\MakeSchemaCommand;
use SchemaCraft\Console\RelationshipCommand;
use SchemaCraft\Console\SchemaFromDatabaseCommand;
use SchemaCraft\Console\SchemaMigrateCommand;
use SchemaCraft\Console\SchemaStatusCommand;
use SchemaCraft\Visualizer\GenerateController;
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
    }

    public function boot(): void
    {
        if ($this->app->environment('local', 'testing')) {
            $this->registerVisualizerRoutes();
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                CreateApiCommand::class,
                GenerateApiCommand::class,
                GenerateFilamentCommand::class,
                GenerateSdkCommand::class,
                InstallCommand::class,
                MakeSchemaCommand::class,
                RelationshipCommand::class,
                SchemaFromDatabaseCommand::class,
                SchemaMigrateCommand::class,
                SchemaStatusCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/schema-craft.php' => config_path('schema-craft.php'),
            ], 'schema-craft-config');

            $this->publishes([
                __DIR__.'/Console/stubs/api' => base_path('stubs/schema-craft/api'),
                __DIR__.'/Console/stubs/filament' => base_path('stubs/schema-craft/filament'),
                __DIR__.'/Console/stubs/sdk' => base_path('stubs/schema-craft/sdk'),
                __DIR__.'/Console/stubs/schema.stub' => base_path('stubs/schema-craft/schema.stub'),
                __DIR__.'/Console/stubs/model.stub' => base_path('stubs/schema-craft/model.stub'),
                __DIR__.'/Console/stubs/base-model.stub' => base_path('stubs/schema-craft/base-model.stub'),
            ], 'schema-craft-stubs');
        }
    }

    private function registerVisualizerRoutes(): void
    {
        $noCsrf = \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class;

        Route::prefix('_schema-craft')->group(function () use ($noCsrf) {
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

            Route::post('/api/create-api', [GenerateController::class, 'createApi'])
                ->withoutMiddleware($noCsrf);
            Route::get('/api/sdk/config', [GenerateController::class, 'sdkConfig']);
            Route::post('/api/sdk/preview', [GenerateController::class, 'sdkPreview'])
                ->withoutMiddleware($noCsrf);
            Route::post('/api/sdk/generate', [GenerateController::class, 'sdkGenerate'])
                ->withoutMiddleware($noCsrf);
        });
    }
}
