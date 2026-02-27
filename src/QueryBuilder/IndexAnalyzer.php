<?php

namespace SchemaCraft\QueryBuilder;

use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Scanner\SchemaScanner;
use SchemaCraft\Scanner\TableDefinition;

/**
 * Analyzes a QueryDefinition to detect columns used in JOINs and WHERE clauses
 * that lack indexes. Returns suggestions for adding indexes to improve performance
 * on large datasets.
 */
class IndexAnalyzer
{
    /**
     * Cached table definitions by schema class.
     *
     * @var array<string, TableDefinition>
     */
    private array $tableCache = [];

    /**
     * Pre-populate the table cache with a scanned schema.
     *
     * Useful for testing and for callers that already have the TableDefinition.
     */
    public function registerTable(string $schemaClass, TableDefinition $table): void
    {
        $this->tableCache[$schemaClass] = $table;
    }

    /**
     * Analyze a query definition for missing indexes.
     *
     * @return array<int, array{schema: string, column: string, reason: string}>
     */
    public function analyze(QueryDefinition $query): array
    {
        $suggestions = [];

        // Check JOIN columns on the base table
        foreach ($query->joins as $join) {
            $localSuggestion = $this->checkColumn(
                $query->baseSchema,
                $query->baseTable,
                $join->localColumn,
                "Used in {$join->type} JOIN with {$join->table}",
            );

            if ($localSuggestion !== null) {
                $suggestions[] = $localSuggestion;
            }

            // Check the foreign column on the joined table
            if ($join->schema !== null) {
                $foreignSuggestion = $this->checkColumn(
                    $join->schema,
                    $join->table,
                    $join->foreignColumn,
                    "Used as JOIN target from {$query->baseTable}",
                );

                if ($foreignSuggestion !== null) {
                    $suggestions[] = $foreignSuggestion;
                }
            }
        }

        // Check WHERE columns (all leaf conditions from the tree)
        foreach ($query->allConditionNodes() as $condition) {
            $tableName = $this->extractTableName($condition->column, $query->baseTable);
            $columnName = $this->extractColumnName($condition->column);
            $schema = $this->resolveSchemaForTable($tableName, $query);

            if ($schema === null) {
                continue;
            }

            $suggestion = $this->checkColumn(
                $schema,
                $tableName,
                $columnName,
                'Used in WHERE clause without index',
            );

            if ($suggestion !== null) {
                $suggestions[] = $suggestion;
            }
        }

        return $suggestions;
    }

    /**
     * Check if a column has an index and return a suggestion if not.
     *
     * @return array{schema: string, column: string, reason: string}|null
     */
    private function checkColumn(
        ?string $schemaClass,
        string $tableName,
        string $columnName,
        string $reason,
    ): ?array {
        if ($schemaClass === null) {
            return null;
        }

        $table = $this->scanSchema($schemaClass);

        if ($table === null) {
            return null;
        }

        $column = $this->findColumn($table, $columnName);

        if ($column === null) {
            return null;
        }

        // Skip primary keys — they already have an index
        if ($column->primary) {
            return null;
        }

        // Skip columns that already have an index or unique constraint
        if ($column->index || $column->unique) {
            return null;
        }

        return [
            'schema' => $schemaClass,
            'column' => $columnName,
            'reason' => $reason,
        ];
    }

    /**
     * Scan a schema class and cache the result.
     */
    private function scanSchema(string $schemaClass): ?TableDefinition
    {
        if (isset($this->tableCache[$schemaClass])) {
            return $this->tableCache[$schemaClass];
        }

        if (! class_exists($schemaClass)) {
            return null;
        }

        $scanner = new SchemaScanner($schemaClass);
        $table = $scanner->scan();

        $this->tableCache[$schemaClass] = $table;

        return $table;
    }

    /**
     * Find a column definition by name.
     */
    private function findColumn(TableDefinition $table, string $columnName): ?ColumnDefinition
    {
        foreach ($table->columns as $column) {
            if ($column->name === $columnName) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Extract the table name from a potentially qualified column name.
     *
     * "posts.status" → "posts", "status" → $defaultTable
     */
    private function extractTableName(string $column, string $defaultTable): string
    {
        if (str_contains($column, '.')) {
            return substr($column, 0, strpos($column, '.'));
        }

        return $defaultTable;
    }

    /**
     * Extract the column name from a potentially qualified column name.
     *
     * "posts.status" → "status", "status" → "status"
     */
    private function extractColumnName(string $column): string
    {
        if (str_contains($column, '.')) {
            return substr($column, strpos($column, '.') + 1);
        }

        return $column;
    }

    /**
     * Resolve the schema class for a given table name in the context of a query.
     */
    private function resolveSchemaForTable(string $tableName, QueryDefinition $query): ?string
    {
        if ($tableName === $query->baseTable) {
            return $query->baseSchema;
        }

        foreach ($query->joins as $join) {
            if ($join->table === $tableName) {
                return $join->schema;
            }
        }

        return null;
    }
}
