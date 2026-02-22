<?php

namespace SchemaCraft\Scanner;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Support\Carbon;
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
use SchemaCraft\Attributes\PivotColumns;
use SchemaCraft\Attributes\PivotTable;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Attributes\Relations\BelongsTo;
use SchemaCraft\Attributes\Relations\BelongsToMany;
use SchemaCraft\Attributes\Relations\HasMany;
use SchemaCraft\Attributes\Relations\HasOne;
use SchemaCraft\Attributes\Relations\MorphMany;
use SchemaCraft\Attributes\Relations\MorphOne;
use SchemaCraft\Attributes\Relations\MorphTo;
use SchemaCraft\Attributes\Relations\MorphToMany;
use SchemaCraft\Attributes\RenamedFrom;
use SchemaCraft\Attributes\SmallInt;
use SchemaCraft\Attributes\Text;
use SchemaCraft\Attributes\Time;
use SchemaCraft\Attributes\TinyInt;
use SchemaCraft\Attributes\Unique;
use SchemaCraft\Attributes\Unsigned;
use SchemaCraft\Attributes\With;
use SchemaCraft\Attributes\Year;
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

        $columns = [];
        $relationships = [];
        $fillable = [];
        $hidden = [];
        $with = [];

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
                    $columns[] = $fkColumn;

                    if ($isFillable) {
                        $fillable[] = $fkColumn->name;
                    }

                    if ($isHidden) {
                        $hidden[] = $fkColumn->name;
                    }
                } elseif ($relationAttr instanceof MorphTo) {
                    $morphIndex = $this->hasAttribute($property, Index::class);
                    $columnTypeAttr = $this->getAttributeInstance($property, ColumnType::class);
                    $columns = array_merge($columns, $this->buildMorphToColumns($rel, $morphIndex, $columnTypeAttr?->type));
                }

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

        return new TableDefinition(
            tableName: $tableName,
            schemaClass: $this->schemaClass,
            columns: $columns,
            relationships: $relationships,
            compositeIndexes: $compositeIndexes,
            hasTimestamps: $hasTimestamps,
            hasSoftDeletes: $hasSoftDeletes,
            fillable: $fillable,
            hidden: $hidden,
            with: $with,
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

    private function getRelationAttribute(ReflectionProperty $property): BelongsTo|HasOne|HasMany|BelongsToMany|MorphTo|MorphOne|MorphMany|MorphToMany|null
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
        $unsigned = false;
        $length = null;
        $precision = null;
        $scale = null;
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

        if (is_a($typeName, Carbon::class, true) || is_a($typeName, DateTimeInterface::class, true)) {
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

        if (is_a($typeName, Castable::class, true) || is_a($typeName, CastsAttributes::class, true)) {
            return ['columnType' => 'json', 'castType' => $typeName];
        }

        throw new RuntimeException(
            "Unknown type [{$typeName}] on schema [{$this->schemaClass}]. Add #[Cast(YourCast::class)] or make [{$typeName}] implement Castable."
        );
    }

    private function scanRelationship(
        ReflectionProperty $property,
        BelongsTo|HasOne|HasMany|BelongsToMany|MorphTo|MorphOne|MorphMany|MorphToMany $relationAttr,
        bool $nullable,
    ): RelationshipDefinition {
        $foreignColumn = $this->getAttributeInstance($property, ForeignColumn::class)?->column;
        $onDelete = $this->getAttributeInstance($property, OnDelete::class)?->action;
        $onUpdate = $this->getAttributeInstance($property, OnUpdate::class)?->action;
        $noConstraint = $this->hasAttribute($property, NoConstraint::class);
        $pivotTable = $this->getAttributeInstance($property, PivotTable::class)?->table;
        $pivotColumns = $this->getAttributeInstance($property, PivotColumns::class)?->columns;

        $relType = match (true) {
            $relationAttr instanceof BelongsTo => 'belongsTo',
            $relationAttr instanceof HasOne => 'hasOne',
            $relationAttr instanceof HasMany => 'hasMany',
            $relationAttr instanceof BelongsToMany => 'belongsToMany',
            $relationAttr instanceof MorphTo => 'morphTo',
            $relationAttr instanceof MorphOne => 'morphOne',
            $relationAttr instanceof MorphMany => 'morphMany',
            $relationAttr instanceof MorphToMany => 'morphToMany',
        };

        $relatedModel = $relationAttr instanceof MorphTo
            ? \Illuminate\Database\Eloquent\Model::class
            : $relationAttr->model;

        $morphName = property_exists($relationAttr, 'morphName')
            ? $relationAttr->morphName
            : null;

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
            pivotColumns: $pivotColumns,
            morphName: $morphName,
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
