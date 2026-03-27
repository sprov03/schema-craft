<?php

namespace SchemaCraft\Generators;

class InputDefinition
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
        public readonly mixed $default = null,
        public readonly array $options = [],
        public readonly ?string $schemaKey = null,
    ) {}
}
