<?php

namespace SchemaCraft\Visualizer;

use Illuminate\Support\Str;
use ReflectionClass;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Scanner\RelationshipDefinition;
use SchemaCraft\Scanner\SchemaScanner;
use SchemaCraft\Scanner\TableDefinition;

class SchemaAnalyzer
{
    /** @var array<string, TableDefinition> keyed by schema FQCN */
    private array $tables = [];

    /** @var array<string, string> model FQCN => schema FQCN */
    private array $modelToSchema = [];

    /**
     * @param  class-string<\SchemaCraft\Schema>[]  $schemaClasses
     */
    public function __construct(
        private array $schemaClasses,
    ) {}

    public function analyze(): AnalysisResult
    {
        $this->scanAllSchemas();
        $this->buildModelToSchemaMap();

        $schemas = $this->buildSchemaInfos();
        $issues = $this->runHealthChecks();

        $totalRelationships = 0;
        foreach ($this->tables as $table) {
            $totalRelationships += count($table->relationships);
        }

        return new AnalysisResult(
            schemas: $schemas,
            issues: $issues,
            modelCount: count($this->tables),
            relationshipCount: $totalRelationships,
            issueCount: count($issues),
        );
    }

    private function scanAllSchemas(): void
    {
        foreach ($this->schemaClasses as $schemaClass) {
            $this->tables[$schemaClass] = (new SchemaScanner($schemaClass))->scan();
        }
    }

    private function buildModelToSchemaMap(): void
    {
        $referencedModels = [];

        foreach ($this->tables as $table) {
            foreach ($table->relationships as $rel) {
                if ($rel->type !== 'morphTo') {
                    $referencedModels[$rel->relatedModel] = true;
                }
            }
        }

        foreach ($referencedModels as $modelClass => $_) {
            if (! class_exists($modelClass)) {
                continue;
            }

            $ref = new ReflectionClass($modelClass);

            if (! $ref->hasProperty('schema') || ! $ref->getProperty('schema')->isStatic()) {
                continue;
            }

            $schemaClass = $ref->getStaticPropertyValue('schema');

            if (isset($this->tables[$schemaClass])) {
                $this->modelToSchema[$modelClass] = $schemaClass;
            }
        }
    }

    /**
     * @return array<string, SchemaInfo>
     */
    private function buildSchemaInfos(): array
    {
        $schemas = [];

        foreach ($this->tables as $schemaClass => $table) {
            $columns = array_map(
                fn (ColumnDefinition $col) => [
                    'name' => $col->name,
                    'type' => $col->columnType,
                    'nullable' => $col->nullable,
                    'primary' => $col->primary,
                    'unique' => $col->unique,
                    'index' => $col->index,
                ],
                $table->columns,
            );

            $relationships = array_map(
                fn (RelationshipDefinition $rel) => [
                    'name' => $rel->name,
                    'type' => $rel->type,
                    'relatedSchema' => $this->modelToSchema[$rel->relatedModel] ?? null,
                    'relatedTable' => isset($this->modelToSchema[$rel->relatedModel])
                        ? $this->tables[$this->modelToSchema[$rel->relatedModel]]->tableName
                        : null,
                    'relatedModel' => $rel->relatedModel,
                    'nullable' => $rel->nullable,
                    'foreignColumn' => $rel->foreignColumn,
                    'morphName' => $rel->morphName,
                ],
                $table->relationships,
            );

            $schemas[$schemaClass] = new SchemaInfo(
                schemaClass: $schemaClass,
                tableName: $table->tableName,
                columns: $columns,
                relationships: $relationships,
                hasTimestamps: $table->hasTimestamps,
                hasSoftDeletes: $table->hasSoftDeletes,
                modelClass: $this->resolveModelFqcn($schemaClass),
            );
        }

        return $schemas;
    }

    /**
     * @return HealthIssue[]
     */
    private function runHealthChecks(): array
    {
        return array_merge(
            $this->checkMissingInverses(),
            $this->checkOrphanedModels(),
            $this->checkForeignKeysWithoutRelationship(),
        );
    }

