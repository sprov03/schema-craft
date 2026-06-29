<?php

namespace SchemaCraft;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use SchemaCraft\Contracts\CastsDataSchemaProperty;
use SchemaCraft\Exceptions\DataSchemaHydrationException;

/**
 * Pure shape primitive — typed data structure, no column behavior.
 *
 * Extend this class to define the shape of structured data: typed properties, the
 * fromArray/toArray round-trip, the nested-rules walker for validation. The class
 * does NOT serve as a Schema column type on its own — for that, use a JsonColumn
 * primitive (`SchemaCraft\Primitives\JsonColumn`) that extends DataSchema and adds
 * the Castable + SchemaCraftColumn surface.
 *
 * Where DataSchemas are used:
 *   - As items inside a CollectionColumn (`#[CollectionOf(MyItem::class)]`)
 *   - As nested typed properties inside other DataSchemas or JsonColumns
 *   - In Action payloads (request shape, response shape)
 *   - In Resource properties where the shape is not a DB column
 *   - In templates and anywhere else a typed data structure is needed
 *
 * The class implements CastsDataSchemaProperty so it can be a typed property INSIDE
 * another DataSchema — the framework's nested-hydration path calls fromRaw/toRaw
 * to load/dehydrate the nested value. That's shape-internal, not column-related.
 *
 * For column reads, the JsonColumn primitive's castUsing() delegates to this class's
 * resolveFromColumnValue() helper so the smart "non-nullable property + null DB value
 * → hydrate with defaults" behavior is implemented in one place and reused.
 */
abstract class DataSchema implements \JsonSerializable, CastsDataSchemaProperty
{
    /**
     * Cached schema-property-nullability per (model class, key). Used by
     * resolveFromColumnValue() so the reflection happens once per pair, not per cast read.
     *
     * @var array<string, bool>
     */
    private static array $nullabilityCache = [];

    /**
     * Optional link to the related model's Schema class.
     * When set, enables automatic validation rule derivation and column type resolution.
     *
     * @var class-string<Schema>
     */
    protected static string $schema = '';

    /**
     * Cached property metadata per class.
     *
     * Keyed by class name → array of property descriptors. Populated once
     * per class via reflection, reused for all subsequent hydration/serialization calls.
     *
     * @var array<class-string, array<int, array{
     *     name: string,
     *     typeName: string|null,
     *     isBuiltin: bool,
     *     nullable: bool,
     *     hasDefault: bool,
     *     default: mixed,
     *     isDataSchema: bool,
     *     isCastsProperty: bool,
     *     isBackedEnum: bool,
     *     isDatetime: bool,
     *     isCollectionColumn: bool,
     *     collectionItemClass: class-string<DataSchema>|null,
     * }>>
     */
    private static array $propertyCache = [];

    /**
     * Get the schema class this DataSchema references.
     *
     * @return class-string<Schema>|null
     */
    public static function schema(): ?string
    {
        return static::$schema !== '' ? static::$schema : null;
    }

    /**
     * Public reflection accessor for the SDK generator.
     *
     * The SDK's SdkDataGenerator reflects a DataSchema's typed properties to emit a
     * typed nested DTO (rather than a bare `array`). It needs the same field metadata
     * hydration uses, so this exposes the cached descriptors without duplicating the
     * reflection logic.
     *
     * @return array<int, array{
     *     name: string,
     *     typeName: string|null,
     *     isBuiltin: bool,
     *     nullable: bool,
     *     hasDefault: bool,
     *     default: mixed,
     *     isDataSchema: bool,
     *     isCastsProperty: bool,
     *     isBackedEnum: bool,
     *     isDatetime: bool,
     *     isCollectionColumn: bool,
     *     collectionItemClass: class-string<DataSchema>|null,
     * }>
     */
    public static function fieldDescriptors(): array
    {
        return static::resolveProperties();
    }

    /**
     * Resolve and cache property metadata for the given class.
     *
     * @return array<int, array{
     *     name: string,
     *     typeName: string|null,
     *     isBuiltin: bool,
     *     nullable: bool,
     *     hasDefault: bool,
     *     default: mixed,
     *     isDataSchema: bool,
     *     isCastsProperty: bool,
     *     isBackedEnum: bool,
     *     isDatetime: bool,
     *     isCollectionColumn: bool,
     *     collectionItemClass: class-string<DataSchema>|null,
     * }>
     */
    private static function resolveProperties(): array
    {
        $class = static::class;

        if (isset(self::$propertyCache[$class])) {
            return self::$propertyCache[$class];
        }

        // Detection now lives in the shared TypedPropertyReflector (one source of truth, also
        // used by the response-side ResourceScanner). We map its unified descriptor back to
        // DataSchema's established key names so every existing consumer (validationRules,
        // fromArray, fieldDescriptors, SdkDataGenerator) is untouched. isCastsProperty stays
        // computed here — it's a DataSchema-specific contract (CastsDataSchemaProperty), not a
        // shape concept the reflector knows about.
        $properties = [];
        foreach (\SchemaCraft\Scanner\TypedPropertyReflector::scan($class, self::class) as $d) {
            $typeName = $d['typeName'];

            $properties[] = [
                'name' => $d['name'],
                'typeName' => $typeName,
                'isBuiltin' => $d['isBuiltin'],
                'nullable' => $d['nullable'],
                'hasDefault' => $d['hasDefault'],
                'default' => $d['default'],
                'isDataSchema' => $d['isNestedShape'],
                'isCastsProperty' => $typeName !== null && ! $d['isBuiltin']
                    && is_a($typeName, CastsDataSchemaProperty::class, true),
                'isBackedEnum' => $d['isBackedEnum'],
                'isDatetime' => $d['isDatetime'],
                'isCollectionColumn' => $d['isCollection'],
                'collectionItemClass' => $d['collectionItemClass'],
            ];
        }

        self::$propertyCache[$class] = $properties;

        return $properties;
    }

