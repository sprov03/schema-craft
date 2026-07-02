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
use ReflectionUnionType;
use RuntimeException;
use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\BigInt;
use SchemaCraft\Attributes\Cast;
use SchemaCraft\DataSchema;
use SchemaCraft\Attributes\ColumnType;
use SchemaCraft\Attributes\Date;
use SchemaCraft\Attributes\Decimal;
use SchemaCraft\Attributes\DefaultExpression;
use SchemaCraft\Attributes\DefaultValue;
use SchemaCraft\Attributes\Fillable;
use SchemaCraft\Attributes\Generated;
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
            $relationAttr = $this->getRelationAttribute($property);

            // Union types are allowed ONLY on morphTo properties, where the union is the honest
            // documentation of the possible runtime targets (e.g. Record|Contact) and the scanner
            // needs nothing from the type but nullability — the related model is polymorphic
            // (hardcoded to Model) and the columns come from the morph name. Everywhere else the
            // single named type drives column/model resolution, so unions remain fatal.
            $isMorphToUnion = $type instanceof ReflectionUnionType && $relationAttr instanceof MorphTo;

            if (! $type instanceof ReflectionNamedType && ! $isMorphToUnion) {
                throw new RuntimeException(
                    "Property [{$property->getName()}] on [{$this->schemaClass}] must have a single named type. Union and intersection types are not supported (except a union of morph targets on a #[MorphTo] property)."
                );
            }

            // A union-typed morphTo property never reaches the scalar-column branch below, so a
            // type name is only needed for named types.
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;
            $isFillable = $this->hasAttribute($property, Fillable::class);
            $isHidden = $this->hasAttribute($property, Hidden::class);
            $isWith = $this->hasAttribute($property, With::class);

            if ($relationAttr !== null) {
                $rel = $this->scanRelationship($property, $relationAttr, $type->allowsNull());
                $relationships[] = $rel;

                if ($relationAttr instanceof BelongsTo) {
                    $fkColumn = $this->buildForeignKeyColumn($property, $rel);
                    $pendingFkColumns[] = [$fkColumn, $property, $isFillable, $isHidden];
                } elseif ($relationAttr instanceof MorphTo) {
                    // MorphTo generates two synthetic columns: {name}_type (string) and
                    // {name}_id (unsignedBigInteger). Schemas sometimes declare these
                    // explicitly as typed scalar properties — e.g. a backed Enum for _type
                    // or a plain int for _id — to give them precise PHP types and casts that
                    // the generic MorphTo defaults cannot express.
                    //
                    // We defer these columns into $pendingFkColumns rather than merging them
                    // immediately, for the same reason BelongsTo does: the dedup pass below
                    // runs after ALL scalar properties have been collected, so it can detect
                    // whether an explicit declaration already exists. When it does, the
                    // explicit column wins (preserving the developer's intentional type), and
                    // only the #[Index] flag is merged in from the relationship if needed.
                    //
                    // Without this deferral, both the explicit column (correct type) and the
                    // synthetic column (generic fallback type) would appear in $table->columns,
                    // causing every downstream consumer — resource generators, SDK generators,
                    // migration generators — to emit duplicate properties or columns.
                    $morphIndex = $this->hasAttribute($property, Index::class);
                    $columnTypeAttr = $this->getAttributeInstance($property, ColumnType::class);

                    foreach ($this->buildMorphToColumns($rel, $morphIndex, $columnTypeAttr?->type) as $morphColumn) {
                        $pendingFkColumns[] = [$morphColumn, $property, $isFillable, $isHidden];
                    }
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

        // Resolve pending FK/morph columns — dedup against explicitly declared scalar columns.
        //
        // BelongsTo and MorphTo both generate synthetic columns (_id, _type) that may
        // duplicate properties the developer has explicitly declared on the schema class.
        // Explicit declarations always win: they carry the developer's intentional PHP type,
        // cast, and nullability. The only thing we take from the relationship is the
        // #[Index] flag — if the relationship property is annotated with #[Index] and the
        // explicit column is not yet indexed, we promote it here so no indexing intent is lost.
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

        $inferred = $this->inferColumnType($typeName, $type, $property);

        $columnType = $inferred['columnType'];
        $castType = $inferred['castType'];
        $unsigned = $inferred['unsigned'] ?? false;
        $length = $inferred['length'] ?? null;
        $precision = $inferred['precision'] ?? null;
        $scale = $inferred['scale'] ?? null;
        $dataSchemaClass = $inferred['dataSchemaClass'] ?? null;
        $collectionItemClass = $inferred['collectionItemClass'] ?? null;
        $unique = false;
        $index = false;
        $primary = false;
        $autoIncrement = false;
        $generated = false;
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
                $attrInstance instanceof Generated => $generated = true,
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
            generated: $generated,
            dataSchemaClass: $dataSchemaClass,
            collectionItemClass: $collectionItemClass,
        );
    }

    /**
     * @return array{columnType: string, castType: string|null, dataSchemaClass?: string, collectionItemClass?: string}
     */
    private function inferColumnType(string $typeName, ReflectionNamedType $type, ReflectionProperty $property): array
    {
        // Bare Illuminate Collection on a Schema property is not supported — users must
        // extend SchemaCraft\Primitives\CollectionColumn (a recognized framework primitive) and
        // declare the item type via class-level #[CollectionOf]. The SchemaCraftType branch
        // below handles the primitive subclass uniformly; this guard surfaces the misuse
        // explicitly rather than silently treating Collection as an untyped column.
        if (! $type->isBuiltin()
            && is_a($typeName, \Illuminate\Support\Collection::class, true)
            && ! is_subclass_of($typeName, \SchemaCraft\Primitives\CollectionColumn::class)
        ) {
            throw new RuntimeException(
                "Property [{$property->getName()}] on schema [{$this->schemaClass}] is typed as a Collection "
                .'but does not extend SchemaCraft\\Primitives\\CollectionColumn. Schema columns must use a CollectionColumn '
                .'primitive subclass that declares its item type via class-level #[CollectionOf(ItemSchema::class)].'
            );
        }

        // Bare DataSchema on a Schema property is not supported — DataSchema is the pure
        // shape primitive (no column behavior). Schema columns of object shape must extend
        // SchemaCraft\Primitives\JsonColumn (which extends DataSchema and adds the cast +
        // generator-dispatch surface). DataSchema-typed properties remain valid in Resources,
        // Actions, and nested-DataSchema contexts — those are non-column uses where the cast
        // surface isn't required.
        if (! $type->isBuiltin()
            && is_subclass_of($typeName, DataSchema::class)
            && ! is_subclass_of($typeName, \SchemaCraft\Primitives\JsonColumn::class)
        ) {
            throw new RuntimeException(
                "Property [{$property->getName()}] on schema [{$this->schemaClass}] is typed as a bare DataSchema "
                ."({$typeName}). Schema columns of object shape must extend SchemaCraft\\Primitives\\JsonColumn "
                .'so the framework can wire the Eloquent cast. Bare DataSchemas are valid as nested item types, '
                .'Action payloads, or Resource properties — but not as Schema column types directly.'
            );
        }

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

            // Primitive-aware shape introspection. DataSchema and Collection are the two
            // recognized "container" primitives; both populate a sibling field on the column
            // so the SDK pipeline can emit typed inner DTOs without re-introspecting the
            // castType class. Bitmask doesn't carry a separate shape class — its flag set
            // comes from getBitFlags() at SDK gen time, no column-level field needed.
            if (is_subclass_of($typeName, DataSchema::class)) {
                $result['dataSchemaClass'] = $typeName;
            } elseif (is_subclass_of($typeName, \SchemaCraft\Primitives\CollectionColumn::class)) {
                $result['collectionItemClass'] = $typeName::itemClass();
            }

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
        // Resolve at the door: a relation may reference a Schema for nicer
        // schema-to-schema navigation; collapse it to the Model here so every
        // downstream consumer keeps seeing a Model FQCN. Pass-through for Models.
        $pivotModel = $belongsToManyAttr?->using === null
            ? null
            : SchemaResolver::resolveModelClass($belongsToManyAttr->using);

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
            : SchemaResolver::resolveModelClass($relationAttr->model);

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

        // Through relationship params — the intermediate `through` class may also
        // be declared as a Schema; resolve it to its Model at the door too.
        $through = property_exists($relationAttr, 'through') && $relationAttr->through !== null
            ? SchemaResolver::resolveModelClass($relationAttr->through)
            : null;
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
