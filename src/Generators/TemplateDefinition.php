<?php

namespace SchemaCraft\Generators;

class TemplateDefinition
{
    public function __construct(
        public readonly string $viewName,
        public readonly string $outputPath,
    ) {}
}
