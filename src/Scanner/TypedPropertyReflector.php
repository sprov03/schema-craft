<?php

namespace SchemaCraft\Scanner;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use SchemaCraft\Attributes\CollectionOf;
use SchemaCraft\Primitives\CollectionColumn;

/**
 * The one typed-property parser, shared by the shape layers that used to each own a copy:
 *   - DataSchema (requests + actions) — pass $shapeBaseClass = DataSchema::class
 *   - SchemaCraftResource (responses) — pass $shapeBaseClass = SchemaCraftResource::class
 *
 * Given a class, it classifies each public property as scalar / nested-shape / collection /
 * enum / datetime, returning one flat descriptor per property. "Nested shape" is parameterized
 * by $shapeBaseClass so the same algorithm serves a DataSchema-of-DataSchemas and a
 * Resource-of-Resources without duplication.
 *
 * Collections arrive two ways and BOTH are supported (one was DataSchema-only, the other
 * Resource-only before this unified them):
 *   (a) a CollectionColumn-typed property — item type via the class-level #[CollectionOf]
 *       it carries, read through ::itemClass();
 *   (b) any other property carrying a property-level #[CollectionOf(X)] — item type X.
 *
 * It deliberately does NOT throw on a bare collection with no item info: that guard is a
 * response-side overlay (DataSchema allows a bare `array`), so the caller decides.
 */
class TypedPropertyReflector
{
    /**
     * @param  class-string  $class           the shape class to reflect
     * @param  class-string  $shapeBaseClass  the base that defines "this is a nested shape"
     * @return array<int, array{
     *   name: string, typeName: ?string, isBuiltin: bool, nullable: bool,
     *   hasDefault: bool, default: mixed, isNestedShape: bool, nestedShapeClass: ?string,
     *   isCollection: bool, collectionItemClass: ?string, isBackedEnum: bool, isDatetime: bool,
     * }>
     */
    public static function scan(string $class, string $shapeBaseClass): array
    {
        $ref = new ReflectionClass($class);
        $out = [];

        foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) {
                continue;
            }

            // Inherit-from-parents: include a property only when its declaring class is a PROPER
            // subclass of the shape base. This keeps inherited fields from a user-defined parent
            // shape (e.g. a BaseResponse with id/created_at extended by a concrete Resource) while
            // still excluding the base class's own infra AND framework ancestors — notably
            // JsonResource::$resource, which would otherwise leak into the response shape.
            $declaring = $prop->getDeclaringClass()->getName();
            if (! is_subclass_of($declaring, $shapeBaseClass, true)) {
                continue;
            }

            $type = $prop->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;
            $isBuiltin = $type instanceof ReflectionNamedType && $type->isBuiltin();
            $nullable = $type instanceof ReflectionNamedType ? $type->allowsNull() : true;

            $isObjectType = $typeName !== null && ! $isBuiltin;
            $isNestedShape = $isObjectType && is_subclass_of($typeName, $shapeBaseClass, true);
            $isBackedEnum = $isObjectType && is_subclass_of($typeName, \BackedEnum::class, true);
            $isDatetime = $isObjectType && is_a($typeName, \DateTimeInterface::class, true);

            // Collection detection — both mechanisms (see class docblock).
            $isCollection = false;
            $collectionItemClass = null;

            if ($isObjectType && is_subclass_of($typeName, CollectionColumn::class, true)) {
                $isCollection = true;
                $collectionItemClass = $typeName::itemClass();
            } else {
                $attrs = $prop->getAttributes(CollectionOf::class);
                if (! empty($attrs)) {
                    $isCollection = true;
                    $collectionItemClass = $attrs[0]->newInstance()->resource;
                }
            }

            $out[] = [
                'name' => $prop->getName(),
                'typeName' => $typeName,
                'isBuiltin' => $isBuiltin,
                'nullable' => $nullable,
                'hasDefault' => $prop->hasDefaultValue(),
                'default' => $prop->hasDefaultValue() ? $prop->getDefaultValue() : null,
                'isNestedShape' => $isNestedShape,
                'nestedShapeClass' => $isNestedShape ? $typeName : null,
                'isCollection' => $isCollection,
                'collectionItemClass' => $collectionItemClass,
                'isBackedEnum' => $isBackedEnum,
                'isDatetime' => $isDatetime,
            ];
        }

        return $out;
    }
}
