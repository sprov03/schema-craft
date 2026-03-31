<?php

namespace SchemaCraft;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use SchemaCraft\Exceptions\DataSchemaHydrationException;

/**
 * Base class for defining typed data structures.
 *
 * Extend this class to define the shape of structured data using typed
 * properties — mirroring how Schema defines table columns.
 *
 * DataSchema classes are purely structural: they define fields, types,
 * and nullability. They can be used in Actions (nested relationship data),
 * JSON columns (DTO shapes), templates, or anywhere a typed data structure
 * is needed.
 */
abstract class DataSchema implements \JsonSerializable
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

            $properties[] = [
                'name' => $prop->getName(),
                'typeName' => $typeName,
                'isBuiltin' => $isBuiltin,
                'nullable' => $nullable,
                'hasDefault' => $prop->hasDefaultValue(),
                'default' => $prop->hasDefaultValue() ? $prop->getDefaultValue() : null,
                'isDataSchema' => $isDataSchema,
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

                // Nested DataSchema — recurse
                if ($prop['isDataSchema']) {
                    if ($value === null && $prop['nullable']) {
                        $instance->{$name} = null;
                    } elseif ($value === null) {
                        // Non-nullable nested DataSchema with null value — create with defaults
                        $instance->{$name} = $prop['typeName']::fromArray(null);
                    } else {
                        $instance->{$name} = $prop['typeName']::fromArray((array) $value);
                    }

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
