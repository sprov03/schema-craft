<?php

namespace SchemaCraft\Generator\Sdk;

use Illuminate\Support\Str;
use SchemaCraft\Generator\Api\GeneratedFile;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Scanner\RelationshipDefinition;
use SchemaCraft\Scanner\SchemaResolver;
use SchemaCraft\Scanner\TableDefinition;

/**
 * Emits flat, self-contained, read-only Eloquent model classes INTO the SDK package.
 *
 * Why this exists separately from the schema-craft model generator: the models schema-craft
 * normally generates are thin runtime shells (`extends BaseModel` + `$schema = FooSchema::class`)
 * whose casts/relations/table are resolved at runtime by SchemaModel reading the schema. That
 * machinery requires schema-craft to be installed. A consuming project does NOT have schema-craft,
 * so the exported model must be a plain Eloquent class with everything written out statically and
 * no schema-craft dependency. Hence a sibling emitter, not a parameterization of SchemaFileGenerator.
 *
 * Consumes the same SdkSchemaContext map as SdkGenerator so model export rides along with the SDK
 * export (one package: API client + read-only models).
 */
class SdkModelGenerator
{
    /**
     * @param  array<string, SdkSchemaContext>  $schemas  Keyed by model name
     * @param  string  $sourceModelNamespace  The source model-namespace root to strip (e.g.
     *                                         'App\Models'). Each model's relative sub-namespace
     *                                         under this root is preserved and re-rooted under the
     *                                         SDK base, so same-named models from different databases
     *                                         don't collide. '' = flat (all models directly under base).
     * @return array<string, GeneratedFile>
     */
    public function generate(array $schemas, string $namespace = 'MyApp\\Sdk', string $sourceModelNamespace = ''): array
    {
        $modelsBase = $namespace.'\\Models';
        $files = [];

        // Shared read-only base — shipped once per package. Exported models extend it so every
        // write entry point throws (the consuming project mutates via the API/SDK, not these models).
        $files['model_base'] = new GeneratedFile(
            path: 'src/Models/ReadOnlyModel.php',
            content: $this->buildReadOnlyBase($modelsBase),
        );

        foreach ($schemas as $modelName => $context) {
            [$subNamespace, $className] = $this->placement(
                $context->table->schemaClass,
                $modelName,
                $sourceModelNamespace,
            );

            $targetNamespace = $modelsBase.($subNamespace !== '' ? '\\'.$subNamespace : '');
            $subPath = $subNamespace !== '' ? str_replace('\\', '/', $subNamespace).'/' : '';

            $files["model_{$modelName}"] = new GeneratedFile(
                path: "src/Models/{$subPath}{$className}.php",
                content: $this->buildModel($context->table, $modelsBase, $targetNamespace, $className, $sourceModelNamespace),
            );
        }

        return $files;
    }

    /**
     * Resolve a schema's export placement: [relative sub-namespace, class name].
     *
     * In flat mode (no source root) we keep the map key as the class name. Otherwise we resolve the
     * schema's conventional model FQCN and strip the source root to recover the relative path.
     *
     * @return array{0: string, 1: string}
     */
    private function placement(string $schemaClass, string $fallbackName, string $modelRoot): array
    {
        if ($modelRoot === '') {
            return ['', $fallbackName];
        }

        return $this->relativePlacement(SchemaResolver::schemaToModelFqcn($schemaClass), $modelRoot);
    }

    /**
     * Split a model FQCN into [sub-namespace relative to $root, class basename].
     * A FQCN outside $root yields an empty sub-namespace (placed flat under the base).
     *
     * @return array{0: string, 1: string}
     */
    private function relativePlacement(string $fqcn, string $root): array
    {
        $className = $this->classBasename($fqcn);
        $namespace = ($pos = strrpos($fqcn, '\\')) !== false ? substr($fqcn, 0, $pos) : '';
        $root = trim($root, '\\');

        $sub = '';
        if ($root !== '' && ($namespace === $root || str_starts_with($namespace, $root.'\\'))) {
            $sub = ltrim(substr($namespace, strlen($root)), '\\');
        }

        return [$sub, $className];
    }

