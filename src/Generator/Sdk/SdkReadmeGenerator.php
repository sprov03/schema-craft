<?php

namespace SchemaCraft\Generator\Sdk;

use Illuminate\Support\Str;

/**
 * Generates a README.md for the SDK package with installation and usage examples.
 */
class SdkReadmeGenerator
{
    /**
     * @param  string[]  $primaryModelNames
     * @param  array<string, SdkSchemaContext>  $schemas
     */
    public function generate(
        string $packageName,
        string $clientClassName,
        string $namespace,
        array $primaryModelNames,
        array $schemas,
        string $version,
    ): string {
        $lines = [];

        $lines[] = "# {$packageName}";
        $lines[] = '';
        $lines[] = "Auto-generated PHP SDK client (v{$version}).";
        $lines[] = '';

        // Installation
        $lines[] = '## Installation';
        $lines[] = '';
        $lines[] = 'Add the package as a path repository in your `composer.json`:';
        $lines[] = '';
        $lines[] = '```json';
        $lines[] = '{';
        $lines[] = '    "repositories": [';
        $lines[] = '        {';
        $lines[] = '            "type": "path",';
        $lines[] = '            "url": "../path/to/this/sdk"';
        $lines[] = '        }';
        $lines[] = '    ]';
        $lines[] = '}';
        $lines[] = '```';
        $lines[] = '';
        $lines[] = 'Then require it:';
        $lines[] = '';
        $lines[] = '```bash';
        $lines[] = "composer require {$packageName}";
        $lines[] = '```';
        $lines[] = '';

        // Setup
        $lines[] = '## Setup';
        $lines[] = '';
        $lines[] = '### 1. Configure Autoloading';
        $lines[] = '';
        $lines[] = "After installing, ensure your application's `composer.json` autoloads the SDK namespace. If you used a path repository (above), this is handled automatically.";
        $lines[] = '';
        $lines[] = '### 2. Create a Client Instance';
        $lines[] = '';
        $lines[] = '```php';
        $lines[] = "use {$namespace}\\{$clientClassName};";
        $lines[] = '';
        $lines[] = "\$client = new {$clientClassName}(";
        $lines[] = "    baseUrl: 'https://your-api.com',";
        $lines[] = "    token: 'your-sanctum-token',";
        $lines[] = ');';
        $lines[] = '```';
        $lines[] = '';
        $lines[] = 'The `baseUrl` should point to your API\'s root URL (without a trailing slash). The `token` is a valid Sanctum API token.';
        $lines[] = '';
        $lines[] = '### 3. Register as a Service (Optional)';
        $lines[] = '';
        $lines[] = 'For Laravel applications, bind the client in a service provider so it can be injected anywhere:';
        $lines[] = '';
        $lines[] = '```php';
        $lines[] = "// In a service provider's register() method:";
        $lines[] = "use {$namespace}\\{$clientClassName};";
        $lines[] = '';
        $lines[] = "\$this->app->singleton({$clientClassName}::class, function () {";
        $lines[] = "    return new {$clientClassName}(";
        $lines[] = "        baseUrl: config('services.my_api.url'),";
        $lines[] = "        token: config('services.my_api.token'),";
        $lines[] = '    );';
        $lines[] = '});';
        $lines[] = '```';
        $lines[] = '';
        $lines[] = 'Then add the credentials to your `config/services.php`:';
        $lines[] = '';
        $lines[] = '```php';
        $lines[] = "'my_api' => [";
        $lines[] = "    'url' => env('MY_API_URL', 'https://your-api.com'),";
        $lines[] = "    'token' => env('MY_API_TOKEN'),";
        $lines[] = '],';
        $lines[] = '```';
        $lines[] = '';
        $lines[] = 'And set the environment variables in your `.env`:';
        $lines[] = '';
        $lines[] = '```env';
        $lines[] = 'MY_API_URL=https://your-api.com';
        $lines[] = 'MY_API_TOKEN=your-sanctum-token';
        $lines[] = '```';
        $lines[] = '';
        $lines[] = "Now you can inject `{$clientClassName}` into controllers, jobs, or commands:";
        $lines[] = '';
        $lines[] = '```php';
        $lines[] = "use {$namespace}\\{$clientClassName};";
        $lines[] = '';
        $lines[] = "public function __construct(private {$clientClassName} \$client) {}";
        $lines[] = '```';
        $lines[] = '';

        // Available Resources
        $lines[] = '## Available Resources';
        $lines[] = '';

        foreach ($primaryModelNames as $modelName) {
            $methodName = Str::camel(Str::pluralStudly($modelName));
            $dataClass = $modelName.'Data';
            $context = $schemas[$modelName] ?? null;

            $lines[] = "### {$modelName}";
            $lines[] = '';
            $lines[] = '```php';
            $lines[] = '// List all';
            $lines[] = "\${$methodName} = \$client->{$methodName}()->list(); // {$dataClass}[]";
            $lines[] = '';
            $lines[] = '// Get one';
            $lines[] = "\${$methodName}Item = \$client->{$methodName}()->get(\$id); // {$dataClass}";
            $lines[] = '';
            $lines[] = '// Create';
            $lines[] = "\${$methodName}Item = \$client->{$methodName}()->create(...);";
            $lines[] = '';
            $lines[] = '// Update';
            $lines[] = "\${$methodName}Item = \$client->{$methodName}()->update(\$id, ...);";
            $lines[] = '';
            $lines[] = '// Delete';
            $lines[] = "\$client->{$methodName}()->delete(\$id);";

            // Custom actions
            if ($context !== null && ! empty($context->customActions)) {
                $lines[] = '';
                foreach ($context->customActions as $action) {
                    $actionSlug = Str::snake($action->name, '-');
                    $lines[] = "// {$action->name} (".strtoupper($action->httpMethod)." /{$actionSlug})";
                    $lines[] = "\$client->{$methodName}()->{$action->name}(\$id);";
                }
            }

            $lines[] = '```';
            $lines[] = '';
        }

        // Authentication
        $lines[] = '## Authentication';
        $lines[] = '';
        $lines[] = 'This SDK uses Bearer token authentication. Pass a valid Sanctum token when creating the client.';
        $lines[] = '';

        return implode("\n", $lines);
    }
}
