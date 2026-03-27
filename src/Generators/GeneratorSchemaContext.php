<?php

namespace SchemaCraft\Generators;

use Illuminate\Support\Str;
use SchemaCraft\Scanner\TableDefinition;

/**
 * Provides a schema context for use in Blade generator templates.
 *
 * Passed to templates as named variables (e.g. $schema, $relatedSchema) matching
 * the keys declared in the generator's schemas() method.
 *
 * Name helpers (ModelName, modelName, etc.) are derived from the table name.
 */
class GeneratorSchemaContext
{
    /** @var GeneratorColumn[] User-selected columns (or all columns if none specified) */
    public readonly array $columns;

    /** @var GeneratorColumn[] All columns in the schema */
    public readonly array $allColumns;

    /** StudlyCase singular model name, e.g. 'UserProfile' */
    public readonly string $ModelName;

    /** camelCase singular model name, e.g. 'userProfile' */
    public readonly string $modelName;

    /** snake_case singular model name, e.g. 'user_profile' */
    public readonly string $model_name;

    /** snake_case plural model name, e.g. 'user_profiles' */
    public readonly string $model_names;

    /** Raw table name, e.g. 'user_profiles' */
    public readonly string $tableName;

    /**
     * @param  string[]  $selectedColumnNames  Column names to include in $columns. Empty = all.
     */
    public function __construct(TableDefinition $table, array $selectedColumnNames = [])
    {
        $singular = Str::singular($table->tableName);

        $this->tableName = $table->tableName;
        $this->ModelName = Str::studly($singular);
        $this->modelName = Str::camel($singular);
        $this->model_name = Str::snake($singular);
        $this->model_names = Str::snake(Str::plural($table->tableName));

        $all = array_map(fn ($col) => new GeneratorColumn($col), $table->columns);
        $this->allColumns = $all;

        if (empty($selectedColumnNames)) {
            $this->columns = $all;
        } else {
            $selected = array_filter($all, fn ($col) => in_array($col->name, $selectedColumnNames));
            $this->columns = array_values($selected);
        }
    }
}
