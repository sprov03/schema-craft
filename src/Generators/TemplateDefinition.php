<?php

namespace SchemaCraft\Generators;

class TemplateDefinition
{
    public function __construct(
        /** Output file path with [bracket] placeholders, e.g. 'app/[schema.model.title].php'. */
        public readonly string $outputPath,
        /** Blade view name, e.g. 'generators.resources.resource'. */
        public readonly string $viewName,
        /** Extra variables to inject, values may contain [bracket] placeholders. */
        public readonly array $extraVariables = [],
        /** Dot-path to an iterable in $allData to iterate over (e.g. 'schema.relationships'). */
        public readonly ?string $iterateOver = null,
        /** Variable name for each item during iteration (e.g. 'relationship'). */
        public readonly ?string $iterateAs = null,
        /** Optional filter: 'collection', 'singular', or null. */
        public readonly ?string $iterateFilter = null,
    ) {}
}