    /**
     * @return HealthIssue[]
     */
    private function checkMissingInverses(): array
    {
        $issues = [];
        $checked = [];

        foreach ($this->tables as $schemaClassA => $tableA) {
            foreach ($tableA->relationships as $rel) {
                $targetSchemaClass = $this->resolveRelatedSchema($rel);

                if ($targetSchemaClass === null) {
                    continue;
                }

                $pairKey = $this->makePairKey($schemaClassA, $rel->name, $targetSchemaClass, $rel->type);

                if (isset($checked[$pairKey])) {
                    continue;
                }

                $checked[$pairKey] = true;

                if (! $this->hasInverse($schemaClassA, $rel, $targetSchemaClass)) {
                    $issue = $this->buildMissingInverseIssue($schemaClassA, $rel, $targetSchemaClass);

                    if ($issue !== null) {
                        $issues[] = $issue;

                        $inversePairKey = $this->makeInversePairKey($schemaClassA, $rel, $targetSchemaClass);

                        if ($inversePairKey !== null) {
                            $checked[$inversePairKey] = true;
                        }
                    }
                }
            }
        }

        return $issues;
    }

    private function resolveRelatedSchema(RelationshipDefinition $rel): ?string
    {
        if ($rel->type === 'morphTo') {
            return null;
        }

        return $this->modelToSchema[$rel->relatedModel] ?? null;
    }

    private function makePairKey(string $schemaA, string $relName, string $schemaB, string $relType): string
    {
        return "{$schemaA}::{$relName}->{$schemaB}({$relType})";
    }

    private function makeInversePairKey(string $schemaClassA, RelationshipDefinition $rel, string $targetSchemaClass): ?string
    {
        $expectedInverseType = $this->expectedInverseType($rel->type);

        if ($expectedInverseType === null) {
            return null;
        }

        $targetTable = $this->tables[$targetSchemaClass] ?? null;

        if ($targetTable === null) {
            return null;
        }

        foreach ($targetTable->relationships as $targetRel) {
            $targetRelSchema = $this->resolveRelatedSchema($targetRel);

            if ($targetRelSchema === $schemaClassA && $targetRel->type === $expectedInverseType) {
                return $this->makePairKey($targetSchemaClass, $targetRel->name, $schemaClassA, $targetRel->type);
            }
        }

        return null;
    }

    private function hasInverse(string $schemaClassA, RelationshipDefinition $rel, string $targetSchemaClass): bool
    {
        $targetTable = $this->tables[$targetSchemaClass] ?? null;

        if ($targetTable === null) {
            return false;
        }

        return match ($rel->type) {
            'belongsTo' => $this->hasInverseOfBelongsTo($schemaClassA, $targetTable),
            'hasMany', 'hasOne' => $this->hasInverseOfHasX($schemaClassA, $targetTable),
            'belongsToMany' => $this->hasInverseOfBelongsToMany($schemaClassA, $targetTable),
            'morphMany', 'morphOne' => $this->hasInverseOfMorphX($rel, $targetTable),
            'morphToMany' => $this->hasInverseOfMorphToMany($schemaClassA, $rel, $targetTable),
            default => true,
        };
    }

    private function hasInverseOfBelongsTo(string $sourceSchemaClass, TableDefinition $targetTable): bool
    {
        foreach ($targetTable->relationships as $targetRel) {
            if (! in_array($targetRel->type, ['hasMany', 'hasOne'], true)) {
                continue;
            }

            $targetRelSchema = $this->resolveRelatedSchema($targetRel);

            if ($targetRelSchema === $sourceSchemaClass) {
                return true;
            }
        }

        return false;
    }

    private function hasInverseOfHasX(string $sourceSchemaClass, TableDefinition $targetTable): bool
    {
        foreach ($targetTable->relationships as $targetRel) {
            if ($targetRel->type !== 'belongsTo') {
                continue;
            }

            $targetRelSchema = $this->resolveRelatedSchema($targetRel);

            if ($targetRelSchema === $sourceSchemaClass) {
                return true;
            }
        }

        return false;
    }

    private function hasInverseOfBelongsToMany(string $sourceSchemaClass, TableDefinition $targetTable): bool
    {
        foreach ($targetTable->relationships as $targetRel) {
            if ($targetRel->type !== 'belongsToMany') {
                continue;
            }

            $targetRelSchema = $this->resolveRelatedSchema($targetRel);

            if ($targetRelSchema === $sourceSchemaClass) {
                return true;
            }
        }

        return false;
    }

