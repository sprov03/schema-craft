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
    'default_connection' => 'default',

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
                'request' => 'App\\Http\\Requests',
                'resource' => 'App\\Resources',
                // schema, model, service namespaces are resolved from db_connections
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

    /*
    |--------------------------------------------------------------------------
    | DB Connection Configurations
    |--------------------------------------------------------------------------
    |
    | Each entry maps a config name to a database connection, with optional
    | class name prefixes and namespace overrides. Use these when generating
    | schemas/models from multiple databases that share the same table names.
    |
    */

    'db_connections' => [
        'default' => [
            'prefixes' => [
                'service' => '',
                'schema' => '',
                'model' => '',
            ],
            'namespaces' => [
                'service' => 'App\\Models\\Services',
                'schema' => 'App\\Schemas',
                'model' => 'App\\Models',
                'factory' => 'Database\\Factories',
                'test' => 'Tests\\Unit',
            ],
            // DB Connection
            'connection' => 'default',
        ],
        'prefix-example' => [
            'prefixes' => [
                'service' => 'Prefix',
                'schema' => 'Prefix',
                'model' => 'Prefix',
            ],
            'namespaces' => [
                'service' => 'App\\Models\\Services',
                'schema' => 'App\\Schemas',
                'model' => 'App\\Models',
                'factory' => 'Database\\Factories',
                'test' => 'Tests\\Unit',
            ],
            // DB Connection
            'connection' => 'default',
        ],
        'name-spaced-example' => [
            'prefixes' => [
                'service' => '',
                'schema' => '',
                'model' => '',
            ],
            'namespaces' => [
                'service' => 'App\\Models\\Services\\Namespaced',
                'schema' => 'App\\Schemas\\Namespaced',
                'model' => 'App\\Models\\Namespaced',
                'factory' => 'Database\\Factories\\Namespaced',
                'test' => 'Tests\\Unit\\Namespaced',
            ],
            // DB Connection
            'connection' => 'default',
        ],
    ],

];