    private function buildReadOnlyBase(string $namespace): string
    {
        return <<<PHP
        <?php

        namespace {$namespace};

        use Illuminate\\Database\\Eloquent\\Model;

        /**
         * Base class for exported models. These are READ-ONLY: the consuming project reads and
         * traverses data with them, but performs all writes through the API/SDK. Every model-level
         * write entry point throws so a stray save/delete fails loudly instead of corrupting data.
         */
        abstract class ReadOnlyModel extends Model
        {
            public function save(array \$options = [])
            {
                throw new \\RuntimeException(static::class.' is read-only; write through the API instead.');
            }

            public function delete()
            {
                throw new \\RuntimeException(static::class.' is read-only; write through the API instead.');
            }

            public function forceDelete()
            {
                throw new \\RuntimeException(static::class.' is read-only; write through the API instead.');
            }
        }

        PHP;
    }

    private function buildModel(
        TableDefinition $table,
        string $modelsBase,
        string $namespace,
        string $className,
        string $modelRoot,
    ): string {
        // Imports collected as basename => FQCN. A sub-namespaced model must import the base from
        // the parent namespace; relation targets in other sub-namespaces are imported too.
        $imports = [];
        if ($namespace !== $modelsBase) {
            $imports['ReadOnlyModel'] = $modelsBase.'\\ReadOnlyModel';
        }

        $body = "    protected \$table = '{$table->tableName}';\n";

        // Pin the connection explicitly when the schema declares one — the target project cannot
        // rely on a default connection matching this app's.
        if ($table->connection !== null) {
            $body .= "\n    protected \$connection = '{$table->connection}';\n";
        }

        $casts = $this->nativeCasts($table);
        if ($casts !== []) {
            $lines = '';
            foreach ($casts as $column => $cast) {
                $lines .= "        '{$column}' => '{$cast}',\n";
            }
            $body .= "\n    protected \$casts = [\n{$lines}    ];\n";
        }

        // @property-read lines for the class docblock — columns first, then relations. Attributes and
        // relations are magic (__get), so without these the consuming IDE offers no completion.
        $docProps = [];
        foreach ($table->columns as $column) {
            $docProps[] = '@property-read '.$this->columnDocType($column, $casts).' $'.$column->name;
        }

        foreach ($table->relationships as $relation) {
            $method = $this->renderRelation($relation, $className, $modelsBase, $namespace, $modelRoot, $imports);
            if ($method !== null) {
                $body .= "\n{$method}";
            }

            $docProps[] = '@property-read '.$this->relationDocType($relation, $modelsBase, $namespace, $modelRoot, $imports).' $'.$relation->name;
        }

        $header = "<?php\n\nnamespace {$namespace};\n";

        if ($imports !== []) {
            $fqcns = array_values($imports);
            sort($fqcns);
            $header .= "\n";
            foreach ($fqcns as $fqcn) {
                $header .= "use {$fqcn};\n";
            }
        }

        $header .= "\n";
        if ($docProps !== []) {
            $header .= "/**\n";
            foreach ($docProps as $prop) {
                $header .= " * {$prop}\n";
            }
            $header .= " */\n";
        }

        $header .= "class {$className} extends ReadOnlyModel\n{\n";

        return $header.$body."}\n";
    }

    /**
     * Resolve how a related model should be referenced from within $currentNs, registering an import
     * when needed. Returns the basename when the target is imported or already in the same namespace;
     * on a basename collision across namespaces it returns a leading-backslash FQCN inline (always
     * correct, sidesteps import aliasing). Mutates $imports (basename => FQCN).
     */
    private function ref(string $relatedFqcn, string $modelsBase, string $currentNs, string $modelRoot, array &$imports): string
    {
        [$sub, $className] = $modelRoot === ''
            ? ['', $this->classBasename($relatedFqcn)]
            : $this->relativePlacement($relatedFqcn, $modelRoot);

        $relatedNs = $modelsBase.($sub !== '' ? '\\'.$sub : '');

        if ($relatedNs === $currentNs) {
            return $className;
        }

        $fqcn = $relatedNs.'\\'.$className;

        // Basename already imported as a DIFFERENT class — reference fully-qualified to stay correct.
        if (isset($imports[$className]) && $imports[$className] !== $fqcn) {
            return '\\'.$fqcn;
        }

        $imports[$className] = $fqcn;

        return $className;
    }