    private function hasInverseOfMorphX(RelationshipDefinition $rel, TableDefinition $targetTable): bool
    {
        $morphName = $rel->morphName;

        foreach ($targetTable->relationships as $targetRel) {
            if ($targetRel->type !== 'morphTo') {
                continue;
            }

            if ($targetRel->morphName === $morphName) {
                return true;
            }
        }

        return false;
    }

    private function hasInverseOfMorphToMany(string $sourceSchemaClass, RelationshipDefinition $rel, TableDefinition $targetTable): bool
    {
        $morphName = $rel->morphName;

        foreach ($targetTable->relationships as $targetRel) {
            if ($targetRel->type !== 'morphToMany') {
                continue;
            }

            $targetRelSchema = $this->resolveRelatedSchema($targetRel);

            if ($targetRelSchema === $sourceSchemaClass && $targetRel->morphName === $morphName) {
                return true;
            }
        }

        return false;
    }

    private function buildMissingInverseIssue(string $schemaClassA, RelationshipDefinition $rel, string $targetSchemaClass): ?HealthIssue
    {
        $shortA = class_basename($schemaClassA);
        $shortB = class_basename($targetSchemaClass);
        $expectedInverseType = $this->expectedInverseType($rel->type);

        if ($expectedInverseType === null) {
            return null;
        }

        $message = "{$shortA}.{$rel->name} ({$rel->type}) has no inverse on {$shortB}. "
            ."Expected {$expectedInverseType} pointing back.";

        $suggestedFix = $this->buildSuggestedFix($schemaClassA, $rel, $expectedInverseType);

        $applyData = [
            'schemaClass' => $targetSchemaClass,
            'relatedModel' => $this->resolveModelFqcn($schemaClassA),
            'morphName' => $rel->morphName,
        ];

        if ($rel->type === 'belongsTo') {
            $applyData['ambiguousTypes'] = ['hasMany', 'hasOne'];
        } else {
            $applyData['relationshipType'] = $expectedInverseType;
        }

        return new HealthIssue(
            severity: 'warning',
            check: 'missing_inverse',
            message: $message,
            affectedSchemas: [$schemaClassA, $targetSchemaClass],
            suggestedFix: $suggestedFix,
            applyData: $applyData,
        );
    }

    /**
     * @return 'belongsTo'|'hasMany'|'hasOne'|'belongsToMany'|'morphTo'|'morphToMany'|null
     */
    private function expectedInverseType(string $relType): ?string
    {
        return match ($relType) {
            'belongsTo' => 'hasMany',
            'hasMany', 'hasOne' => 'belongsTo',
            'belongsToMany' => 'belongsToMany',
            'morphMany', 'morphOne' => 'morphTo',
            'morphToMany' => 'morphToMany',
            default => null,
        };
    }

    private function buildSuggestedFix(string $schemaClassA, RelationshipDefinition $rel, string $expectedInverseType): string
    {
        $table = $this->tables[$schemaClassA];
        $modelName = $this->resolveModelShortName($schemaClassA);
        $modelFqcn = $this->resolveModelFqcn($schemaClassA);

        return match ($expectedInverseType) {
            'hasMany' => $this->suggestHasMany($modelFqcn, $modelName),
            'hasOne' => $this->suggestHasOne($modelFqcn, $modelName),
            'belongsTo' => $this->suggestBelongsTo($modelFqcn, $modelName),
            'belongsToMany' => $this->suggestBelongsToMany($modelFqcn, $modelName),
            'morphTo' => $this->suggestMorphTo($rel->morphName ?? $rel->name),
            'morphToMany' => $this->suggestMorphToMany($modelFqcn, $modelName, $rel->morphName ?? $rel->name),
            default => '',
        };
    }

    private function resolveModelShortName(string $schemaClass): string
    {
        $schemaShort = class_basename($schemaClass);

        return Str::beforeLast($schemaShort, 'Schema');
    }

    private function resolveModelFqcn(string $schemaClass): string
    {
        foreach ($this->modelToSchema as $modelClass => $schema) {
            if ($schema === $schemaClass) {
                return $modelClass;
            }
        }

        $modelName = $this->resolveModelShortName($schemaClass);

        return "App\\Models\\{$modelName}";
    }

    private function suggestHasMany(string $modelFqcn, string $modelName): string
    {
        $propertyName = Str::camel(Str::plural($modelName));
        $shortModel = class_basename($modelFqcn);

        return "@method Eloquent\\HasMany|{$shortModel} {$propertyName}()\n\n/** @var Collection<int, {$shortModel}> */\n#[HasMany({$shortModel}::class)]\npublic Collection \${$propertyName};";
    }

