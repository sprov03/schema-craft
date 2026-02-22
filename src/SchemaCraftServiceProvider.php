<?php

namespace SchemaCraft;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
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
        //
    }

    public function boot(): void
    {
        if ($this->app->environment('local')) {
            $this->registerVisualizerRoutes();
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
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
                __DIR__.'/Console/stubs/api' => base_path('stubs/schema-craft/api'),
                __DIR__.'/Console/stubs/sdk' => base_path('stubs/schema-craft/sdk'),
            ], 'schema-craft-stubs');
        }
    }

    private function registerVisualizerRoutes(): void
    {
        Route::prefix('_schema-craft')->group(function () {
            Route::get('/', [VisualizerController::class, 'index']);
            Route::get('/api/schema', [VisualizerController::class, 'api']);
            Route::post('/api/apply-relationship', [VisualizerController::class, 'applyRelationship'])
                ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        });
    }
}
