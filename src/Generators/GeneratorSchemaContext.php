<?php

namespace SchemaCraft\Generators;

use Illuminate\Support\Str;
use SchemaCraft\Scanner\TableDefinition;

/**
 * Provides a schema context for use in Blade generator templates.
 *
 * ## Model name access via NameChain
 *
 * The `$model` property is a NameChain that replaces the old string helpers:
 *
 *     $schema->model->title        // "UserProfile"  (was $schema->ModelName)
 *     $schema->model->camel        // "userProfile"  (was $schema->modelName)
 *     $schema->model->snake        // "user_profile" (was $schema->model_name)
 *     $schema->model->plural->snake // "user_profiles" (was $schema->model_names)
 *     $schema->model->plural->title // "UserProfiles"
 *     $schema->model->kebab        // "user-profile"
 *
 * ## Columns
 *
 *     $schema->columns    // User-selected columns (or all if none selected)
 *     $schema->allColumns // All columns in the schema
 *
 * ## Relationships
 *
 *     $schema->relationships    // User-selected relationships (or all if none selected)
 *     $schema->allRelationships // All relationships in the schema
 */
class GeneratorSchemaContext
{
    /** NameChain for the model name, derived from the table name. */
    public readonly NameChain $model;

    /** Raw table name, e.g. 'user_profiles'. */
    public readonly string $tableName;

    /** @var GeneratorColumn[] User-selected columns (or all columns if none specified). */
    public readonly array $columns;

    /** @var GeneratorColumn[] All columns in the schema. */
    public readonly array $allColumns;

    /** @var GeneratorRelationship[] User-selected relationships (or all if none specified). */
    public readonly array $relationships;

    /** @var GeneratorRelationship[] All relationships in the schema. */
    public readonly array $allRelationships;

    /**
     * @param  string[]  $selectedColumnNames  Column names to include in $columns. Empty = all.
     * @param  string[]  $selectedRelationshipNames  Relationship names to include. Empty = all.
     */
    public function __construct(
        TableDefinition $table,
        array $selectedColumnNames = [],
        array $selectedRelationshipNames = [],
    ) {
        $singular = Str::singular($table->tableName);

        $this->tableName = $table->tableName;
        $this->model = new NameChain($singular);

        // Columns
        $allCols = array_map(fn ($col) => new GeneratorColumn($col), $table->columns);
        $this->allColumns = $allCols;

        if (empty($selectedColumnNames)) {
            $this->columns = $allCols;
        } else {
            $selected = array_filter($allCols, fn ($col) => in_array($col->name, $selectedColumnNames));
            $this->columns = array_values($selected);
        }

        // Relationships
        $allRels = array_map(fn ($rel) => new GeneratorRelationship($rel), $table->relationships);
        $this->allRelationships = $allRels;

        if (empty($selectedRelationshipNames)) {
            $this->relationships = $allRels;
        } else {
            $selected = array_filter($allRels, fn ($rel) => in_array($rel->definition->name, $selectedRelationshipNames));
            $this->relationships = array_values($selected);
        }
    }
}