    private function suggestHasOne(string $modelFqcn, string $modelName): string
    {
        $propertyName = Str::camel($modelName);
        $shortModel = class_basename($modelFqcn);

        return "@method Eloquent\\HasOne|{$shortModel} {$propertyName}()\n\n#[HasOne({$shortModel}::class)]\npublic {$shortModel} \${$propertyName};";
    }

    private function suggestBelongsTo(string $modelFqcn, string $modelName): string
    {
        $propertyName = Str::camel($modelName);
        $shortModel = class_basename($modelFqcn);

        return "@method Eloquent\\BelongsTo|{$shortModel} {$propertyName}()\n\n#[BelongsTo({$shortModel}::class)]\npublic {$shortModel} \${$propertyName};";
    }

    private function suggestBelongsToMany(string $modelFqcn, string $modelName): string
    {
        $propertyName = Str::camel(Str::plural($modelName));
        $shortModel = class_basename($modelFqcn);

        return "@method Eloquent\\BelongsToMany|{$shortModel} {$propertyName}()\n\n/** @var Collection<int, {$shortModel}> */\n#[BelongsToMany({$shortModel}::class)]\npublic Collection \${$propertyName};";
    }

    private function suggestMorphTo(string $morphName): string
    {
        return "@method Eloquent\\MorphTo|Model {$morphName}()\n\n#[MorphTo('{$morphName}')]\npublic Model \${$morphName};";
    }

    private function suggestMorphToMany(string $modelFqcn, string $modelName, string $morphName): string
    {
        $propertyName = Str::camel(Str::plural($modelName));
        $shortModel = class_basename($modelFqcn);

        return "@method Eloquent\\MorphToMany|{$shortModel} {$propertyName}()\n\n/** @var Collection<int, {$shortModel}> */\n#[MorphToMany({$shortModel}::class, '{$morphName}')]\npublic Collection \${$propertyName};";
    }

    /**
     * @return HealthIssue[]
     */
    private function checkOrphanedModels(): array
    {
        $issues = [];

        foreach ($this->tables as $schemaClass => $table) {
            if (count($table->relationships) === 0) {
                $shortName = class_basename($schemaClass);

                $issues[] = new HealthIssue(
                    severity: 'info',
                    check: 'orphaned_model',
                    message: "{$shortName} has no relationships defined.",
                    affectedSchemas: [$schemaClass],
                );
            }
        }

        return $issues;
    }

    /**
     * @return HealthIssue[]
     */
    private function checkForeignKeysWithoutRelationship(): array
    {
        $issues = [];

        foreach ($this->tables as $schemaClass => $table) {
            $belongsToFkColumns = $this->collectBelongsToFkColumns($table);
            $morphColumns = $this->collectMorphColumns($table);

            foreach ($table->columns as $col) {
                if (! str_ends_with($col->name, '_id')) {
                    continue;
                }

                if (in_array($col->name, $belongsToFkColumns, true)) {
                    continue;
                }

                if (in_array($col->name, $morphColumns, true)) {
                    continue;
                }

                $shortName = class_basename($schemaClass);

                $issues[] = new HealthIssue(
                    severity: 'info',
                    check: 'fk_without_relationship',
                    message: "{$shortName} has column '{$col->name}' that looks like a foreign key but has no BelongsTo relationship.",
                    affectedSchemas: [$schemaClass],
                );
            }
        }

        return $issues;
    }

    /**
     * @return string[]
     */
    private function collectBelongsToFkColumns(TableDefinition $table): array
    {
        $columns = [];

        foreach ($table->relationships as $rel) {
            if ($rel->type === 'belongsTo') {
                $columns[] = $rel->foreignColumn ?? Str::snake($rel->name).'_id';
            }
        }

        return $columns;
    }

    /**
     * @return string[]
     */
    private function collectMorphColumns(TableDefinition $table): array
    {
        $columns = [];

        foreach ($table->relationships as $rel) {
            if ($rel->type === 'morphTo') {
                $morphName = $rel->morphName ?? $rel->name;
                $columns[] = $morphName.'_id';
                $columns[] = $morphName.'_type';
            }
        }

        return $columns;
    }
}
