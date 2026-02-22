<?php

namespace SchemaCraft\Generator;

class GeneratedProperty
{
    public function __construct(
        public string $name,
        public string $phpType,
        public bool $nullable = false,
        public array $attributes = [],
        public mixed $default = null,
        public bool $hasDefault = false,
        public bool $isRelationship = false,
        public ?string $docBlock = null,
    ) {}
}