    /**
     * The native casts to carry into the exported model.
     *
     * We keep a column's cast only when it's a plain Laravel cast DIRECTIVE (e.g. 'datetime',
     * 'integer', 'decimal:2') and drop it when it references something the target project won't
     * have: a custom cast / native-enum class (castType is a class FQCN), a DataSchema object
     * column, or a typed Collection column. This is detection by shape — "is this a class
     * reference or rich shape" — not a hardcoded allow-list of native cast names.
     *
     * @return array<string, string>  column => cast directive
     */
    private function nativeCasts(TableDefinition $table): array
    {
        $casts = [];

        foreach ($table->columns as $column) {
            if ($column->castType === null) {
                continue;
            }

            // Rich shapes carry a class even when castType looks primitive ('array') — drop them.
            if ($column->dataSchemaClass !== null || $column->collectionItemClass !== null) {
                continue;
            }

            // A class FQCN cast (custom CastsAttributes, native enum) — the class won't exist in
            // the target. Native directives never contain a namespace separator.
            if (str_contains($column->castType, '\\')) {
                continue;
            }

            $casts[$column->name] = $column->castType;
        }

        return $casts;
    }

    /**
     * Docblock type for a column property. Must match what the model actually RETURNS:
     *  - a plain scalar phpType (int/string/float/bool/array) is used directly;
     *  - a class-ish phpType is trusted only when we KEPT its cast (dates -> \Carbon\CarbonInterface);
     *    a dropped custom cast (enum, DataSchema) means the raw column value is returned, so we fall
     *    back to the underlying DB scalar — NOT the class the target project doesn't have;
     *  - a null phpType (FK columns) falls back to the DB scalar too.
     *
     * @param  array<string, string>  $keptCasts  Output of nativeCasts() — the casts we actually emit.
     */
    private function columnDocType(ColumnDefinition $column, array $keptCasts): string
    {
        $prefix = $column->nullable ? '?' : '';
        $phpType = $column->phpType;

        if ($phpType !== null && ! str_contains($phpType, '\\')) {
            return $prefix.$phpType;
        }

        if ($phpType !== null && isset($keptCasts[$column->name])) {
            return $prefix.'\\'.ltrim($phpType, '\\');
        }

        return $prefix.$this->rawPhpType($column->columnType);
    }

    /**
     * The PHP type a column returns with NO cast applied (raw DB value). Used for FK columns and
     * dropped-cast columns. Date-ish types resolve to Carbon because Eloquent still date-casts
     * created_at/updated_at/deleted_at via its timestamp handling.
     */
    private function rawPhpType(string $columnType): string
    {
        return match (true) {
            in_array($columnType, ['integer', 'bigInteger', 'smallInteger', 'tinyInteger', 'unsignedBigInteger', 'unsignedInteger', 'unsignedSmallInteger', 'unsignedTinyInteger', 'year'], true) => 'int',
            $columnType === 'boolean' => 'bool',
            in_array($columnType, ['decimal', 'float', 'double'], true) => 'float',
            in_array($columnType, ['timestamp', 'datetime', 'date'], true) => '\\Carbon\\CarbonInterface',
            default => 'string',
        };
    }

    /**
     * Docblock type for a relation property. To-many relations become "Collection|Related[]" (and
     * register the Collection import); to-one become "Related" (nullable-aware). morphTo has no
     * concrete related class, so it documents the base Eloquent Model by FQCN (no import needed).
     */
    private function relationDocType(RelationshipDefinition $relation, string $modelsBase, string $currentNs, string $modelRoot, array &$imports): string
    {
        if ($relation->type === 'morphTo') {
            return ($relation->nullable ? '?' : '').'\\Illuminate\\Database\\Eloquent\\Model';
        }

        $related = $this->ref($relation->relatedModel, $modelsBase, $currentNs, $modelRoot, $imports);

        $toMany = in_array($relation->type, ['hasMany', 'belongsToMany', 'morphMany', 'hasManyThrough', 'morphToMany'], true);

        if ($toMany) {
            $imports['Collection'] = 'Illuminate\\Database\\Eloquent\\Collection';

            return "Collection|{$related}[]";
        }

        return ($relation->nullable ? '?' : '').$related;
    }

