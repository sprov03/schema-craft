<?php

namespace SchemaCraft\Migration;

use Illuminate\Support\Facades\Schema;

/**
 * Reads actual database state using Laravel's Schema facade
 * and normalizes it into DatabaseTableState value objects.
 */
class DatabaseReader
{
    public function __construct(
        private ?string $connection = null,
    ) {}

    /**
     * Read a single table's full state, or null if the table doesn't exist.
     */
    public function read(string $tableName): ?DatabaseTableState
    {
        $schema = $this->schema();

        if (! $schema->hasTable($tableName)) {
            return null;
        }

        return new DatabaseTableState(
            tableName: $tableName,
            columns: $this->readColumns($tableName),
            indexes: $this->readIndexes($tableName),
            foreignKeys: $this->readForeignKeys($tableName),
        );
    }

    /**
     * List all table names in the current database.
     *
     * MySQL/MariaDB/PostgreSQL users may have access to tables across multiple
     * databases. This method filters to only the current database's tables
     * using schema-qualified names.
     *
     * @return string[]
     */
    public function tables(): array
    {
        $schema = $this->schema();
        $driver = $schema->getConnection()->getDriverName();

        // MySQL/MariaDB/PostgreSQL can see tables across multiple databases.
        // Filter to only the current database to avoid listing foreign tables.
        if (in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            $currentDb = $schema->getConnection()->getDatabaseName();
            $prefix = $currentDb.'.';
            $qualified = $schema->getTableListing(schemaQualified: true);

            $names = [];
            foreach ($qualified as $qualifiedName) {
                if (str_starts_with($qualifiedName, $prefix)) {
                    $names[] = substr($qualifiedName, strlen($prefix));
                }
            }

            return array_values(array_unique($names));
        }

        // For SQLite and other drivers, all tables belong to the current database
        return array_values(array_unique(
            $schema->getTableListing(schemaQualified: false)
        ));
    }

    /**
     * @return DatabaseColumnState[]
     */
    private function readColumns(string $tableName): array
    {
        $rawColumns = $this->schema()->getColumns($tableName);
        $indexes = $this->schema()->getIndexes($tableName);

        // Build a set of primary key column names
        $primaryColumns = [];

        foreach ($indexes as $index) {
            if ($index['primary'] ?? false) {
                foreach ($index['columns'] as $col) {
                    $primaryColumns[$col] = true;
                }
            }
        }

        $columns = [];

        foreach ($rawColumns as $raw) {
            $typeName = $raw['type_name'] ?? '';
            $fullType = $raw['type'] ?? $typeName;
            $canonicalType = ColumnTypeMap::normalize($typeName);

            // Parse length/precision/scale from the full type string
            $params = ColumnTypeMap::parseTypeParams($fullType);
            $length = $params['length'] ?? null;
            $precision = $params['precision'] ?? null;
            $scale = $params['scale'] ?? null;

            // Detect unsigned from the full type string
            $unsigned = ColumnTypeMap::isUnsigned($fullType);

            // Detect auto-increment
            $autoIncrement = $raw['auto_increment'] ?? false;

            // Auto-increment integer-family columns are always unsignedBigInteger PKs
            // SQLite reports 'integer', MySQL reports 'bigint' (→ bigInteger)
            $integerFamily = ['integer', 'bigInteger', 'smallInteger', 'tinyInteger'];

            if ($autoIncrement && in_array($canonicalType, $integerFamily, true)) {
                $canonicalType = 'unsignedBigInteger';
                $unsigned = true;
            }

            // Normalize unsigned integer types to their unsigned canonical form
            // so the type vocabulary matches SchemaScanner (e.g. 'unsignedBigInteger')
            if ($unsigned && ! $autoIncrement && in_array($canonicalType, $integerFamily, true)) {
                $canonicalType = match ($canonicalType) {
                    'bigInteger' => 'unsignedBigInteger',
                    'integer' => 'unsignedInteger',
                    'smallInteger' => 'unsignedSmallInteger',
                    'tinyInteger' => 'unsignedTinyInteger',
                    default => $canonicalType,
                };
            }

            // Determine default value
            $rawDefault = $raw['default'] ?? null;
            $hasDefault = $rawDefault !== null;
            $default = null;
            $expressionDefault = null;

            if ($hasDefault) {
                if ($this->isExpressionDefault($rawDefault)) {
                    $expressionDefault = $this->normalizeExpressionDefault($rawDefault);
                    $hasDefault = false;
                } else {
                    $default = $this->normalizeDefault($rawDefault, $canonicalType);
                }
            }

            $columns[] = new DatabaseColumnState(
                name: $raw['name'],
                type: $canonicalType,
                nullable: $raw['nullable'] ?? false,
                default: $default,
                hasDefault: $hasDefault,
                unsigned: $unsigned,
                length: $length,
                precision: $precision,
                scale: $scale,
                primary: isset($primaryColumns[$raw['name']]),
                autoIncrement: $autoIncrement,
                expressionDefault: $expressionDefault,
            );
        }

        return $columns;
    }

