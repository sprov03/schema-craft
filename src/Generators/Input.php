<?php

namespace SchemaCraft\Generators;

class Input
{
    public static function text(string $key, string $label): InputDefinition
    {
        return new InputDefinition(key: $key, label: $label, type: 'text');
    }

    public static function select(string $key, string $label, array $options): InputDefinition
    {
        return new InputDefinition(key: $key, label: $label, type: 'select', options: $options);
    }

    public static function boolean(string $key, string $label, bool $default = false): InputDefinition
    {
        return new InputDefinition(key: $key, label: $label, type: 'boolean', default: $default);
    }

    public static function schemaColumn(string $key, string $label, string $schemaKey = 'schema'): InputDefinition
    {
        return new InputDefinition(key: $key, label: $label, type: 'schemaColumn', schemaKey: $schemaKey);
    }

    public static function schemaColumns(string $key, string $label, string $schemaKey = 'schema'): InputDefinition
    {
        return new InputDefinition(key: $key, label: $label, type: 'schemaColumns', schemaKey: $schemaKey);
    }
}
