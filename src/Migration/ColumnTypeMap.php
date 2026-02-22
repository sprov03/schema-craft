<?php

namespace SchemaCraft\Migration;

/**
 * Maps database type names to SchemaCraft's canonical type vocabulary
 * and provides Blueprint method name lookups.
 *
 * The canonical types are the same strings used by SchemaScanner's
 * ColumnDefinition::$columnType — e.g. 'string', 'integer', 'text', etc.
 */
class ColumnTypeMap
{
    /**
     * Database type_name → canonical (scanner) type.
     *
     * @var array<string, string>
     */
    private const DB_TO_CANONICAL = [
        // String types
        'varchar' => 'string',
        'character varying' => 'string',
        'char' => 'string',
        'character' => 'string',

        // Text types
        'text' => 'text',
        'tinytext' => 'text',
        'mediumtext' => 'mediumText',
        'longtext' => 'longText',

        // Integer types
        'integer' => 'integer',
        'int' => 'integer',
        'int4' => 'integer',
        'bigint' => 'bigInteger',
        'int8' => 'bigInteger',
        'smallint' => 'smallInteger',
        'int2' => 'smallInteger',
        'tinyint' => 'tinyInteger',
        'mediumint' => 'integer',

        // Boolean
        'boolean' => 'boolean',
        'bool' => 'boolean',

        // Float / Double / Decimal
        'double' => 'double',
        'double precision' => 'double',
        'float8' => 'double',
        'float' => 'float',
        'real' => 'float',
        'float4' => 'float',
        'decimal' => 'decimal',
        'numeric' => 'decimal',

        // JSON
        'json' => 'json',
        'jsonb' => 'json',

        // Date / Time
        'timestamp' => 'timestamp',
        'timestamp without time zone' => 'timestamp',
        'timestamp with time zone' => 'timestamp',
        'datetime' => 'timestamp',
        'date' => 'date',
        'time' => 'time',
        'time without time zone' => 'time',
        'time with time zone' => 'time',
        'year' => 'year',

        // UUID / ULID
        'uuid' => 'uuid',
        'ulid' => 'ulid',

        // Binary
        'blob' => 'binary',
        'binary' => 'binary',
        'varbinary' => 'binary',

        // Enum / Set
        'enum' => 'string',
        'set' => 'string',
    ];

    /**
     * Canonical type → Blueprint method name.
     *
     * @var array<string, string>
     */
    private const CANONICAL_TO_BLUEPRINT = [
        'string' => 'string',
        'text' => 'text',
        'mediumText' => 'mediumText',
        'longText' => 'longText',
        'integer' => 'integer',
        'unsignedBigInteger' => 'unsignedBigInteger',
        'unsignedInteger' => 'unsignedInteger',
        'unsignedSmallInteger' => 'unsignedSmallInteger',
        'unsignedTinyInteger' => 'unsignedTinyInteger',
        'bigInteger' => 'bigInteger',
        'smallInteger' => 'smallInteger',
        'tinyInteger' => 'tinyInteger',
        'boolean' => 'boolean',
        'double' => 'double',
        'float' => 'float',
        'decimal' => 'decimal',
        'json' => 'json',
        'timestamp' => 'timestamp',
        'date' => 'date',
        'time' => 'time',
        'year' => 'year',
        'uuid' => 'uuid',
        'ulid' => 'ulid',
        'binary' => 'binary',
    ];

    /**
     * Normalize a database type_name into the canonical scanner vocabulary.
     */
    public static function normalize(string $dbType): string
    {
        $lower = strtolower(trim($dbType));

        // Direct lookup first
        if (isset(self::DB_TO_CANONICAL[$lower])) {
            return self::DB_TO_CANONICAL[$lower];
        }

        // Handle MySQL tinyint(1) → boolean
        if (str_starts_with($lower, 'tinyint(1)')) {
            return 'boolean';
        }

        // Handle parameterized types: strip params and try again
        // e.g. 'varchar(255)' → 'varchar', 'decimal(10,2)' → 'decimal'
        $base = preg_replace('/\(.*\)/', '', $lower);

        if (isset(self::DB_TO_CANONICAL[$base])) {
            return self::DB_TO_CANONICAL[$base];
        }

        // Fallback: return the lower-cased type as-is
        return $lower;
    }

    /**
     * Get the Blueprint method name for a canonical column type.
     */
    public static function toBlueprintMethod(string $canonicalType): string
    {
        return self::CANONICAL_TO_BLUEPRINT[$canonicalType] ?? $canonicalType;
    }

    /**
     * Parse length, precision, and scale from a full DB type string.
     *
     * Examples:
     *   'varchar(100)' → ['length' => 100]
     *   'decimal(10,2)' → ['precision' => 10, 'scale' => 2]
     *   'integer'       → []
     *
     * @return array{length?: int, precision?: int, scale?: int}
     */
    public static function parseTypeParams(string $fullType): array
    {
        if (! preg_match('/\(([^)]+)\)/', $fullType, $matches)) {
            return [];
        }

        $params = $matches[1];
        $parts = array_map('trim', explode(',', $params));

        if (count($parts) === 2) {
            return [
                'precision' => (int) $parts[0],
                'scale' => (int) $parts[1],
            ];
        }

        if (count($parts) === 1 && is_numeric($parts[0])) {
            return ['length' => (int) $parts[0]];
        }

        return [];
    }

    /**
     * Check if a DB type name represents an unsigned integer type.
     * MySQL prefixes unsigned types; PostgreSQL and SQLite don't support unsigned.
     */
    public static function isUnsigned(string $fullType): bool
    {
        return str_contains(strtolower($fullType), 'unsigned');
    }
}