    /**
     * Build nested validation rules from this DataSchema's typed properties.
     *
     * Returns dot-notation rules relative to a given prefix.
     * E.g., for prefix "metadata":
     *   ['metadata.key' => ['required', 'string'], 'metadata.value' => ['nullable', 'integer']]
     *
     * @return array<string, array<int, string>>
     */
    public static function validationRules(string $prefix = ''): array
    {
        $rules = [];

        foreach (static::resolveProperties() as $prop) {
            $fullKey = $prefix !== '' ? "{$prefix}.{$prop['name']}" : $prop['name'];

            $fieldRules = [];
            $fieldRules[] = $prop['nullable'] ? 'nullable' : 'required';

            // Check if property type is itself a DataSchema (nested object)
            if ($prop['isDataSchema']) {
                $fieldRules[] = 'array';
                $rules[$fullKey] = $fieldRules;

                // Recurse into the nested DataSchema
                $nestedRules = $prop['typeName']::validationRules($fullKey);
                $rules = array_merge($rules, $nestedRules);

                continue;
            }

            // Typed collection of shapes: validate the container as an array, then cascade the
            // item shape's own rules under the `.*` wildcard. Mirrors the nested-object branch
            // above; the only difference is the `.*` prefix. Same pattern ValidationRuleMapper
            // already uses at the Schema layer — kept in one place to avoid a third copy.
            if ($prop['isCollectionColumn'] && $prop['collectionItemClass'] !== null) {
                $fieldRules[] = 'array';
                $rules[$fullKey] = $fieldRules;

                $rules = array_merge($rules, $prop['collectionItemClass']::validationRules("{$fullKey}.*"));

                continue;
            }

            // Map PHP types to validation rules
            $typeRules = match ($prop['typeName']) {
                'string' => ['string'],
                'int' => ['integer'],
                'float' => ['numeric'],
                'bool' => ['boolean'],
                'array' => ['array'],
                default => ['string'],
            };

            $rules[$fullKey] = array_merge($fieldRules, $typeRules);
        }

        return $rules;
    }

    /**
     * Create and hydrate an instance from an associative array.
     *
     * Strictly enforces the typed property contract:
     * - Nullable properties default to null when not provided.
     * - Properties with PHP defaults use those defaults when not provided.
     * - Non-nullable properties without defaults MUST be present in the data
     *   or a DataSchemaHydrationException is thrown.
     * - Nested DataSchema properties are recursively hydrated.
     *
     * @param  array<string, mixed>|null  $data
     *
     * @throws DataSchemaHydrationException
     */
    public static function fromArray(?array $data): static
    {
        $instance = new static;
        $properties = static::resolveProperties();

        foreach ($properties as $prop) {
            $name = $prop['name'];

            // Value exists in data
            if ($data !== null && array_key_exists($name, $data)) {
                $value = $data[$name];

                // Null value — respect nullability
                if ($value === null) {
                    if ($prop['nullable']) {
                        $instance->{$name} = null;

                        continue;
                    }

                    // Non-nullable DataSchema — create with defaults
                    if ($prop['isDataSchema']) {
                        $instance->{$name} = $prop['typeName']::fromArray(null);

                        continue;
                    }
                }

                // Nested DataSchema — recurse
                if ($prop['isDataSchema'] && $value !== null) {
                    $instance->{$name} = $prop['typeName']::fromArray((array) $value);

                    continue;
                }

                // CastsDataSchemaProperty — custom fromRaw/toRaw
                if ($prop['isCastsProperty'] && $value !== null) {
                    $instance->{$name} = $prop['typeName']::fromRaw($value);

                    continue;
                }

                // BackedEnum — PHP native ::from()
                if ($prop['isBackedEnum'] && $value !== null) {
                    $instance->{$name} = $prop['typeName']::from($value);

                    continue;
                }

                // DateTimeInterface — Carbon::parse()
                if ($prop['isDatetime'] && $value !== null) {
                    $instance->{$name} = Carbon::parse($value);

                    continue;
                }

                $instance->{$name} = self::castValue($value, $prop['typeName'], $prop['isBuiltin']);

                continue;
            }

            // Value NOT in data — resolve from defaults or nullability
            if ($prop['hasDefault']) {
                $instance->{$name} = $prop['default'];
            } elseif ($prop['nullable']) {
                $instance->{$name} = null;
            } elseif ($prop['isDataSchema']) {
                // Non-nullable nested DataSchema — recursively create with defaults
                $instance->{$name} = $prop['typeName']::fromArray(null);
            } else {
                // Non-nullable, no default, not provided — strict error
                throw DataSchemaHydrationException::missingRequiredField(static::class, $name);
            }
        }

        return $instance;
    }

