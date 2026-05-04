<?php

namespace SchemaCraft\Scanner;

use BackedEnum;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;
use ReflectionProperty;
use RuntimeException;
use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\BigInt;
use SchemaCraft\Attributes\Cast;
use SchemaCraft\Attributes\ColumnType;
use SchemaCraft\Attributes\Date;
use SchemaCraft\Attributes\Decimal;
use SchemaCraft\Attributes\DefaultExpression;
use SchemaCraft\Attributes\DefaultValue;
use SchemaCraft\Attributes\Fillable;
use SchemaCraft\Attributes\FloatColumn;
use SchemaCraft\Attributes\ForeignColumn;
use SchemaCraft\Attributes\Hidden;
use SchemaCraft\Attributes\Index;
use SchemaCraft\Attributes\Length;
use SchemaCraft\Attributes\LongText;
use SchemaCraft\Attributes\MediumText;
use SchemaCraft\Attributes\NoConstraint;
use SchemaCraft\Attributes\OnDelete;
use SchemaCraft\Attributes\OnUpdate;
use SchemaCraft\Attributes\PivotTable;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Attributes\Relations\BelongsTo;
use SchemaCraft\Attributes\Relations\BelongsToMany;
use SchemaCraft\Attributes\Relations\HasMany;
use SchemaCraft\Attributes\Relations\HasManyThrough;
use SchemaCraft\Attributes\Relations\HasOne;
use SchemaCraft\Attributes\Relations\HasOneThrough;
use SchemaCraft\Attributes\Relations\MorphedByMany;
use SchemaCraft\Attributes\Relations\MorphMany;
use SchemaCraft\Attributes\Relations\MorphOne;
use SchemaCraft\Attributes\Relations\MorphTo;
use SchemaCraft\Attributes\Relations\MorphToMany;
use SchemaCraft\Attributes\RenamedFrom;
use SchemaCraft\Attributes\SmallInt;
use SchemaCraft\Attributes\Text;
use SchemaCraft\Attributes\Time;
use SchemaCraft\Attributes\TinyInt;
use SchemaCraft\Attributes\Title;
use SchemaCraft\Attributes\Unique;
use SchemaCraft\Attributes\Unsigned;
use SchemaCraft\Attributes\With;
use SchemaCraft\Attributes\Year;
use SchemaCraft\Contracts\SchemaCraftType;
use SchemaCraft\Migration\CanonicalColumn;
use SchemaCraft\Schema;
use SchemaCraft\Traits\SoftDeletesSchema;
use SchemaCraft\Traits\TimestampsSchema;

/**
 * Reflects on a Schema class and produces a TableDefinition value object.
 */
class SchemaScanner
{
    private const RELATION_ATTRIBUTES = [
        BelongsTo::class,
        HasOne::class,
        HasMany::class,
        BelongsToMany::class,
        MorphTo::class,
        MorphOne::class,
        MorphMany::class,
        MorphToMany::class,
        MorphedByMany::class,
        HasOneThrough::class,
        HasManyThrough::class,
    ];

    public function __construct(
        private string $schemaClass,
    ) {}

