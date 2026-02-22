<?php

namespace SchemaCraft;

use Illuminate\Support\Str;
use SchemaCraft\Scanner\SchemaScanner;
use SchemaCraft\Scanner\TableDefinition;
use SchemaCraft\Validation\RuleSet;
use SchemaCraft\Validation\ValidationRuleMapper;

/**
 * Abstract base class for schema definitions.
 *
 * Extend this class to define your database schema using typed properties.
 * This class is never instantiated as a model — it exists purely for
 * schema scanning and IDE type hinting via @mixin on the model.
 */
abstract class Schema
{
    /** @var array<class-string<Schema>, TableDefinition> */
    private static array $scanCache = [];

    /**
     * The table name for this schema.
     *
     * Return null to use convention-based naming (class name → plural snake_case).
     * For example, ProductSchema → "products", UserProfileSchema → "user_profiles".
     */
    public static function tableName(): ?string
    {
        return null;
    }

    /**
     * Get validation rules for creating a new record.
     *
     * @param  string[]  $fields  Column names to include in the rules
     */
    public static function createRules(array $fields): RuleSet
    {
        return static::buildRuleSet($fields, 'create');
    }

    /**
     * Get validation rules for updating an existing record.
     *
     * @param  string[]  $fields  Column names to include in the rules
     */
    public static function updateRules(array $fields): RuleSet
    {
        return static::buildRuleSet($fields, 'update');
    }

    private static function buildRuleSet(array $fields, string $context): RuleSet
    {
        $table = static::scanCached();
        $mapper = new ValidationRuleMapper($table->tableName, $table->relationships);
        $modelVariable = static::resolveModelVariable();

        $rules = [];

        // Build a column lookup by name
        $columnMap = [];
        foreach ($table->columns as $column) {
            $columnMap[$column->name] = $column;
        }

        foreach ($fields as $field) {
            if (! isset($columnMap[$field])) {
                continue;
            }

            $column = $columnMap[$field];

            // Skip columns that should never have validation rules
            if ($column->primary || $column->autoIncrement) {
                continue;
            }

            if ($context === 'update') {
                $rules[$field] = $mapper->updateRules($column, $modelVariable);
            } else {
                $rules[$field] = $mapper->createRules($column);
            }
        }

        return new RuleSet($rules);
    }

    private static function scanCached(): TableDefinition
    {
        $class = static::class;

        if (! isset(self::$scanCache[$class])) {
            self::$scanCache[$class] = (new SchemaScanner($class))->scan();
        }

        return self::$scanCache[$class];
    }

    private static function resolveModelVariable(): string
    {
        $className = class_basename(static::class);
        $baseName = Str::beforeLast($className, 'Schema');

        if ($baseName === $className) {
            $baseName = $className;
        }

        return Str::camel($baseName);
    }

    /**
     * Clear the scan cache. Useful in tests.
     */
    public static function clearScanCache(): void
    {
        self::$scanCache = [];
    }
}
