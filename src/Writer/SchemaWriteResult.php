<?php

namespace SchemaCraft\Writer;

class SchemaWriteResult
{
    public function __construct(
        public bool $success,
        public string $message,
        public ?string $propertyName = null,
    ) {}
}