    public function scan(): TableDefinition
    {
        $reflection = new ReflectionClass($this->schemaClass);
        $traits = class_uses_recursive($this->schemaClass);

        $tableName = $this->resolveTableName($reflection);
        $hasTimestamps = isset($traits[TimestampsSchema::class]);
        $hasSoftDeletes = isset($traits[SoftDeletesSchema::class]);
        $compositeIndexes = $this->readCompositeIndexes($reflection);
        $titleColumns = $this->readTitleColumns($reflection);

        $columns = [];
        $relationships = [];
        $fillable = [];
        $hidden = [];
        $with = [];
        $pendingFkColumns = [];

        foreach ($this->getSchemaProperties($reflection) as $property) {
            $type = $property->getType();

            if (! $type instanceof ReflectionNamedType) {
                throw new RuntimeException(
                    "Property [{$property->getName()}] on [{$this->schemaClass}] must have a single named type. Union and intersection types are not supported."
                );
            }

            $typeName = $type->getName();
            $isFillable = $this->hasAttribute($property, Fillable::class);
            $isHidden = $this->hasAttribute($property, Hidden::class);
            $isWith = $this->hasAttribute($property, With::class);

            $relationAttr = $this->getRelationAttribute($property);

            if ($relationAttr !== null) {
                $rel = $this->scanRelationship($property, $relationAttr, $type->allowsNull());
                $relationships[] = $rel;

                if ($relationAttr instanceof BelongsTo) {
                    $fkColumn = $this->buildForeignKeyColumn($property, $rel);
                    $pendingFkColumns[] = [$fkColumn, $property, $isFillable, $isHidden];
                } elseif ($relationAttr instanceof MorphTo) {
                    $morphIndex = $this->hasAttribute($property, Index::class);
                    $columnTypeAttr = $this->getAttributeInstance($property, ColumnType::class);
                    $columns = array_merge($columns, $this->buildMorphToColumns($rel, $morphIndex, $columnTypeAttr?->type));
                }
                // Through and MorphedByMany relationships are virtual — no columns created.

                if ($isWith) {
                    $with[] = $property->getName();
                }
            } else {
                $columns[] = $this->scanColumn($property, $typeName, $type);

                if ($isFillable) {
                    $fillable[] = $property->getName();
                }

                if ($isHidden) {
                    $hidden[] = $property->getName();
                }
            }
        }

        // Resolve pending FK columns — dedup against explicitly declared columns
        $explicitColumnNames = array_map(fn (ColumnDefinition $c) => $c->name, $columns);
        foreach ($pendingFkColumns as [$fkColumn, $relProperty, $fkFillable, $fkHidden]) {
            $existingIndex = array_search($fkColumn->name, $explicitColumnNames, true);

            if ($existingIndex !== false) {
                // Explicit column exists — apply index union from relationship
                $relIndex = $this->hasAttribute($relProperty, Index::class);
                if ($relIndex && ! $columns[$existingIndex]->index) {
                    $columns[$existingIndex] = new ColumnDefinition(
                        name: $columns[$existingIndex]->name,
                        columnType: $columns[$existingIndex]->columnType,
                        nullable: $columns[$existingIndex]->nullable,
                        default: $columns[$existingIndex]->default,
                        hasDefault: $columns[$existingIndex]->hasDefault,
                        unsigned: $columns[$existingIndex]->unsigned,
                        length: $columns[$existingIndex]->length,
                        precision: $columns[$existingIndex]->precision,
                        scale: $columns[$existingIndex]->scale,
                        unique: $columns[$existingIndex]->unique,
                        index: true,
                        primary: $columns[$existingIndex]->primary,
                        autoIncrement: $columns[$existingIndex]->autoIncrement,
                        castType: $columns[$existingIndex]->castType,
                        attributes: $columns[$existingIndex]->attributes,
                        renamedFrom: $columns[$existingIndex]->renamedFrom,
                        expressionDefault: $columns[$existingIndex]->expressionDefault,
                    );
                }
            } else {
                $columns[] = $fkColumn;
                $explicitColumnNames[] = $fkColumn->name;

                if ($fkFillable) {
                    $fillable[] = $fkColumn->name;
                }

                if ($fkHidden) {
                    $hidden[] = $fkColumn->name;
                }
            }
        }

        // Read connection from the schema class (if declared)
        $connection = $reflection->hasProperty('connection')
            ? $reflection->getStaticPropertyValue('connection')
            : null;

        return new TableDefinition(
            tableName: $tableName,
            schemaClass: $this->schemaClass,
            connection: $connection,
            columns: $columns,
            relationships: $relationships,
            compositeIndexes: $compositeIndexes,
            hasTimestamps: $hasTimestamps,
            hasSoftDeletes: $hasSoftDeletes,
            fillable: $fillable,
            hidden: $hidden,
            with: $with,
            titleColumns: $titleColumns,
        );
    }

    private function resolveTableName(ReflectionClass $reflection): string
    {
        $custom = $this->schemaClass::tableName();

        if ($custom !== null) {
            return $custom;
        }

        $className = $reflection->getShortName();
        $baseName = Str::beforeLast($className, 'Schema');

        if ($baseName === $className) {
            $baseName = $className;
        }

        return Str::snake(Str::pluralStudly($baseName));
    }

    /**
     * @return ReflectionProperty[]
     */
    private function getSchemaProperties(ReflectionClass $reflection): array
    {
        $properties = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $declaringClass = $property->getDeclaringClass()->getName();

            if ($declaringClass === Schema::class) {
                continue;
            }

            $properties[] = $property;
        }

