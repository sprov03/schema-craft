<?php

namespace SchemaCraft\QueryBuilder;

class SchemaIndexWriteResult
{
    public function __construct(
        public bool $success,
        public string $message,
    ) {}
}
