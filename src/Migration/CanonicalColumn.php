<?php

namespace SchemaCraft\Migration;

/**
 * Unified canonical representation of a database column.
 *
 * Both SchemaScanner (via TableDefinitionNormalizer) and DatabaseReader
 * (via DatabaseTableNormalizer) normalize into this format so that
 * SchemaDiffer compares identical types on both sides.
 */
class CanonicalColumn
{
    /**
     * Compound unsigned types → base type.
     *
     * @var array<string, string>
     */
    private const UNSIGNED_TYPE_MAP = [
        'unsignedBigInteger' => 'bigInteger',
        'unsignedInteger' => 'integer',
        'unsignedSmallInteger' => 'smallInteger',
        'unsignedTinyInteger' => 'tinyInteger',
        'unsignedMediumInteger' => 'mediumInteger',
    ];

    public function __construct(
        public string $name,
        public string $type,
        public bool $nullable = false,
        public mixed $default = null,
        public bool $hasDefault = false,
        public bool $unsigned = false,
        public ?int $length = null,
        public ?int $precision = null,
        public ?int $scale = null,
        public bool $unique = false,
        public bool $index = false,
        public bool $primary = false,
        public bool $autoIncrement = false,
        public ?string $expressionDefault = null,
    ) {}

    /**
     * Decompose a compound unsigned type into its base type + unsigned flag.
     *
     * e.g. 'unsignedBigInteger' → ['bigInteger', true]
     *      'integer'            → ['integer', false]  (unchanged)
     *
     * @return array{0: string, 1: bool}
     */
    public static function decomposeType(string $type, bool $unsigned): array
    {
        if (isset(self::UNSIGNED_TYPE_MAP[$type])) {
            return [self::UNSIGNED_TYPE_MAP[$type], true];
        }

        return [$type, $unsigned];
    }
}
