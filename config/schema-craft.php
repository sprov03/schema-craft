<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Explicit Foreign Keys
    |--------------------------------------------------------------------------
    |
    | When true, schema:from-database generates FK columns as visible PHP
    | properties alongside the BelongsTo relationship attribute. When false
    | (default), FK columns are implicit — derived from the relationship.
    |
    */

    'explicit_foreign_keys' => false,

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
    | Custom Generators
    |--------------------------------------------------------------------------
    |
    | Path to scan for SchemaCraftGenerator subclasses (auto-discovery).
    | Set to null to disable auto-discovery.
    |
    | The 'generators' array allows explicit registration of generator classes
    | in addition to auto-discovered ones.
    |
    */

    'generators_path' => app_path('Generators'),

    'generators' => [
        // App\Generators\MyGenerator::class,
    ],

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

    /*
    |--------------------------------------------------------------------------
    | Visualizer Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for the Schema Visualizer dev tool.
    |
    | docs_path: Directory (relative to base_path()) to scan for project-level
    |            markdown documentation files. These are displayed in the
    |            Docs tab alongside the SchemaCraft package documentation.
    |
    */

    'visualizer' => [
        'docs_path' => 'docs',
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
                'actions' => 'App\\Models\\Actions',
                'factory' => 'Database\\Factories',
                'test' => 'Tests\\Unit',
            ],
            // DB Connection
            'connection' => 'default',
        ],
    ],
];
