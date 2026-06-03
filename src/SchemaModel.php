<?php

namespace SchemaCraft;

use Illuminate\Database\Eloquent\Attributes\Initialize;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use SchemaCraft\Scanner\RelationshipDefinition;
use SchemaCraft\Scanner\SchemaScanner;
use SchemaCraft\Scanner\TableDefinition;

/**
 * Base model class for SchemaCraft models.
 *
 * Extend this class and set `protected static string $schema` to your
 * schema class. At boot time, this class reads the schema and auto-registers
 * casts, relationships, and attribute defaults on the Eloquent model.
 */
abstract class SchemaModel extends Model
{
    /**
     * The schema class that defines this model's database structure.
     *
     * @var class-string<Schema>
     */
    protected static string $schema;

    /**
     * Cache of scanned schema definitions, keyed by schema class name.
     *
     * @var array<string, TableDefinition>
     */
    private static array $schemaCache = [];

    /**
     * Register schema-defined relationships on the model.
     *
     * This runs once per class (static). Relationships are registered via
     * resolveRelationUsing() which works with both property access ($model->rel)
     * and method calls ($model->rel()), as well as eager loading.
     *
     * Model-defined methods always take precedence because Eloquent checks
     * method_exists() before checking relationResolver().
     */
    protected static function booted(): void
    {
        parent::booted();

        if (! isset(static::$schema)) {
            return;
        }

        $tableDefinition = static::resolveSchema();

        foreach ($tableDefinition->relationships as $rel) {
            if (method_exists(static::class, $rel->name)) {
                continue;
            }

            static::resolveRelationUsing($rel->name, function (Model $model) use ($rel) {
                return static::buildRelation($model, $rel);
            });
        }
    }

    /**
     * Initialize schema-derived casts, defaults, and Eloquent config per instance.
     *
     * Uses the #[Initialize] attribute so Eloquent's bootTraits() auto-discovers
     * this method even though SchemaModel is a class, not a trait.
     */
    #[Initialize]
    public function initializeSchemaModel(): void
    {
        if (! isset(static::$schema)) {
            return;
        }

        $tableDefinition = static::resolveSchema();

        foreach ($tableDefinition->columns as $col) {
            if ($col->castType === null || isset($this->casts[$col->name])) {
                continue;
            }

            // All recognized column types now carry their cast logic via class identity —
            // Laravel's $casts entry is just the bare class name. DataSchema subclasses
            // implement CastsAttributes directly; Bitmask subclasses too; Collection primitive
            // subclasses implement Castable (forced by the Collection::get vs CastsAttributes::get
            // signature collision) but the cast handler reads the subclass's own #[CollectionOf]
            // via reflection at castUsing time — still no boot-time directive parameterization.
            $this->casts[$col->name] = $col->castType;
        }

        foreach ($tableDefinition->columns as $col) {
            if ($col->hasDefault && ! array_key_exists($col->name, $this->attributes)) {
                $default = $col->default;

                if (is_array($default)) {
                    $default = json_encode($default);
                }

                $this->attributes[$col->name] = $default;
            }
        }

        if (! $tableDefinition->hasTimestamps) {
            $this->timestamps = false;
        }

        if (! empty($tableDefinition->fillable) && empty($this->fillable)) {
            $this->fillable = $tableDefinition->fillable;
        }

        if (! empty($tableDefinition->hidden) && empty($this->hidden)) {
            $this->hidden = $tableDefinition->hidden;
        }

        if (! empty($tableDefinition->with) && empty($this->with)) {
            $this->with = $tableDefinition->with;
        }
    }

    /**
     * Get the table associated with the model.
     *
     * Derives the table name from the schema class instead of the model class name.
     */
    public function getTable(): string
    {
        if (isset($this->table)) {
            return $this->table;
        }

        if (isset(static::$schema)) {
            return static::resolveSchema()->tableName;
        }

        return parent::getTable();
    }