    /**
     * Render a single relation method.
     *
     * The related target is the SIBLING exported model class (basename of the source Model FQCN) —
     * safe because every model on the connection ships in the same package. All key columns are
     * pinned EXPLICITLY (null keys resolved to Laravel convention) so the relation can't depend on
     * the target project re-deriving conventions identically.
     *
     * Returns null for relation types not yet supported (morph*, *Through) — handled in a later pass.
     */
    private function renderRelation(
        RelationshipDefinition $relation,
        string $modelName,
        string $modelsBase,
        string $currentNs,
        string $modelRoot,
        array &$imports,
    ): ?string {
        $name = $relation->name;
        // The related basename drives convention-derived keys/pivot names. The reference used in
        // code (basename or imported) is resolved by ref(), which registers imports as a side effect
        // — so it must be called directly, not via a closure (arrow fns capture $imports by value).
        $relatedBase = $this->classBasename($relation->relatedModel);

        $call = match ($relation->type) {
            'belongsTo' => sprintf(
                "\$this->belongsTo(%s::class, '%s', '%s')",
                $this->ref($relation->relatedModel, $modelsBase, $currentNs, $modelRoot, $imports),
                $relation->foreignColumn ?? Str::snake($name).'_id',
                $relation->ownerKey ?? 'id',
            ),
            'hasOne' => sprintf(
                "\$this->hasOne(%s::class, '%s', '%s')",
                $this->ref($relation->relatedModel, $modelsBase, $currentNs, $modelRoot, $imports),
                $relation->foreignColumn ?? Str::snake($modelName).'_id',
                $relation->localKey ?? 'id',
            ),
            'hasMany' => sprintf(
                "\$this->hasMany(%s::class, '%s', '%s')",
                $this->ref($relation->relatedModel, $modelsBase, $currentNs, $modelRoot, $imports),
                $relation->foreignColumn ?? Str::snake($modelName).'_id',
                $relation->localKey ?? 'id',
            ),
            'belongsToMany' => sprintf(
                "\$this->belongsToMany(%s::class, '%s', '%s', '%s', '%s', '%s')",
                $this->ref($relation->relatedModel, $modelsBase, $currentNs, $modelRoot, $imports),
                $relation->pivotTable ?? $this->pivotTableName($modelName, $relatedBase),
                $relation->foreignPivotKey ?? Str::snake($modelName).'_id',
                $relation->relatedPivotKey ?? Str::snake($relatedBase).'_id',
                $relation->parentKey ?? 'id',
                $relation->relatedKey ?? 'id',
            ),
            // morphTo is the inverse side — it has no related class; the morph columns are pinned
            // from the morph name (defaults to the relation name).
            'morphTo' => sprintf(
                "\$this->morphTo('%s', '%s_type', '%s_id')",
                $morphName = $relation->morphName ?? $name,
                $morphName,
                $morphName,
            ),
            'morphOne', 'morphMany' => sprintf(
                "\$this->%s(%s::class, '%s', '%s_type', '%s_id', '%s')",
                $relation->type,
                $this->ref($relation->relatedModel, $modelsBase, $currentNs, $modelRoot, $imports),
                $morphName = $relation->morphName ?? $name,
                $morphName,
                $morphName,
                $relation->localKey ?? 'id',
            ),
            'morphToMany' => sprintf(
                "\$this->morphToMany(%s::class, '%s', '%s', '%s', '%s', '%s', '%s', %s)",
                $this->ref($relation->relatedModel, $modelsBase, $currentNs, $modelRoot, $imports),
                $morphName = $relation->morphName ?? $name,
                // Laravel's default morph pivot table is the plural of the morph name.
                $relation->pivotTable ?? Str::plural($morphName),
                $relation->foreignPivotKey ?? $morphName.'_id',
                $relation->relatedPivotKey ?? Str::snake($relatedBase).'_id',
                $relation->parentKey ?? 'id',
                $relation->relatedKey ?? 'id',
                $relation->inverse ? 'true' : 'false',
            ),
            'hasOneThrough', 'hasManyThrough' => sprintf(
                "\$this->%s(%s::class, %s::class, '%s', '%s', '%s', '%s')",
                $relation->type,
                $this->ref($relation->relatedModel, $modelsBase, $currentNs, $modelRoot, $imports),
                $this->ref($relation->through, $modelsBase, $currentNs, $modelRoot, $imports),
                $relation->firstKey ?? Str::snake($modelName).'_id',
                $relation->secondKey ?? Str::snake($this->classBasename($relation->through)).'_id',
                $relation->localKey ?? 'id',
                $relation->secondLocalKey ?? 'id',
            ),
            default => null,
        };

        if ($call === null) {
            return null;
        }

        return <<<PHP
            public function {$name}()
            {
                return {$call};
            }

        PHP;
    }

    /**
     * Laravel's default pivot table name: singular snake of both model basenames, sorted
     * alphabetically and joined by underscore (e.g. Post + Tag => 'post_tag').
     */
    private function pivotTableName(string $modelA, string $modelB): string
    {
        $segments = [Str::snake($modelA), Str::snake($modelB)];
        sort($segments);

        return implode('_', $segments);
    }

    private function classBasename(string $fqcn): string
    {
        return ($pos = strrpos($fqcn, '\\')) !== false
            ? substr($fqcn, $pos + 1)
            : $fqcn;
    }
}
