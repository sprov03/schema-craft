<?php

namespace SchemaCraft\Generators;

use Illuminate\Support\Str;
use SchemaCraft\Config\ConfigResolver;
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
 *
 * ## Schema metadata (from TableDefinition)
 *
 *     $schema->schemaClass   // "App\\Schemas\\PostSchema"
 *     $schema->modelClass    // "App\\Models\\Post" (derived from schema class)
 *     $schema->connection    // DB connection name or null
 *     $schema->fillable      // string[] of fillable attribute names
 *     $schema->hidden        // string[] of hidden attribute names
 *     $schema->defaultWith   // string[] of default eager-loaded relationships
 *     $schema->titleColumns  // string[] of column names marked with #[Title]
 *
 * ## Flags
 *
 *     $schema->hasTimestamps   // bool — uses created_at/updated_at
 *     $schema->hasSoftDeletes  // bool — uses deleted_at (SoftDeletesSchema or deleted_at column)
 *
 * ## Column lookups
 *
 *     $schema->primaryKey           // First GeneratorColumn with primary=true, or null
 *     $schema->titleColumn          // First column in titleColumns (falls back to primaryKey)
 *     $schema->column('email')      // Find a column by name or null
 *     $schema->hasColumn('email')   // bool
 *     $schema->relationship('author') // Find a relationship by name or null
 *
 * ## Filtered column sets
 *
 *     $schema->foreignKeyColumns() // Columns whose name ends in _id
 *     $schema->searchableColumns() // String/text columns, excluding FKs/PKs/timestamps/soft-delete
 *     $schema->fillableColumns()   // Columns whose name appears in $fillable
 */
class GeneratorSchemaContext
{
    /** NameChain for the model name, derived from the table name. */
    public readonly NameChain $model;

    /** Raw table name, e.g. 'user_profiles'. */
    public readonly string $tableName;

    /** FQCN of the scanned schema class, e.g. 'App\Schemas\PostSchema'. */
    public readonly string $schemaClass;

    /** FQCN of the Eloquent model, derived from the schema class. */
    public readonly string $modelClass;

    /** Actions namespace for this schema's connection, e.g. 'App\Models\Actions'. */
    public readonly string $actionsNamespace;

    /** Actions directory path relative to base_path(), e.g. 'app/Models/Actions'. */
    public readonly string $actionsPath;

    /** DB connection name, or null for default. */
    public readonly ?string $connection;

    /** @var string[] Fillable attribute names (from schema). */
    public readonly array $fillable;

    /** @var string[] Hidden attribute names (from schema). */
    public readonly array $hidden;

    /** @var string[] Default eager-loaded relationship names (from schema ->with). */
    public readonly array $defaultWith;

    /** @var string[] Column names marked with #[Title] — used as display name. */
    public readonly array $titleColumns;

    /** @var GeneratorColumn[] User-selected columns (or all columns if none specified). */
    public readonly array $columns;

    /** @var GeneratorColumn[] All columns in the schema. */
    public readonly array $allColumns;

    /** @var GeneratorRelationship[] User-selected relationships (or all if none specified). */
    public readonly array $relationships;

    /** @var GeneratorRelationship[] All relationships in the schema. */
    public readonly array $allRelationships;

    /** True if the schema uses timestamps (created_at/updated_at). */
    public readonly bool $hasTimestamps;

    /** True if the schema uses soft deletes (SoftDeletesSchema trait or a deleted_at column). */
    public readonly bool $hasSoftDeletes;

    /** First column marked primary, or null if none. */
    public readonly ?GeneratorColumn $primaryKey;

    /** First column in titleColumns as a GeneratorColumn, falls back to $primaryKey. */
    public readonly ?GeneratorColumn $titleColumn;

    /**
     * @param  string[]|null  $selectedColumnNames  Column names to include in $columns. Null = all, [] = none.
     * @param  string[]|null  $selectedRelationshipNames  Relationship names to include. Null = all, [] = none.
     */
    public function __construct(
        TableDefinition $table,
        ?array $selectedColumnNames,
        ?array $selectedRelationshipNames,
    ) {
        $singular = Str::singular($table->tableName);

        $this->tableName = $table->tableName;
        $this->model = new NameChain($singular);

        // Schema metadata
        $this->schemaClass = $table->schemaClass;
        $this->modelClass = $this->resolveModelClass($table->schemaClass);

        try {
            $connConfig = ConfigResolver::resolveForSchema($table->schemaClass);
            $this->actionsNamespace = $connConfig->actionNamespace;
            $this->actionsPath = $connConfig->actionDirectory();
        } catch (\Throwable) {
            $this->actionsNamespace = '';
            $this->actionsPath = '';
        }

        $this->connection = $table->connection;
        $this->fillable = $table->fillable;
        $this->hidden = $table->hidden;
        $this->defaultWith = $table->with;
        $this->titleColumns = $table->titleColumns;
        $this->hasTimestamps = $table->hasTimestamps;

        // Columns
        $allCols = array_map(fn ($col) => new GeneratorColumn($col), $table->columns);
        $this->allColumns = $allCols;

        // Soft deletes: trait flag OR a deleted_at column
        $hasDeletedAtColumn = false;
        foreach ($allCols as $col) {
            if ($col->name === 'deleted_at') {
                $hasDeletedAtColumn = true;
                break;
            }
        }
        $this->hasSoftDeletes = $table->hasSoftDeletes || $hasDeletedAtColumn;

        if ($selectedColumnNames === null) {
            $this->columns = $allCols;
        } else {
            $selected = array_filter($allCols, fn ($col) => in_array($col->name, $selectedColumnNames));
            $this->columns = array_values($selected);
        }

        // Relationships
        $allRels = array_map(fn ($rel) => new GeneratorRelationship($rel), $table->relationships);
        $this->allRelationships = $allRels;

        if ($selectedRelationshipNames === null) {
            $this->relationships = $allRels;
        } else {
            $selected = array_filter($allRels, fn ($rel) => in_array($rel->definition->name, $selectedRelationshipNames));
            $this->relationships = array_values($selected);
        }

        // Derived helpers
        $this->primaryKey = $this->resolvePrimaryKey($allCols);
        $this->titleColumn = $this->resolveTitleColumn($allCols, $table->titleColumns, $this->primaryKey);
    }

