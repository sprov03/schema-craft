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

        // Exceptions — SdkValidationException extends SdkRequestException so a single
        // catch(SdkRequestException) covers both, but callers can target 422 specifically.
        $files['exception_request'] = new GeneratedFile(
            path: 'src/SdkRequestException.php',
            content: $this->generateRequestException($namespace),
        );

        $files['exception_validation'] = new GeneratedFile(
            path: 'src/SdkValidationException.php',
            content: $this->generateValidationException($namespace),
        );

        // Data DTOs and Resources for each schema
        $primaryModelNames = [];

        foreach ($schemas as $modelName => $context) {
            // Data DTO — generated for all schemas (primary and dependency-only).
            // When resourceFields is present, the DTO is driven by the same per-Resource shape
            // the API docs panel renders — both consume SdkBuildResult's schema map (projected
            // via toApiDocsJson() for the visualizer). The SDK and the visualizer display the
            // same response shape because they consume the same model; no separate enrichment,
            // no visualizer-specific payload. See knowledge entry sdk-walker-separation.
            // Inner DTOs (from rich column-type SdkShapes) carry their full class name
            // in the innerDto definition; everything else appends 'Data' to the model name.
            $dataClassName = $context->innerDto !== null
                ? $context->innerDto['name']
                : $modelName.'Data';

            $files["data_{$modelName}"] = new GeneratedFile(
                path: "src/Data/{$dataClassName}.php",
                content: match (true) {
                    // Rich column-type nested DTO — emitted from its resolved field set.
                    $context->innerDto !== null => (new SdkDataGenerator)->generateFromInnerDto(
                        $context->innerDto,
                        $dataNamespace,
                    ),
                    $context->resourceFields !== null => (new SdkDataGenerator)->generateFromFields(
                        $context->resourceFields,
                        $dataNamespace,
                        $dataClassName,
                    ),
                    default => (new SdkDataGenerator)->generate(
                        $context->table,
                        $dataNamespace,
                        $modelName,
                    ),
                },
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
                        $context->endpoints,
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

        // README.md
        $files['readme'] = new GeneratedFile(
            path: 'README.md',
            content: (new SdkReadmeGenerator)->generate(
                $packageName,
                $clientClassName,
                $namespace,
                $primaryModelNames,
                $schemas,
                $version,
            ),
        );

        return $files;
    }

    public function generateRequestException(string $namespace): string
    {
        return <<<PHP
        <?php

        namespace {$namespace};

        /**
         * Thrown by SdkConnector when the API returns any 4xx or 5xx response.
         *
         * Catch SdkValidationException first when you need to handle 422 specifically.
         * Catch this class to handle everything else (404, 403, 500, etc.).
         */
        class SdkRequestException extends \\RuntimeException
        {
            /** @var int */
            private \$statusCode;

            /** @var array */
            private \$errors;

            /**
             * @param string \$message
             * @param int    \$statusCode
             * @param array  \$errors
             */
            public function __construct(\$message, \$statusCode, array \$errors = [])
            {
                parent::__construct(\$message);
                \$this->statusCode = \$statusCode;
                \$this->errors = \$errors;
            }

            /** @return int */
            public function getStatusCode()
            {
                return \$this->statusCode;
            }

            /** @return array */
            public function getErrors()
            {
                return \$this->errors;
            }
        }

        PHP;
    }

    public function generateValidationException(string $namespace): string
    {
        return <<<PHP
        <?php

        namespace {$namespace};

        /**
         * Thrown by SdkConnector when the API returns HTTP 422 Unprocessable Entity.
         *
         * getErrors() returns the field-level validation messages from the API response:
         *   ['field' => ['The field is required.', ...], ...]
         *
         * Extends SdkRequestException so a single catch(SdkRequestException) catches both.
         */
        class SdkValidationException extends SdkRequestException
        {
        }

        PHP;
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
