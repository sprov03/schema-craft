<?php

namespace SchemaCraft;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use SchemaCraft\Console\CreateApiCommand;
use SchemaCraft\Console\GenerateApiCommand;
use SchemaCraft\Console\GenerateSdkCommand;
use SchemaCraft\Console\InstallCommand;
use SchemaCraft\Console\MakeSchemaCommand;
use SchemaCraft\Console\RelationshipCommand;
use SchemaCraft\Console\SchemaFromDatabaseCommand;
use SchemaCraft\Console\SchemaMigrateCommand;
use SchemaCraft\Console\SchemaStatusCommand;
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
                __DIR__.'/Console/stubs/sdk' => base_path('stubs/schema-craft/sdk'),
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
        });
    }
}