    /**
     * Look up a column by name, or return null if not found.
     */
    public function column(string $name): ?GeneratorColumn
    {
        foreach ($this->allColumns as $col) {
            if ($col->name === $name) {
                return $col;
            }
        }

        return null;
    }

    /**
     * Check if a column exists on this schema.
     */
    public function hasColumn(string $name): bool
    {
        return $this->column($name) !== null;
    }

    /**
     * Look up a relationship by name, or return null if not found.
     */
    public function relationship(string $name): ?GeneratorRelationship
    {
        foreach ($this->allRelationships as $rel) {
            if ($rel->definition->name === $name) {
                return $rel;
            }
        }

        return null;
    }

    /**
     * Columns ending with `_id` — FK candidates.
     *
     * @return GeneratorColumn[]
     */
    public function foreignKeyColumns(): array
    {
        return array_values(array_filter(
            $this->allColumns,
            fn (GeneratorColumn $col) => $col->isFK(),
        ));
    }

    /**
     * String/text columns excluding FKs, timestamps, primary keys, and soft-delete.
     *
     * Good default for "searchable" columns in Filament resources or APIs.
     *
     * @return GeneratorColumn[]
     */
    public function searchableColumns(): array
    {
        $stringTypes = ['string', 'text', 'mediumText', 'longText', 'char'];

        return array_values(array_filter(
            $this->allColumns,
            fn (GeneratorColumn $col) => in_array($col->columnType, $stringTypes, true)
                && ! $col->isFK()
                && ! $col->isPrimary()
                && ! $col->isTimestamp()
                && ! $col->isSoftDelete(),
        ));
    }

    /**
     * Columns whose name appears in the schema's $fillable array.
     *
     * @return GeneratorColumn[]
     */
    public function fillableColumns(): array
    {
        if (empty($this->fillable)) {
            return [];
        }

        return array_values(array_filter(
            $this->allColumns,
            fn (GeneratorColumn $col) => in_array($col->name, $this->fillable, true),
        ));
    }

    /**
     * Resolve the Eloquent model FQCN from the schema class.
     *
     * 1. If the schema class is loadable and declares a `public static string $model`,
     *    use that — this is the SchemaModel convention and is authoritative.
     * 2. Otherwise fall back to naming convention: strip a trailing `Schema` from
     *    the class name and swap a `Schemas` namespace segment for `Models`.
     *    e.g. `App\X\Schemas\FooSchema` → `App\X\Models\Foo`.
     */
    private function resolveModelClass(string $schemaClass): string
    {
        return self::modelClassFor($schemaClass);
    }

    /**
     * Derive the Eloquent model FQCN a schema maps to: an explicit static `model` property if
     * present, else {modelNamespace}\{SchemaBasename-without-Schema}. Static so callers can test
     * whether a schema is model-backed (class_exists on the result) without building a full
     * context — e.g. response-only schemas like a transient ActionResult have no such model.
     * Single source for the derivation rule.
     */
    /**
     * Whether a schema is an *entity* — i.e. tied to an Eloquent model. This is the canonical
     * test for "should this surface as a top-level, selectable thing": a schema is just a data
     * shape, an entity is a schema bound to a model. Schemas without a model (response shapes,
     * transient DTOs) still appear nested in docs/JSON, but are not selectable entities.
     *
     * Model-presence is the single source for this distinction — no separate #[Entity] marker —
     * so adding a model promotes a shape to an entity with nothing else to declare.
     */
    public static function isEntity(string $schemaClass): bool
    {
        return class_exists(self::modelClassFor($schemaClass));
    }

    public static function modelClassFor(string $schemaClass): string
    {
        if (class_exists($schemaClass)) {
            $refl = new \ReflectionClass($schemaClass);
            if ($refl->hasProperty('model')) {
                $prop = $refl->getProperty('model');
                if ($prop->isStatic() && $prop->isPublic()) {
                    $value = $prop->getDefaultValue();
                    if (is_string($value) && $value !== '') {
                        return $value;
                    }
                }
            }
        }

        $connConfig = ConfigResolver::resolveForSchema($schemaClass);
        $basename = (string) preg_replace('/Schema$/', '', class_basename($schemaClass));

        return $connConfig->modelNamespace.'\\'.$basename;
    }

    /**
     * @param  GeneratorColumn[]  $cols
     */
    private function resolvePrimaryKey(array $cols): ?GeneratorColumn
    {
        foreach ($cols as $col) {
            if ($col->primary) {
                return $col;
            }
        }

        return null;
    }

    /**
     * @param  GeneratorColumn[]  $cols
     * @param  string[]  $titleColumns
     */
    private function resolveTitleColumn(array $cols, array $titleColumns, ?GeneratorColumn $primaryKey): ?GeneratorColumn
    {
        foreach ($titleColumns as $name) {
            foreach ($cols as $col) {
                if ($col->name === $name) {
                    return $col;
                }
            }
        }

        return $primaryKey;
    }
}
