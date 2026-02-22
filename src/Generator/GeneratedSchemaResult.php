<?php

namespace SchemaCraft\Generator;

class GeneratedSchemaResult
{
    public function __construct(
        public string $schemaContent,
        public string $modelContent,
        public string $schemaClassName,
        public string $modelClassName,
        public bool $hasSoftDeletes,
    ) {}
}
