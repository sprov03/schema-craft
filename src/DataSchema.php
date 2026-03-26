<?php

namespace SchemaCraft;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

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
abstract class DataSchema
{
    /**
     * Optional link to the related model's Schema class.
     * When set, enables automatic validation rule derivation and column type resolution.
     *
     * @var class-string<Schema>
     */
    protected static string $schema = '';

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
        $ref = new ReflectionClass(static::class);

        foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) {
                continue;
            }

            $declaringClass = $prop->getDeclaringClass()->getName();
            if ($declaringClass === self::class) {
                continue;
            }

            $type = $prop->getType();
            if (! $type instanceof ReflectionNamedType) {
                continue;
            }

            $fieldName = $prop->getName();
            $fullKey = $prefix !== '' ? "{$prefix}.{$fieldName}" : $fieldName;
            $typeName = $type->getName();
            $nullable = $type->allowsNull();

            $fieldRules = [];
            $fieldRules[] = $nullable ? 'nullable' : 'required';

            // Check if property type is itself a DataSchema (nested object)
            if (! $type->isBuiltin() && is_subclass_of($typeName, self::class, true)) {
                $fieldRules[] = 'array';
                $rules[$fullKey] = $fieldRules;

                // Recurse into the nested DataSchema
                $nestedRules = $typeName::validationRules($fullKey);
                $rules = array_merge($rules, $nestedRules);

                continue;
            }

            // Map PHP types to validation rules
            $typeRules = match ($typeName) {
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
}
