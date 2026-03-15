<?php

namespace SchemaCraft\Scanner;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use SchemaCraft\Action;
use SchemaCraft\Attributes\Actions\ActionMeta;
use SchemaCraft\Attributes\Relations\BelongsTo;
use SchemaCraft\Attributes\Relations\BelongsToMany;
use SchemaCraft\Attributes\Relations\HasMany;
use SchemaCraft\Attributes\Relations\HasOne;
use SchemaCraft\Attributes\Relations\MorphMany;
use SchemaCraft\Attributes\Relations\MorphOne;
use SchemaCraft\Attributes\Relations\MorphTo;
use SchemaCraft\Attributes\Relations\MorphToMany;
use SchemaCraft\Config\ConfigResolver;
use SchemaCraft\Schema;

/**
 * Reflects on an Action class and produces an ActionDefinition value object.
 *
 * Mirrors the SchemaScanner pattern — reads typed properties and attributes
 * to discover the action's parameter definitions, FK relationships, and metadata.
 */
class ActionScanner
{
    /** @var array<string, string[]> Relationship types that represent collections. */
    private const COLLECTION_TYPES = [
        'hasMany' => HasMany::class,
        'belongsToMany' => BelongsToMany::class,
        'morphMany' => MorphMany::class,
        'morphToMany' => MorphToMany::class,
    ];

    /** @var array<string, string[]> Relationship types that represent singular related models. */
    private const SINGULAR_TYPES = [
        'hasOne' => HasOne::class,
        'morphOne' => MorphOne::class,
    ];

    public function __construct(
        /** @var class-string<Action> */
        private string $actionClass,
    ) {}

    public function scan(): ActionDefinition
    {
        $ref = new ReflectionClass($this->actionClass);

        $meta = $this->readMeta($ref);
        $parameters = $this->scanParameters($ref);
        $serviceMethod = $this->actionClass::serviceMethod();
        $schemaClass = $this->actionClass::schema();

        $name = $this->resolveName($ref);

        return new ActionDefinition(
            actionClass: $this->actionClass,
            name: $name,
            httpMethod: $meta?->method ?? 'put',
            label: $meta?->label,
            description: $meta?->description,
            serviceMethod: $serviceMethod,
            schemaClass: $schemaClass,
            parameters: $parameters,
        );
    }

    /**
     * Resolve a model FQCN to its corresponding Schema class.
     *
     * Convention: App\Models\Contact → App\Schemas\ContactSchema
     * Also checks configured schema namespaces via ConfigResolver.
     */
    public static function resolveRelatedSchemaClass(string $modelFqcn): ?string
    {
        $parts = explode('\\', $modelFqcn);
        $modelBaseName = array_pop($parts);
        $namespace = implode('\\', $parts);

        // Replace \Models segment with \Schemas
        $schemaNamespace = preg_replace('/\\\\Models(\\\\|$)/', '\\Schemas$1', $namespace, 1);
        $schemaClass = $schemaNamespace.'\\'.$modelBaseName.'Schema';

        if (class_exists($schemaClass) && is_subclass_of($schemaClass, Schema::class)) {
            return $schemaClass;
        }

        // Fall back to checking configured connection namespaces
        if (class_exists(ConfigResolver::class)) {
            try {
                foreach (ConfigResolver::allConnectionNames() as $name) {
                    $config = ConfigResolver::resolveConnection($name);
                    $candidate = $config->schemaClass($modelBaseName);

                    if (class_exists($candidate) && is_subclass_of($candidate, Schema::class)) {
                        return $candidate;
                    }
                }
            } catch (\Throwable) {
                // ConfigResolver may not be available in unit tests
            }
        }

        return null;
    }

    private function readMeta(ReflectionClass $ref): ?ActionMeta
    {
        $attrs = $ref->getAttributes(ActionMeta::class);

        return ! empty($attrs) ? $attrs[0]->newInstance() : null;
    }

    /**
     * Scan typed properties to build parameter definitions.
     *
     * @return ActionParameter[]
     */
    private function scanParameters(ReflectionClass $ref): array
    {
        $parameters = [];

        foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) {
                continue;
            }

            if ($prop->getDeclaringClass()->getName() === Action::class) {
                continue;
            }

            $type = $prop->getType();
            if (! $type instanceof ReflectionNamedType) {
                continue;
            }

