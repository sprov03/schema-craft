<?php

namespace SchemaCraft\Generator\Api;

/**
 * Value object representing a generated file with its target path and content.
 */
class GeneratedFile
{
    public function __construct(
        public string $path,
        public string $content,
    ) {}
}