        return $properties;
    }

    private function getRelationAttribute(ReflectionProperty $property): BelongsTo|HasOne|HasMany|BelongsToMany|MorphTo|MorphOne|MorphMany|MorphToMany|MorphedByMany|HasOneThrough|HasManyThrough|null
    {
        foreach (self::RELATION_ATTRIBUTES as $attrClass) {
            $instance = $this->getAttributeInstance($property, $attrClass);

            if ($instance !== null) {
                return $instance;
            }
        }

        return null;
    }

    private function scanColumn(ReflectionProperty $property, string $typeName, ReflectionNamedType $type): ColumnDefinition
    {
        $nullable = $type->allowsNull();
        $hasDefault = $property->hasDefaultValue();
        $default = $hasDefault ? $property->getDefaultValue() : null;

        if ($hasDefault && $default instanceof BackedEnum) {
            $default = $default->value;
        }

        $inferred = $this->inferColumnType($typeName, $type);

        $columnType = $inferred['columnType'];
        $castType = $inferred['castType'];
        $unsigned = $inferred['unsigned'] ?? false;
        $length = $inferred['length'] ?? null;
        $precision = $inferred['precision'] ?? null;
        $scale = $inferred['scale'] ?? null;
        $unique = false;
        $index = false;
        $primary = false;
        $autoIncrement = false;
        $expressionDefault = null;

        $phpAttributes = $property->getAttributes();
        $rawAttributes = [];

        foreach ($phpAttributes as $attr) {
            $attrInstance = $attr->newInstance();
            $rawAttributes[] = $attrInstance;

            match (true) {
                $attrInstance instanceof Text => $columnType = 'text',
                $attrInstance instanceof MediumText => $columnType = 'mediumText',
                $attrInstance instanceof LongText => $columnType = 'longText',
                $attrInstance instanceof Length => $length = $attrInstance->length,
                $attrInstance instanceof Decimal => (function () use ($attrInstance, &$columnType, &$precision, &$scale) {
                    $columnType = 'decimal';
                    $precision = $attrInstance->precision;
                    $scale = $attrInstance->scale;
                })(),
                $attrInstance instanceof Unsigned => $unsigned = true,
                $attrInstance instanceof BigInt => $columnType = 'bigInteger',
                $attrInstance instanceof SmallInt => $columnType = 'smallInteger',
                $attrInstance instanceof TinyInt => $columnType = 'tinyInteger',
                $attrInstance instanceof FloatColumn => $columnType = 'float',
                $attrInstance instanceof Date => (function () use (&$columnType, &$castType) {
                    $columnType = 'date';
                    $castType = 'date';
                })(),
                $attrInstance instanceof Time => $columnType = 'time',
                $attrInstance instanceof Year => $columnType = 'year',
                $attrInstance instanceof Unique => $unique = true,
                $attrInstance instanceof Index => $index = true,
                $attrInstance instanceof Cast => $castType = $attrInstance->castClass,
                $attrInstance instanceof DefaultExpression => $expressionDefault = $attrInstance->expression,
                $attrInstance instanceof DefaultValue => (function () use ($attrInstance, &$hasDefault, &$default) {
                    $hasDefault = true;
                    $default = $attrInstance->value;
                })(),
                $attrInstance instanceof Primary => $primary = true,
                $attrInstance instanceof AutoIncrement => $autoIncrement = true,
                $attrInstance instanceof ColumnType => $columnType = $attrInstance->type,
                default => null,
            };
        }

        // Auto-increment PKs are always unsigned big integers
        if ($primary && $autoIncrement) {
            $columnType = 'unsignedBigInteger';
            $unsigned = true;
            $castType = 'integer';
        }

        $renamedFrom = $this->getAttributeInstance($property, RenamedFrom::class)?->from;

        return new ColumnDefinition(
            name: $property->getName(),
            columnType: $columnType,
            nullable: $nullable,
            default: $default,
            hasDefault: $hasDefault,
            unsigned: $unsigned,
            length: $length,
            precision: $precision,
            scale: $scale,
            unique: $unique,
            index: $index,
            primary: $primary,
            autoIncrement: $autoIncrement,
            castType: $castType,
            attributes: $rawAttributes,
            renamedFrom: $renamedFrom,
            expressionDefault: $expressionDefault,
            phpType: $typeName,
        );
    }

    /**
     * @return array{columnType: string, castType: string|null}
     */
    private function inferColumnType(string $typeName, ReflectionNamedType $type): array
    {
        if ($type->isBuiltin()) {
            return match ($typeName) {
                'string' => ['columnType' => 'string', 'castType' => 'string'],
                'int' => ['columnType' => 'integer', 'castType' => 'integer'],
                'float' => ['columnType' => 'double', 'castType' => 'double'],
                'bool' => ['columnType' => 'boolean', 'castType' => 'boolean'],
                'array' => ['columnType' => 'json', 'castType' => 'array'],
                default => throw new RuntimeException(
                    "Unsupported built-in type [{$typeName}] on schema [{$this->schemaClass}]."
                ),
            };
        }

        if (is_a($typeName, CarbonInterface::class, true) || is_a($typeName, DateTimeInterface::class, true)) {
            return ['columnType' => 'timestamp', 'castType' => 'datetime'];
        }

        if (is_a($typeName, BackedEnum::class, true)) {
            $enumReflection = new ReflectionEnum($typeName);
            $backingType = $enumReflection->getBackingType()->getName();

            return [
                'columnType' => $backingType === 'string' ? 'string' : 'integer',
                'castType' => $typeName,
            ];
        }

        if (is_a($typeName, SchemaCraftType::class, true)) {
            $result = [
                'columnType' => $typeName::schemaColumnType(),
                'castType' => $typeName,
            ];

            $modifiers = $typeName::schemaColumnModifiers();
            if (! empty($modifiers['unsigned'])) {
                $result['unsigned'] = true;
            }
            if (isset($modifiers['length'])) {
                $result['length'] = $modifiers['length'];
            }
            if (isset($modifiers['precision'])) {
                $result['precision'] = $modifiers['precision'];
            }
            if (isset($modifiers['scale'])) {
                $result['scale'] = $modifiers['scale'];
            }

            return $result;
        }

        if (is_a($typeName, Castable::class, true) || is_a($typeName, CastsAttributes::class, true)) {
            return ['columnType' => 'json', 'castType' => $typeName];
        }

        throw new RuntimeException(
            "Unknown type [{$typeName}] on schema [{$this->schemaClass}]. Add #[Cast(YourCast::class)] or make [{$typeName}] implement Castable."
        );
    }

    private function scanRelationship(
        ReflectionProperty $property,
        BelongsTo|HasOne|HasMany|BelongsToMany|MorphTo|MorphOne|MorphMany|MorphToMany|MorphedByMany|HasOneThrough|HasManyThrough $relationAttr,
        bool $nullable,
    ): RelationshipDefinition {
        $onDelete = $this->getAttributeInstance($property, OnDelete::class)?->action;
        $onUpdate = $this->getAttributeInstance($property, OnUpdate::class)?->action;
        $noConstraint = $this->hasAttribute($property, NoConstraint::class);
        $belongsToManyAttr = $this->getAttributeInstance($property, BelongsToMany::class);
        $pivotModel = $belongsToManyAttr?->using;

        $relType = match (true) {
            $relationAttr instanceof BelongsTo => 'belongsTo',
            $relationAttr instanceof HasOne => 'hasOne',
            $relationAttr instanceof HasMany => 'hasMany',
            $relationAttr instanceof BelongsToMany => 'belongsToMany',
            $relationAttr instanceof MorphTo => 'morphTo',
            $relationAttr instanceof MorphOne => 'morphOne',
            $relationAttr instanceof MorphMany => 'morphMany',
            $relationAttr instanceof MorphToMany => 'morphToMany',
            $relationAttr instanceof MorphedByMany => 'morphToMany',
            $relationAttr instanceof HasOneThrough => 'hasOneThrough',
            $relationAttr instanceof HasManyThrough => 'hasManyThrough',
        };

        $relatedModel = $relationAttr instanceof MorphTo
            ? \Illuminate\Database\Eloquent\Model::class
            : $relationAttr->model;

        $morphName = property_exists($relationAttr, 'morphName')
            ? $relationAttr->morphName
            : null;

        // Read foreignKey from attribute (new), falling back to deprecated #[ForeignColumn]
        $foreignColumn = null;
        if (property_exists($relationAttr, 'foreignKey')) {
            $foreignColumn = $relationAttr->foreignKey;
        }
        if ($foreignColumn === null) {
            $foreignColumn = $this->getAttributeInstance($property, ForeignColumn::class)?->column;
        }

        // Read pivotTable from attribute (new), falling back to deprecated #[PivotTable]
        $pivotTable = null;
        if (property_exists($relationAttr, 'table')) {
            $pivotTable = $relationAttr->table;
        }
        if ($pivotTable === null) {
            $pivotTable = $this->getAttributeInstance($property, PivotTable::class)?->table;
        }

        // Read key overrides from attribute
        $ownerKey = property_exists($relationAttr, 'ownerKey') ? $relationAttr->ownerKey : null;
        $localKey = property_exists($relationAttr, 'localKey') ? $relationAttr->localKey : null;
        $parentKey = property_exists($relationAttr, 'parentKey') ? $relationAttr->parentKey : null;
        $relatedKey = property_exists($relationAttr, 'relatedKey') ? $relationAttr->relatedKey : null;
        $foreignPivotKey = property_exists($relationAttr, 'foreignPivotKey') ? $relationAttr->foreignPivotKey : null;
        $relatedPivotKey = property_exists($relationAttr, 'relatedPivotKey') ? $relationAttr->relatedPivotKey : null;

        // Through relationship params
        $through = property_exists($relationAttr, 'through') ? $relationAttr->through : null;
        $firstKey = property_exists($relationAttr, 'firstKey') ? $relationAttr->firstKey : null;
        $secondKey = property_exists($relationAttr, 'secondKey') ? $relationAttr->secondKey : null;
        $secondLocalKey = property_exists($relationAttr, 'secondLocalKey') ? $relationAttr->secondLocalKey : null;

        // MorphedByMany is MorphToMany with inverse = true
        $inverse = $relationAttr instanceof MorphedByMany
            || (property_exists($relationAttr, 'inverse') && $relationAttr->inverse);

        return new RelationshipDefinition(
            name: $property->getName(),
            type: $relType,
            relatedModel: $relatedModel,
            nullable: $nullable,
            foreignColumn: $foreignColumn,
            onDelete: $onDelete,
            onUpdate: $onUpdate,
            noConstraint: $noConstraint,
            pivotTable: $pivotTable,
            morphName: $morphName,
            pivotModel: $pivotModel,
            ownerKey: $ownerKey,
            localKey: $localKey,
            parentKey: $parentKey,
            relatedKey: $relatedKey,
            foreignPivotKey: $foreignPivotKey,
            relatedPivotKey: $relatedPivotKey,
            through: $through,
            firstKey: $firstKey,
            secondKey: $secondKey,
            secondLocalKey: $secondLocalKey,
            inverse: $inverse,
        );
    }

    private function buildForeignKeyColumn(ReflectionProperty $property, RelationshipDefinition $rel): ColumnDefinition
    {
        $fkName = $rel->foreignColumn ?? Str::snake($property->getName()).'_id';
        $index = $this->hasAttribute($property, Index::class);
        $columnTypeAttr = $this->getAttributeInstance($property, ColumnType::class);
        $fkType = $columnTypeAttr?->type ?? 'unsignedBigInteger';
        [$baseType, $isUnsigned] = CanonicalColumn::decomposeType($fkType, false);

        return new ColumnDefinition(
            name: $fkName,
            columnType: $fkType,
            nullable: $rel->nullable,
            unsigned: $isUnsigned,
            index: $index,
            castType: 'integer',
        );
    }

    /**
     * @return ColumnDefinition[]
     */
    private function buildMorphToColumns(RelationshipDefinition $rel, bool $index = false, ?string $idColumnType = null): array
    {
        $morphName = $rel->morphName ?? $rel->name;
        $fkType = $idColumnType ?? 'unsignedBigInteger';
        [$baseType, $isUnsigned] = CanonicalColumn::decomposeType($fkType, false);

        return [
            new ColumnDefinition(
                name: $morphName.'_type',
                columnType: 'string',
                nullable: $rel->nullable,
                index: $index,
                castType: 'string',
            ),
            new ColumnDefinition(
                name: $morphName.'_id',
                columnType: $fkType,
                nullable: $rel->nullable,
                unsigned: $isUnsigned,
                index: $index,
                castType: 'integer',
            ),
        ];
    }

    /**
     * @return array<int, string[]>
     */
    private function readCompositeIndexes(ReflectionClass $reflection): array
    {
        $indexes = [];

        foreach ($reflection->getAttributes(Index::class) as $attr) {
            $instance = $attr->newInstance();

            if ($instance->columns !== null) {
                $indexes[] = $instance->columns;
            }
        }

        return $indexes;
    }

    /**
     * Read #[Title] from class-level attribute.
     *
     * Single: #[Title('company_name')] → ['company_name']
     * Composite: #[Title(['first_name', 'last_name'])] → ['first_name', 'last_name']
     *
     * @return string[]
     */
    private function readTitleColumns(ReflectionClass $reflection): array
    {
        $attrs = $reflection->getAttributes(Title::class);

        if (! empty($attrs)) {
            return $attrs[0]->newInstance()->columns;
        }

        return [];
    }

    /**
     * @template T
     *
     * @param  class-string<T>  $attributeClass
     * @return T|null
     */
    private function getAttributeInstance(ReflectionProperty $property, string $attributeClass): mixed
    {
        $attrs = $property->getAttributes($attributeClass);

        if (count($attrs) === 0) {
            return null;
        }

        return $attrs[0]->newInstance();
    }

    private function hasAttribute(ReflectionProperty $property, string $attributeClass): bool
    {
        return count($property->getAttributes($attributeClass)) > 0;
    }
}
