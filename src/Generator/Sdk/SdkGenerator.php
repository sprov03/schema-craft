<?php

namespace SchemaCraft\Generator\Sdk;

use SchemaCraft\Generator\Api\GeneratedFile;

/**
 * Orchestrates the generation of a complete SDK Composer package.
 *
 * Takes a list of schema classes (that have generated APIs) and produces
 * all the files needed for a standalone PHP client package.
 */
class SdkGenerator
{
    /**
     * Generate all SDK package files.
     *
     * @param  array<string, SdkSchemaContext>  $schemas  Keyed by model name
     * @return array<string, GeneratedFile>
     */
    public function generate(
        array $schemas,
        string $packageName = 'my-app/sdk',
        string $namespace = 'MyApp\\Sdk',
        string $clientClassName = 'MyAppClient',
        string $stubsPath = '',
        string $version = '0.1.0',
    ): array {
        $dataNamespace = $namespace.'\\Data';
        $resourceNamespace = $namespace.'\\Resources';
        $files = [];

        // composer.json
        $files['composer.json'] = new GeneratedFile(
            path: 'composer.json',
            content: $this->generateComposerJson($packageName, $namespace, $clientClassName, $stubsPath, $version),
        );

        // SdkConnector
        $files['connector'] = new GeneratedFile(
            path: 'src/SdkConnector.php',
            content: (new SdkConnectorGenerator)->generate($namespace),
        );

        // Data DTOs and Resources for each schema
        $primaryModelNames = [];

        foreach ($schemas as $modelName => $context) {
            // Data DTO — generated for all schemas (primary and dependency-only)
            $dataClassName = $modelName.'Data';
            $files["data_{$modelName}"] = new GeneratedFile(
                path: "src/Data/{$dataClassName}.php",
                content: (new SdkDataGenerator)->generate(
                    $context->table,
                    $dataNamespace,
                    $modelName,
                ),
            );

            // Resource — only for primary schemas (not dependency-only)
            if (! $context->isDependencyOnly) {
                $primaryModelNames[] = $modelName;

                $resourceClassName = $modelName.'Resource';
                $files["resource_{$modelName}"] = new GeneratedFile(
                    path: "src/Resources/{$resourceClassName}.php",
                    content: (new SdkResourceGenerator)->generate(
                        $context->table,
                        $resourceNamespace,
                        $dataNamespace,
                        $modelName,
                        $context->customActions,
                    ),
                );
            }
        }

        // Client — only references primary schemas
        $files['client'] = new GeneratedFile(
            path: "src/{$clientClassName}.php",
            content: (new SdkClientGenerator)->generate(
                $namespace,
                $clientClassName,
                $resourceNamespace,
                $primaryModelNames,
            ),
        );

        return $files;
    }

    private function generateComposerJson(
        string $packageName,
        string $namespace,
        string $clientClassName,
        string $stubsPath,
        string $version,
    ): string {
        $stubFile = $stubsPath !== '' ? $stubsPath.'/sdk/composer.json.stub' : '';

        if ($stubFile !== '' && file_exists($stubFile)) {
            $stub = file_get_contents($stubFile);
        } else {
            $stub = file_get_contents(dirname(__DIR__, 2).'/Console/stubs/sdk/composer.json.stub');
        }

        $escapedNamespace = str_replace('\\', '\\\\', $namespace);

        return str_replace(
            ['{{ packageName }}', '{{ clientName }}', '{{ namespace }}', '{{ version }}'],
            [$packageName, $clientClassName, $escapedNamespace, $version],
            $stub,
        );
    }
}
