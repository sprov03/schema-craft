<?php

namespace SchemaCraft\Generator;

/**
 * Value object representing a column as configured in the visual schema editor.
 */
class EditorColumn
{
    public function __construct(
        public string $name,
        public string $phpType,
        public ?string $typeOverride = null,
        public ?string $columnType = null,
        public bool $nullable = false,
        public bool $primary = false,
        public bool $autoIncrement = false,
        public bool $unsigned = false,
        public bool $unique = false,
        public bool $index = false,
        public bool $fillable = false,
        public bool $hidden = false,
        public ?int $length = null,
        public ?int $precision = null,
        public ?int $scale = null,
        public mixed $default = null,
        public bool $hasDefault = false,
        public ?string $expressionDefault = null,
        public ?string $castClass = null,
        public ?string $renamedFrom = null,
        public ?array $rules = null,
    ) {}
}