    /**
     * @return DatabaseIndexState[]
     */
    private function readIndexes(string $tableName): array
    {
        $rawIndexes = $this->schema()->getIndexes($tableName);
        $indexes = [];

        foreach ($rawIndexes as $raw) {
            $indexes[] = new DatabaseIndexState(
                name: $raw['name'] ?? '',
                columns: $raw['columns'] ?? [],
                unique: $raw['unique'] ?? false,
                primary: $raw['primary'] ?? false,
            );
        }

        return $indexes;
    }

    /**
     * @return DatabaseForeignKeyState[]
     */
    private function readForeignKeys(string $tableName): array
    {
        $rawFks = $this->schema()->getForeignKeys($tableName);
        $foreignKeys = [];

        foreach ($rawFks as $raw) {
            $columns = $raw['columns'] ?? [];
            $foreignColumns = $raw['foreign_columns'] ?? [];

            // We handle single-column FKs. Multi-column FKs are uncommon.
            if (count($columns) === 1 && count($foreignColumns) === 1) {
                $foreignKeys[] = new DatabaseForeignKeyState(
                    column: $columns[0],
                    foreignTable: $raw['foreign_table'] ?? '',
                    foreignColumn: $foreignColumns[0],
                    onDelete: strtolower($raw['on_delete'] ?? 'no action'),
                    onUpdate: strtolower($raw['on_update'] ?? 'no action'),
                );
            }
        }

        return $foreignKeys;
    }

    /**
     * Normalize a raw SQL default value to a PHP-comparable form.
     */
    private function normalizeDefault(mixed $rawDefault, string $canonicalType): mixed
    {
        if ($rawDefault === null) {
            return null;
        }

        // Strip SQL quoting: 'value' → value, "value" → value
        $value = is_string($rawDefault)
            ? trim($rawDefault, "'\"")
            : $rawDefault;

        // Handle SQL NULL literal
        if (is_string($value) && strtoupper($value) === 'NULL') {
            return null;
        }

        // Type coerce based on canonical type
        return match ($canonicalType) {
            'integer', 'bigInteger', 'smallInteger', 'tinyInteger', 'unsignedBigInteger' => is_numeric($value) ? (int) $value : $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $value,
            'double', 'float', 'decimal' => is_numeric($value) ? (float) $value : $value,
            default => $value,
        };
    }

    /**
     * Check if a raw default value is an SQL expression (e.g., CURRENT_TIMESTAMP, NOW()).
     */
    private function isExpressionDefault(mixed $rawDefault): bool
    {
        if (! is_string($rawDefault)) {
            return false;
        }

        $upper = strtoupper(trim($rawDefault, "'\""));

        return str_contains($upper, 'CURRENT_TIMESTAMP')
            || str_contains($upper, 'NOW()');
    }

    /**
     * Normalize an SQL expression default to a canonical form.
     */
    private function normalizeExpressionDefault(string $rawDefault): string
    {
        $value = trim($rawDefault, "'\"");
        $upper = strtoupper($value);

        if ($upper === 'NOW()' || str_contains($upper, 'CURRENT_TIMESTAMP')) {
            return 'CURRENT_TIMESTAMP';
        }

        return $value;
    }

    /**
     * Get the Schema builder, optionally for a specific connection.
     */
    private function schema(): \Illuminate\Database\Schema\Builder
    {
        if ($this->connection !== null) {
            return Schema::connection($this->connection);
        }

        return Schema::getFacadeRoot();
    }
}
