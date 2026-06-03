<?php

namespace SchemaCraft;

use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use SchemaCraft\Contracts\CastsDataSchemaProperty;
use SchemaCraft\Contracts\SchemaCraftColumn;
use SchemaCraft\Exceptions\DataSchemaHydrationException;
use SchemaCraft\Generators\GeneratorColumn;
use SchemaCraft\Scanner\ColumnDefinition;

/**
 * Base class for defining typed data structures.
 *
 * Extend this class to define the shape of structured data using typed
 * properties — mirroring how Schema defines table columns.
 *
 * A DataSchema *is* a column type. The class is simultaneously:
 *   - the shape declaration (typed properties — what fields, what types)
 *   - the Eloquent cast (implements CastsAttributes — hydrates from JSON, serializes back)
 *   - the schema-craft column type (implements SchemaCraftColumn — answers the generator
 *     dispatch for migration / faker / Filament / validation / SDK)
 *
 * Symmetric with how Bitmask works: the class identity carries the cast, the schema
 * declaration, and the generator surface in one place. No wrapper layer.
 *
 * DataSchema classes can be used in Actions (nested relationship data), as JSON column
 * types on Schemas (`public AddressShape $address`), in templates, or anywhere a typed
 * data structure is needed.
 */
abstract class DataSchema implements \JsonSerializable, CastsAttributes, SchemaCraftColumn
{
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
     * }>
     */
    private static function resolveProperties(): array
    {
        $class = static::class;

        if (isset(self::$propertyCache[$class])) {
            return self::$propertyCache[$class];
        }

        $ref = new ReflectionClass($class);
        $properties = [];

        foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) {
                continue;
            }

            $declaringClass = $prop->getDeclaringClass()->getName();
            if ($declaringClass === self::class) {
                continue;
            }

            $type = $prop->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;
            $isBuiltin = $type instanceof ReflectionNamedType && $type->isBuiltin();
            $nullable = $type instanceof ReflectionNamedType ? $type->allowsNull() : true;
            $isDataSchema = $typeName !== null && ! $isBuiltin && is_subclass_of($typeName, self::class, true);
            $isCastsProperty = $typeName !== null && ! $isBuiltin && is_a($typeName, CastsDataSchemaProperty::class, true);
            $isBackedEnum = $typeName !== null && ! $isBuiltin && is_subclass_of($typeName, \BackedEnum::class, true);
            $isDatetime = $typeName !== null && ! $isBuiltin && is_a($typeName, \DateTimeInterface::class, true);

            $properties[] = [
                'name' => $prop->getName(),
                'typeName' => $typeName,
                'isBuiltin' => $isBuiltin,
                'nullable' => $nullable,
                'hasDefault' => $prop->hasDefaultValue(),
                'default' => $prop->hasDefaultValue() ? $prop->getDefaultValue() : null,
                'isDataSchema' => $isDataSchema,
                'isCastsProperty' => $isCastsProperty,
                'isBackedEnum' => $isBackedEnum,
                'isDatetime' => $isDatetime,
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

    // ─── CastsAttributes (Eloquent) ─────────────────────────────
    // The cast IS the class — Laravel's $casts entry can name a DataSchema FQCN
    // directly and Eloquent will instantiate + dispatch get/set here. Bridge from
    // the JSON column value into a typed DataSchema instance and back.
    //
    // Note: stateless dispatcher. Laravel creates one instance of the cast and calls
    // get/set on it; the instance never holds the hydrated data — it returns a fresh
    // typed instance from `static::fromArray()` each time.

    public function get(Model $model, string $key, mixed $value, array $attributes): ?DataSchema
    {
        if ($value === null) {
            return null;
        }

        $data = is_string($value) ? json_decode($value, true) : $value;

        return static::fromArray(is_array($data) ? $data : []);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof self) {
            return json_encode($value->toArray());
        }

        if (is_array($value)) {
            return json_encode($value);
        }

        return (string) $value;
    }

    // ─── SchemaCraftType ─────────────────────────────────────────
    // Every DataSchema column stores as a JSON column. Schema-level attributes
    // (#[Length], #[Decimal], etc.) don't apply to JSON columns; modifiers/rules stay flat.

    public static function schemaColumnType(): string
    {
        return 'json';
    }

    public static function schemaColumnModifiers(): array
    {
        return [];
    }

    public static function schemaValidationRules(): array
    {
        return ['array'];
    }

    // ─── CastsDataSchemaProperty ─────────────────────────────────
    // Called when a parent DataSchema has a property typed as this DataSchema —
    // routes through fromArray/toArray so nested shapes hydrate uniformly.

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

    // ─── FormatsApiOutput / ParsesApiInput ───────────────────────

    public function toApiRepresentation(): array
    {
        return $this->toArray();
    }

    public static function fromApiInput(mixed $input): static
    {
        if (! is_array($input)) {
            throw new \InvalidArgumentException(static::class.' API input must be an array.');
        }

        return static::fromArray($input);
    }

    // ─── GeneratesFakerValue / GeneratesSdkType ──────────────────

    public static function fakerExpression(ColumnDefinition $column): string
    {
        return '[]';
    }

    public static function sdkType(): string
    {
        return 'array';
    }

    // ─── FilamentRenderable ──────────────────────────────────────
    // Generic defaults — projects can override per-DataSchema if they want a
    // different Filament rendering for their specific shape.

    public static function asFilamentField(GeneratorColumn $column): string
    {
        return "Forms\\Components\\KeyValue::make('{$column->name}')";
    }

    public static function asFilamentColumn(GeneratorColumn $column): string
    {
        return "Tables\\Columns\\TextColumn::make('{$column->name}')";
    }

    public static function asFilamentEntry(GeneratorColumn $column): string
    {
        return "Infolists\\Components\\TextEntry::make('{$column->name}')";
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
