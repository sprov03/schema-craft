<?php

namespace SchemaCraft\Generators;

class Template
{
    public static function file(string $viewName, string $outputPath): TemplateDefinition
    {
        return new TemplateDefinition(viewName: $viewName, outputPath: $outputPath);
    }
}
