<?php

namespace SchemaCraft\Generator\Sdk;

use Illuminate\Support\Str;

/**
 * Generates the main SDK client class — the entry point for consumers.
 *
 * Provides resource accessor methods (e.g., $client->posts(), $client->comments()).
 * Generated code is PHP 7.4 compatible.
 */
class SdkClientGenerator
{
    /**
     * Generate the client class PHP code.
     *
     * @param  string[]  $modelNames  List of model names with generated APIs
     */
    public function generate(
        string $namespace,
        string $clientClassName,
        string $resourceNamespace,
        array $modelNames,
    ): string {
        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = "namespace {$namespace};";
        $lines[] = '';

        // Imports
        foreach ($modelNames as $modelName) {
            $resourceClass = $modelName.'Resource';
            $lines[] = "use {$resourceNamespace}\\{$resourceClass};";
        }

        $lines[] = '';
        $lines[] = "class {$clientClassName}";
        $lines[] = '{';
        $lines[] = '    /** @var SdkConnector */';
        $lines[] = '    private $connector;';
        $lines[] = '';
        $lines[] = '    /**';
        $lines[] = '     * @param string $baseUrl';
        $lines[] = '     * @param string $token';
        $lines[] = '     */';
        $lines[] = '    public function __construct($baseUrl, $token)';
        $lines[] = '    {';
        $lines[] = '        $this->connector = new SdkConnector($baseUrl, $token);';
        $lines[] = '    }';

        // Resource accessor methods
        foreach ($modelNames as $modelName) {
            $resourceClass = $modelName.'Resource';
            $methodName = Str::camel(Str::pluralStudly($modelName));
            $lines[] = '';
            $lines[] = '    /**';
            $lines[] = "     * @return {$resourceClass}";
            $lines[] = '     */';
            $lines[] = "    public function {$methodName}()";
            $lines[] = '    {';
            $lines[] = "        return new {$resourceClass}(\$this->connector);";
            $lines[] = '    }';
        }

        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }
}