            $parameters[] = $this->buildParameter($prop, $type);
        }

        return $parameters;
    }

    private function buildParameter(ReflectionProperty $prop, ReflectionNamedType $type): ActionParameter
    {
        $typeName = $type->getName();
        $nullable = $type->allowsNull();
        $hasDefault = $prop->hasDefaultValue();
        $default = $hasDefault ? $prop->getDefaultValue() : null;

        // Check for nested relationship attributes (with non-empty fields)
        $nestedParam = $this->tryBuildNestedParameter($prop, $nullable, $hasDefault, $default);
        if ($nestedParam !== null) {
            return $nestedParam;
        }

        // Existing behavior for FK-only relationships
        $belongsTo = $this->getAttribute($prop, BelongsTo::class);
        $morphTo = $this->getAttribute($prop, MorphTo::class);

        if ($belongsTo !== null) {
            return $this->buildBelongsToParameter($prop, $belongsTo, $nullable, $hasDefault, $default);
        }

        if ($morphTo !== null) {
            return $this->buildMorphToParameter($prop, $morphTo, $nullable, $hasDefault, $default);
        }

        if ($this->isModelType($typeName)) {
            return $this->buildModelParameter($prop, $typeName, $nullable, $hasDefault, $default);
        }

        return $this->buildScalarParameter($prop, $typeName, $nullable, $hasDefault, $default);
    }

    /**
     * Try to build a nested relationship parameter from any relationship attribute with non-empty fields.
     */
    private function tryBuildNestedParameter(
        ReflectionProperty $prop,
        bool $nullable,
        bool $hasDefault,
        mixed $default,
    ): ?ActionParameter {
        $propName = $prop->getName();

        // Check singular relationship types
        foreach (self::SINGULAR_TYPES as $relType => $attrClass) {
            $attr = $this->getAttribute($prop, $attrClass);
            if ($attr !== null && ! empty($attr->fields)) {
                return $this->buildNestedRelationshipParameter(
                    propName: $propName,
                    relationshipType: $relType,
                    relatedModel: $attr->model,
                    nullable: $nullable,
                    isCollection: false,
                    fields: $attr->fields,
                    pivotFields: [],
                    sync: false,
                    morphName: property_exists($attr, 'morphName') ? $attr->morphName : null,
                    hasDefault: $hasDefault,
                    default: $default,
                );
            }
        }

        // Check collection relationship types
        foreach (self::COLLECTION_TYPES as $relType => $attrClass) {
            $attr = $this->getAttribute($prop, $attrClass);
            if ($attr !== null && ! empty($attr->fields)) {
                return $this->buildNestedRelationshipParameter(
                    propName: $propName,
                    relationshipType: $relType,
                    relatedModel: $attr->model,
                    nullable: $nullable,
                    isCollection: true,
                    fields: $attr->fields,
                    pivotFields: property_exists($attr, 'pivotFields') ? $attr->pivotFields : [],
                    sync: property_exists($attr, 'sync') ? $attr->sync : false,
                    morphName: property_exists($attr, 'morphName') ? $attr->morphName : null,
                    hasDefault: $hasDefault,
                    default: $default,
                );
            }
        }

        // Check BelongsTo with fields (create-related pattern)
        $belongsTo = $this->getAttribute($prop, BelongsTo::class);
        if ($belongsTo !== null && ! empty($belongsTo->fields)) {
            return $this->buildNestedRelationshipParameter(
                propName: $propName,
                relationshipType: 'belongsTo',
                relatedModel: $belongsTo->model,
                nullable: $nullable,
                isCollection: false,
                fields: $belongsTo->fields,
                pivotFields: [],
                sync: false,
                morphName: null,
                hasDefault: $hasDefault,
                default: $default,
            );
        }

        return null;
    }

    private function buildNestedRelationshipParameter(
        string $propName,
        string $relationshipType,
        string $relatedModel,
        bool $nullable,
        bool $isCollection,
        array $fields,
        array $pivotFields,
        bool $sync,
        ?string $morphName,
        bool $hasDefault,
        mixed $default,
    ): ActionParameter {
        // Resolve the related schema class
        $relatedSchemaClass = self::resolveRelatedSchemaClass($relatedModel);

        // Resolve field definitions from the related schema
        $resolvedFields = [];
        $nestedRelationships = [];

        if ($relatedSchemaClass !== null) {
            [$resolvedFields, $nestedRelationships] = $this->resolveNestedFields(
                relatedSchemaClass: $relatedSchemaClass,
                fields: $fields,
                pathPrefix: $propName,
            );
        } else {
            // Schema not found — create basic field definitions without type info
            foreach ($fields as $field) {
                if (str_contains($field, '.')) {
                    // Deep nested field without schema — skip sub-relationship building
                    continue;
                }

                $resolvedFields[] = new NestedFieldDefinition(
                    name: $field,
                    dotPath: $propName.'.'.$field,
                    type: 'mixed',
                    nullable: false,
                );
            }
        }

        $nested = new NestedRelationshipParameter(
            name: $propName,
            relationshipType: $relationshipType,
            relatedModel: $relatedModel,
            nullable: $nullable,
            isCollection: $isCollection,
            fields: $resolvedFields,
            pivotFields: $pivotFields,
            sync: $sync,
            morphName: $morphName,
            relatedSchemaClass: $relatedSchemaClass,
            nestedRelationships: $nestedRelationships,
        );

        return new ActionParameter(
            name: $propName,
            type: 'array',
            nullable: $nullable,
            hasDefault: $hasDefault,
            default: $default,
            isModel: false,
            isNestedRelationship: true,
            nestedRelationship: $nested,
        );
    }

    /**
     * Resolve field definitions from a related schema.
     *
     * Handles both flat fields ('first_name') and dot-path fields ('address.city')
     * by scanning the related schema and recursing for deep nesting.
     *
     * @return array{0: NestedFieldDefinition[], 1: NestedRelationshipParameter[]}
     */
    private function resolveNestedFields(
        string $relatedSchemaClass,
        array $fields,
        string $pathPrefix,
    ): array {
        $resolvedFields = [];
        $nestedRelationships = [];

        // Group fields by their top-level segment
        $flatFields = [];
        $deepFields = []; // keyed by relationship name → sub-fields

        foreach ($fields as $field) {
            if (str_contains($field, '.')) {
                $segments = explode('.', $field, 2);
                $deepFields[$segments[0]][] = $segments[1];
            } else {
                $flatFields[] = $field;
            }
        }

        // Resolve flat fields from the schema
        try {
            $scanner = new SchemaScanner($relatedSchemaClass);
            $table = $scanner->scan();
        } catch (\Throwable) {
            // Schema can't be scanned — create basic definitions
            foreach ($flatFields as $field) {
                $resolvedFields[] = new NestedFieldDefinition(
                    name: $field,
                    dotPath: $pathPrefix.'.'.$field,
                    type: 'mixed',
                    nullable: false,
                );
            }

            return [$resolvedFields, $nestedRelationships];
        }

        foreach ($flatFields as $field) {
            $column = $this->findColumn($table, $field);

            if ($column !== null) {
                $resolvedFields[] = new NestedFieldDefinition(
                    name: $field,
                    dotPath: $pathPrefix.'.'.$field,
                    type: $this->columnTypeToPhpType($column->columnType),
                    nullable: $column->nullable,
                    columnType: $column->columnType,
                );
            } else {
                // Column not found on schema — use basic definition
                $resolvedFields[] = new NestedFieldDefinition(
                    name: $field,
                    dotPath: $pathPrefix.'.'.$field,
                    type: 'mixed',
                    nullable: false,
                );
            }
        }

        // Resolve deep fields by finding relationships on the related schema
        foreach ($deepFields as $relName => $subFields) {
            $rel = $this->findRelationship($table, $relName);

            if ($rel === null) {
                continue;
            }

            $subSchemaClass = self::resolveRelatedSchemaClass($rel->relatedModel);
            $subPath = $pathPrefix.'.'.$relName;

            if ($subSchemaClass !== null) {
                [$subResolvedFields, $subNested] = $this->resolveNestedFields(
                    relatedSchemaClass: $subSchemaClass,
                    fields: $subFields,
                    pathPrefix: $subPath,
                );
            } else {
                $subResolvedFields = [];
                $subNested = [];
                foreach ($subFields as $subField) {
                    $subResolvedFields[] = new NestedFieldDefinition(
                        name: $subField,
                        dotPath: $subPath.'.'.$subField,
                        type: 'mixed',
                        nullable: false,
                    );
                }
            }

            $isCollection = in_array($rel->type, ['hasMany', 'belongsToMany', 'morphMany', 'morphToMany']);

            $nestedRelationships[] = new NestedRelationshipParameter(
                name: $relName,
                relationshipType: $rel->type,
                relatedModel: $rel->relatedModel,
                nullable: $rel->nullable,
                isCollection: $isCollection,
                fields: $subResolvedFields,
                pivotFields: [],
                sync: false,
                morphName: $rel->morphName ?? null,
                relatedSchemaClass: $subSchemaClass,
                nestedRelationships: $subNested,
            );
        }

        return [$resolvedFields, $nestedRelationships];
    }

    /**
     * Find a column definition in the table by name.
     */
    private function findColumn(TableDefinition $table, string $name): ?ColumnDefinition
    {
        foreach ($table->columns as $column) {
            if ($column->name === $name) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Find a relationship definition in the table by name.
     */
    private function findRelationship(TableDefinition $table, string $name): ?RelationshipDefinition
    {
        foreach ($table->relationships as $rel) {
            if ($rel->name === $name) {
                return $rel;
            }
        }

        return null;
    }

    /**
     * Convert a schema column type to a PHP type.
     */
    private function columnTypeToPhpType(string $columnType): string
    {
        return match ($columnType) {
            'integer', 'bigInteger', 'unsignedBigInteger', 'unsignedInteger',
            'smallInteger', 'tinyInteger', 'mediumInteger' => 'int',
            'float', 'double', 'decimal' => 'float',
            'boolean' => 'bool',
            'json', 'jsonb' => 'array',
            default => 'string',
        };
    }

    private function buildBelongsToParameter(
        ReflectionProperty $prop,
        BelongsTo $belongsTo,
        bool $nullable,
        bool $hasDefault,
        mixed $default,
    ): ActionParameter {
        $modelClass = $belongsTo->model;
        $propName = $prop->getName();
        $foreignKeyColumn = Str::snake($propName).'_id';

        return new ActionParameter(
            name: $propName,
            type: class_basename($modelClass),
            nullable: $nullable,
            hasDefault: $hasDefault,
            default: $default,
            isModel: true,
            modelClass: $modelClass,
            foreignKeyColumn: $foreignKeyColumn,
            relationship: $propName,
        );
    }

    private function buildMorphToParameter(
        ReflectionProperty $prop,
        MorphTo $morphTo,
        bool $nullable,
        bool $hasDefault,
        mixed $default,
    ): ActionParameter {
        $propName = $prop->getName();
        $morphName = $morphTo->morphName ?? $propName;

        return new ActionParameter(
            name: $propName,
            type: 'Model',
            nullable: $nullable,
            hasDefault: $hasDefault,
            default: $default,
            isModel: true,
            modelClass: null,
            foreignKeyColumn: $morphName.'_id',
            relationship: $morphName,
        );
    }

    private function buildModelParameter(
        ReflectionProperty $prop,
        string $typeName,
        bool $nullable,
        bool $hasDefault,
        mixed $default,
    ): ActionParameter {
        $propName = $prop->getName();
        $foreignKeyColumn = Str::snake($propName).'_id';

        return new ActionParameter(
            name: $propName,
            type: class_basename($typeName),
            nullable: $nullable,
            hasDefault: $hasDefault,
            default: $default,
            isModel: true,
            modelClass: $typeName,
            foreignKeyColumn: $foreignKeyColumn,
            relationship: $propName,
        );
    }

    private function buildScalarParameter(
        ReflectionProperty $prop,
        string $typeName,
        bool $nullable,
        bool $hasDefault,
        mixed $default,
    ): ActionParameter {
        $propName = $prop->getName();
        $columnName = Str::snake($propName);

        return new ActionParameter(
            name: $propName,
            type: $typeName,
            nullable: $nullable,
            hasDefault: $hasDefault,
            default: $default,
            isModel: false,
            columnName: $columnName,
        );
    }

    /**
     * Resolve the action name from the class basename.
     * Strips trailing 'Action' suffix and lowercases first letter.
     */
    private function resolveName(ReflectionClass $ref): string
    {
        $className = $ref->getShortName();
        $baseName = preg_replace('/Action$/', '', $className);

        return lcfirst($baseName);
    }

    private function isModelType(string $typeName): bool
    {
        return is_a($typeName, Model::class, true);
    }

    /**
     * @template T
     *
     * @param  class-string<T>  $attributeClass
     * @return T|null
     */
    private function getAttribute(ReflectionProperty $prop, string $attributeClass): mixed
    {
        $attrs = $prop->getAttributes($attributeClass);

        return ! empty($attrs) ? $attrs[0]->newInstance() : null;
    }
}