    /**
     * Resolve a dynamic relation resolver, with schema fallback.
     *
     * If the standard resolver lookup misses (e.g. booted() failed or was
     * skipped), fall back to the schema to find the relationship and
     * self-heal by registering the resolver for future calls.
     *
     * @param  class-string  $class
     * @param  string  $key
     * @return \Closure|null
     */
    public function relationResolver($class, $key)
    {
        $resolver = parent::relationResolver($class, $key);

        if ($resolver !== null) {
            return $resolver;
        }

        if (! isset(static::$schema)) {
            return null;
        }

        try {
            $tableDefinition = static::resolveSchema();
        } catch (\Throwable) {
            return null;
        }

        foreach ($tableDefinition->relationships as $rel) {
            if ($rel->name === $key) {
                $callback = function (Model $model) use ($rel) {
                    return static::buildRelation($model, $rel);
                };

                // Self-heal: register so this fallback isn't needed again
                static::resolveRelationUsing($key, $callback);

                return $callback;
            }
        }

        return null;
    }

    /**
     * Resolve and cache the schema definition for this model's schema class.
     */
    protected static function resolveSchema(): TableDefinition
    {
        if (! isset(self::$schemaCache[static::$schema])) {
            self::$schemaCache[static::$schema] = (new SchemaScanner(static::$schema))->scan();
        }

        return self::$schemaCache[static::$schema];
    }

    /**
     * Build an Eloquent relationship from a RelationshipDefinition.
     */
    protected static function buildRelation(Model $model, RelationshipDefinition $rel): mixed
    {
        return match ($rel->type) {
            'belongsTo' => $model->belongsTo(
                $rel->relatedModel,
                $rel->foreignColumn ?? Str::snake($rel->name).'_id',
                $rel->ownerKey,
            ),
            'hasOne' => $model->hasOne(
                $rel->relatedModel,
                $rel->foreignColumn,
                $rel->localKey,
            ),
            'hasMany' => $model->hasMany(
                $rel->relatedModel,
                $rel->foreignColumn,
                $rel->localKey,
            ),
            'belongsToMany' => static::buildBelongsToMany($model, $rel),
            'morphTo' => $model->morphTo(
                $rel->morphName ?? $rel->name,
            ),
            'morphOne' => $model->morphOne(
                $rel->relatedModel,
                $rel->morphName ?? $rel->name,
                null,
                null,
                $rel->localKey,
            ),
            'morphMany' => $model->morphMany(
                $rel->relatedModel,
                $rel->morphName ?? $rel->name,
                null,
                null,
                $rel->localKey,
            ),
            'morphToMany' => static::buildMorphToMany($model, $rel),
            'hasOneThrough' => $model->hasOneThrough(
                $rel->relatedModel,
                $rel->through,
                $rel->firstKey,
                $rel->secondKey,
                $rel->localKey,
                $rel->secondLocalKey,
            ),
            'hasManyThrough' => $model->hasManyThrough(
                $rel->relatedModel,
                $rel->through,
                $rel->firstKey,
                $rel->secondKey,
                $rel->localKey,
                $rel->secondLocalKey,
            ),
        };
    }

    /**
     * Build a BelongsToMany relationship with optional pivot configuration.
     */
    private static function buildBelongsToMany(Model $model, RelationshipDefinition $rel): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        $relation = $model->belongsToMany(
            $rel->relatedModel,
            $rel->pivotTable,
            $rel->foreignPivotKey,
            $rel->relatedPivotKey,
            $rel->parentKey,
            $rel->relatedKey,
        );

        if ($rel->pivotModel !== null) {
            $relation->using($rel->pivotModel);
        }

        if ($rel->pivotColumns !== null) {
            $relation->withPivot(array_keys($rel->pivotColumns));
        }

        return $relation;
    }

    private static function buildMorphToMany(Model $model, RelationshipDefinition $rel): \Illuminate\Database\Eloquent\Relations\MorphToMany
    {
        $relation = $model->morphToMany(
            $rel->relatedModel,
            $rel->morphName ?? $rel->name,
            $rel->pivotTable,
            $rel->foreignPivotKey,
            $rel->relatedPivotKey,
            $rel->parentKey,
            $rel->relatedKey,
            $rel->inverse,
        );

        if ($rel->pivotModel !== null) {
            $relation->using($rel->pivotModel);
        }

        if ($rel->pivotColumns !== null) {
            $relation->withPivot(array_keys($rel->pivotColumns));
        }

        return $relation;
    }

    /**
     * Clear the schema cache. Useful for testing.
     */
    public static function clearSchemaCache(): void
    {
        self::$schemaCache = [];
    }
}
