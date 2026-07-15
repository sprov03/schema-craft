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

        // Typed collection wrapper for to-many data. Shipped once; DTOs return DataCollection<XData>
        // instead of bare arrays so consumers get a real object (foreach / ->first() / ->count())
        // with IDE completion. Dependency-free and PHP 7.4-safe.
        $files['data_collection'] = new GeneratedFile(
            path: 'src/Data/DataCollection.php',
            content: $this->generateDataCollection($dataNamespace),
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

        // Companion options classes for closed-enumeration column types — bitmasks and enums.
        // One file per unique type (deduped by source FQCN), placed in src/Data/ alongside the
        // DTOs that reference it. Wire shape isn't changed; the companions are additive so
        // consumers can autocomplete + type-check (MissionBitmaskOptions::LOAN instead of `1`,
        // PostStatusOptions::PUBLISHED instead of `'published'`).
        $companions = [];
        foreach ($schemas as $context) {
            if ($context->resourceFields === null) {
                continue;
            }
            foreach ($context->resourceFields['columns'] ?? [] as $col) {
                if (empty($col['options']) || empty($col['type'])) {
                    continue;
                }
                $sourceFqcn = $col['type'];
                if (isset($companions[$sourceFqcn])) {
                    continue;
                }
                $companionClassName = $this->companionClassName($sourceFqcn);
                $files["options_{$sourceFqcn}"] = new GeneratedFile(
                    path: "src/Data/{$companionClassName}.php",
                    content: (new SdkOptionsGenerator)->generate(
                        $companionClassName,
                        $col['options'],
                        $dataNamespace,
                    ),
                );
                $companions[$sourceFqcn] = true;
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

    /**
     * The typed collection wrapper returned for to-many DTO fields.
     *
     * PHP 7.4-safe on purpose (the SDK supports ^7.4|^8.0): no `mixed` type declarations, and
     * offsetGet uses the #[\ReturnTypeWillChange] trick — a plain comment on 7.4, an attribute on
     * 8.x — so the internal ArrayAccess tentative return type doesn't deprecation-warn on 8.1+.
     */
    public function generateDataCollection(string $dataNamespace): string
    {
        return <<<PHP
        <?php

        namespace {$dataNamespace};

        /**
         * A typed, immutable-style collection of DTOs returned by the SDK for to-many data.
         *
         * Iterate it, index it, or use the helpers — every access is typed to the element via the
         * \\@template so IDEs and static analysers complete the DTO. Depends on nothing.
         *
         * @template T
         * @implements \\IteratorAggregate<int, T>
         * @implements \\ArrayAccess<int, T>
         */
        class DataCollection implements \\IteratorAggregate, \\Countable, \\ArrayAccess
        {
            /** @var array<int, T> */
            private \$items;

            /** @param array<int, T> \$items */
            public function __construct(array \$items = [])
            {
                \$this->items = array_values(\$items);
            }

            /** @return array<int, T> */
            public function all()
            {
                return \$this->items;
            }

            /** @return T|null */
            public function first()
            {
                return \$this->items[0] ?? null;
            }

            /** @return T|null */
            public function last()
            {
                return \$this->items === [] ? null : \$this->items[count(\$this->items) - 1];
            }

            /** @return bool */
            public function isEmpty()
            {
                return \$this->items === [];
            }

            /**
             * @param callable(T): mixed \$callback
             * @return array<int, mixed>
             */
            public function map(callable \$callback)
            {
                return array_map(\$callback, \$this->items);
            }

            /**
             * @param callable(T): bool \$callback
             * @return self<T>
             */
            public function filter(callable \$callback)
            {
                return new self(array_values(array_filter(\$this->items, \$callback)));
            }

            /**
             * The wire representation — each DTO serialised back to a plain array.
             *
             * @return array<int, mixed>
             */
            public function toArray()
            {
                return array_map(function (\$item) {
                    return is_object(\$item) && method_exists(\$item, 'toArray') ? \$item->toArray() : \$item;
                }, \$this->items);
            }

            public function count(): int
            {
                return count(\$this->items);
            }

            /** @return \\ArrayIterator<int, T> */
            public function getIterator(): \\Traversable
            {
                return new \\ArrayIterator(\$this->items);
            }

            /** @param int \$offset */
            public function offsetExists(\$offset): bool
            {
                return isset(\$this->items[\$offset]);
            }

            /**
             * @param int \$offset
             * @return T|null
             */
            #[\\ReturnTypeWillChange]
            public function offsetGet(\$offset)
            {
                return \$this->items[\$offset] ?? null;
            }

            /**
             * @param int|null \$offset
             * @param T \$value
             */
            public function offsetSet(\$offset, \$value): void
            {
                if (\$offset === null) {
                    \$this->items[] = \$value;
                } else {
                    \$this->items[\$offset] = \$value;
                }
            }

            /** @param int \$offset */
            public function offsetUnset(\$offset): void
            {
                unset(\$this->items[\$offset]);
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

    /**
     * Companion class name for a closed-enumeration source type. Bitmask and enum both get
     * `<TypeBasename>Options` so consumers find them in one consistent place under the SDK's
     * Data namespace. The "Options" suffix avoids name collisions with the source class (which
     * isn't copied into the SDK) and signals "constants for valid values."
     */
    private function companionClassName(string $sourceFqcn): string
    {
        $basename = ($pos = strrpos($sourceFqcn, '\\')) !== false
            ? substr($sourceFqcn, $pos + 1)
            : $sourceFqcn;

        return $basename.'Options';
    }
}