    /**
     * Convert the instance to an associative array.
     *
     * Recursively converts nested DataSchema instances.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $properties = static::resolveProperties();
        $result = [];

        foreach ($properties as $prop) {
            $name = $prop['name'];

            // Check if property is initialized (typed properties without defaults may not be)
            try {
                $value = $this->{$name};
            } catch (\Error) {
                $value = null;
            }

            if ($value instanceof self) {
                $result[$name] = $value->toArray();
            } elseif ($value instanceof CastsDataSchemaProperty) {
                $result[$name] = $value->toRaw();
            } elseif ($value instanceof \BackedEnum) {
                $result[$name] = $value->value;
            } elseif ($value instanceof \DateTimeInterface) {
                $result[$name] = $value->toIso8601String();
            } else {
                $result[$name] = $value;
            }
        }

        return $result;
    }

    /**
     * Serialize to JSON string.
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    // ─── CastsDataSchemaProperty (nested-property hydration) ────────
    // Called when a parent DataSchema has a property typed as this DataSchema.
    // Routes through fromArray/toArray so nested shapes hydrate uniformly. This is
    // shape-internal (DataSchema-inside-DataSchema), not column-related.

    public static function fromRaw(mixed $value): static
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return static::fromArray(is_array($value) ? $value : []);
    }

    public function toRaw(): array
    {
        return $this->toArray();
    }

    // ─── Column-read resolution (shape-aware, nullability-aware) ────
    // Called from JsonColumn's castUsing() handler to bridge a DB JSON value into a
    // hydrated DataSchema instance. Honors the SchemaModel property's declared
    // nullability: non-nullable typed property + null DB value → hydrate with defaults
    // so the model never holds a null where the type says non-null. Nullable property
    // + null DB value → null. Non-null DB value → hydrate normally.
    //
    // Centralized here so the JsonColumn cast handler is a one-liner and any future
    // smart-resolve behavior (defaults from migration, lazy hydration, etc.) lives
    // in one place.

    final public static function resolveFromColumnValue(
        Model $model,
        string $key,
        mixed $value,
    ): ?static {
        if ($value === null) {
            return self::isSchemaPropertyNullable($model, $key)
                ? null
                : static::fromArray(null);
        }

        $data = is_string($value) ? json_decode($value, true) : $value;

        return static::fromArray(is_array($data) ? $data : null);
    }

    /**
     * Read the SchemaModel's schema property declaration to determine whether the
     * column's property is nullable. Cached per (model class, key). Returns true
     * (treat as nullable) when nullability can't be determined — defensive default
     * matches Laravel's "cast returned null is fine" convention.
     */
    private static function isSchemaPropertyNullable(Model $model, string $key): bool
    {
        $cacheKey = $model::class.'::'.$key;

        if (isset(self::$nullabilityCache[$cacheKey])) {
            return self::$nullabilityCache[$cacheKey];
        }

        $nullable = true;

        // Only SchemaModels carry a Schema reference; for non-SchemaModel users,
        // there's no declared property to inspect, so the default (nullable) holds.
        if ($model instanceof SchemaModel) {
            try {
                $modelRef = new \ReflectionClass($model);
                $schemaProp = $modelRef->getProperty('schema');
                $schemaClass = $schemaProp->getValue();
            } catch (\ReflectionException) {
                self::$nullabilityCache[$cacheKey] = $nullable;

                return $nullable;
            }

            try {
                $ref = new \ReflectionClass($schemaClass);
                if ($ref->hasProperty($key)) {
                    $prop = $ref->getProperty($key);
                    $type = $prop->getType();
                    $nullable = $type instanceof \ReflectionNamedType ? $type->allowsNull() : true;
                }
            } catch (\ReflectionException) {
                // Fall through to default
            }
        }

        self::$nullabilityCache[$cacheKey] = $nullable;

        return $nullable;
    }

    /**
     * Cast a value to the expected PHP type.
     */
    private static function castValue(mixed $value, ?string $typeName, bool $isBuiltin): mixed
    {
        if ($value === null) {
            return null;
        }

        if (! $isBuiltin || $typeName === null) {
            return $value;
        }

        return match ($typeName) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => (bool) $value,
            'string' => (string) $value,
            'array' => (array) $value,
            default => $value,
        };
    }
}
