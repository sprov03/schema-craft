<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default API
    |--------------------------------------------------------------------------
    |
    | The default API configuration to use when no --api option is specified.
    |
    */

    'default' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Schema Paths
    |--------------------------------------------------------------------------
    |
    | Directories to scan for schema classes. These are used by commands like
    | schema:generate-sdk to discover schema classes automatically.
    |
    */

    'schema_paths' => [app_path('Schemas')],

    /*
    |--------------------------------------------------------------------------
    | Query Definitions Path
    |--------------------------------------------------------------------------
    |
    | Directory where visual query builder definitions are stored as JSON files.
    | These definitions can be loaded and edited in the schema visualizer UI.
    |
    */

    'query_definitions_path' => app_path('QueryDefinitions'),

    /*
    |--------------------------------------------------------------------------
    | API Configurations
    |--------------------------------------------------------------------------
    |
    | Each entry defines an independent API with its own set of namespaces,
    | routes, and SDK configuration. You can generate multiple APIs per
    | project, each with fully isolated controllers, requests, and resources.
    |
    */

    'apis' => [
        'default' => [
            'namespaces' => [
                'controller' => 'App\\Http\\Controllers\\Api',
                'service' => 'App\\Models\\Services',
                'request' => 'App\\Http\\Requests',
                'resource' => 'App\\Resources',
                'schema' => 'App\\Schemas',
                'model' => 'App\\Models',
            ],
            'routes' => [
                'file' => 'routes/api.php',
                'prefix' => 'api',
                'middleware' => ['auth:sanctum'],
            ],
            'schemas' => null, // null = all schemas with controllers
            'sdk' => [
                'path' => 'packages/sdk',
                'name' => 'my-app/sdk',
                'namespace' => 'MyApp\\Sdk',
                'client' => 'MyAppClient',
                'version' => '0.1.0',
            ],
        ],
    ],

];
