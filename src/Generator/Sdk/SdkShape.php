<?php

namespace SchemaCraft\Generator\Sdk;

/**
 * Describes the SDK-facing shape of a custom column type, so the generator can
 * emit a TYPED nested DTO instead of a bare `array`.
 *
 * A type's wire shape is one of three kinds:
 *   - scalar     — a plain PHP scalar (int/string/bool/float/array). No DTO.
 *   - object     — a single nested object backed by a DataSchema subclass
 *                  (AbstractJsonDtoType) OR by an explicit synthesized field list
 *                  (Bitmask primitive, which has no DataSchema of its own).
 *   - collection — a JSON array whose items are a DataSchema subclass
 *                  (AbstractCollectionType).
 *
 * Why a value object rather than returning a string: the generator needs to know
 * not just the type hint (`{X}Data` / `{X}Data[]`) but ALSO which DataSchema(s) /
 * synthesized field lists to recurse into and emit as inner DTO files. A bare
 * string can't carry that, and reflecting the type again downstream would re-derive
 * what sdkShape() already knows. This keeps the "documented to the bottom"
 * knowledge in one place: the type itself.
 */
final class SdkShape
{
    public const KIND_SCALAR = 'scalar';

    public const KIND_OBJECT = 'object';

    public const KIND_COLLECTION = 'collection';

    /**
     * @param  string  $kind  One of the KIND_* constants
     * @param  string|null  $scalarType  Scalar type hint when kind === scalar
     * @param  class-string<\SchemaCraft\DataSchema>|null  $dataSchemaClass  Backing DataSchema for object/collection kinds
     * @param  string|null  $synthesizedName  DTO name for a synthesized object that has no DataSchema (bitmask)
     * @param  SdkShapeField[]|null  $synthesizedFields  Explicit field list for a synthesized object
     */
    private function __construct(
        public readonly string $kind,
        public readonly ?string $scalarType = null,
        public readonly ?string $dataSchemaClass = null,
        public readonly ?string $synthesizedName = null,
        public readonly ?array $synthesizedFields = null,
    ) {}

    public static function scalar(string $type): self
    {
        return new self(kind: self::KIND_SCALAR, scalarType: $type);
    }

    /**
     * An object whose shape is a reflectable DataSchema subclass.
     *
     * @param  class-string<\SchemaCraft\DataSchema>  $dataSchemaClass
     */
    public static function object(string $dataSchemaClass): self
    {
        return new self(kind: self::KIND_OBJECT, dataSchemaClass: $dataSchemaClass);
    }

    /**
     * A collection (JSON array) whose items are a DataSchema subclass.
     *
     * @param  class-string<\SchemaCraft\DataSchema>  $itemDataSchemaClass
     */
    public static function collectionOf(string $itemDataSchemaClass): self
    {
        return new self(kind: self::KIND_COLLECTION, dataSchemaClass: $itemDataSchemaClass);
    }

    /**
     * An object that has no backing DataSchema — its fields are spelled out
     * explicitly. Used by the bitmask type, whose wire shape ({value, flags})
     * is synthesized from flags() rather than reflected off a class.
     *
     * @param  SdkShapeField[]  $fields
     */
    public static function synthesizedObject(string $name, array $fields): self
    {
        return new self(kind: self::KIND_OBJECT, synthesizedName: $name, synthesizedFields: $fields);
    }

    public function isScalar(): bool
    {
        return $this->kind === self::KIND_SCALAR;
    }

    public function isObject(): bool
    {
        return $this->kind === self::KIND_OBJECT;
    }

    public function isCollection(): bool
    {
        return $this->kind === self::KIND_COLLECTION;
    }

    /**
     * The stable DTO class name for the generated inner DTO.
     *
     * For DataSchema-backed shapes it's `{ClassBasename}Data` (deduped by class
     * across the SDK). For synthesized shapes it's whatever name the factory was
     * given (the type itself owns the naming, e.g. `{TypeBasename}Data`).
     */
    public function dtoName(): ?string
    {
        if ($this->isScalar()) {
            return null;
        }

        if ($this->synthesizedName !== null) {
            return $this->synthesizedName;
        }

        if ($this->dataSchemaClass !== null) {
            return self::dtoNameFor($this->dataSchemaClass);
        }

        return null;
    }

    /**
     * Stable `{ClassBasename}Data` derivation, shared so naming/dedup is consistent
     * everywhere a DataSchema class is turned into a DTO.
     */
    public static function dtoNameFor(string $class): string
    {
        $basename = ($pos = strrpos($class, '\\')) !== false
            ? substr($class, $pos + 1)
            : $class;

        return $basename.'Data';
    }

    /**
     * Resolve a column type to its SDK shape by direct introspection — the only entry point
     * the framework's SDK pipeline uses. Replaces the older `$type::sdkShape()` contract that
     * required every rich-type implementer to learn the SdkShape value-object API.
     *
     * Three recognized primitives — anything else returns null and the framework treats it as
     * a scalar (via sdkType()) or throws via the "unsupported resource type" path:
     *   - Bitmask subclass     → synthesized {value: int, flags: {<FLAG>: bool, ...}}
     *   - AbstractJsonDtoType  → object backed by dtoClass()
     *   - AbstractCollectionType → collection of itemClass()
     *
     * If a fourth primitive is ever added, it gets a branch here — not a contract method on
     * every implementer.
     */
    public static function forType(string $type): ?self
    {
        if (! class_exists($type)) {
            return null;
        }

        // Bitmask primitive — wire shape is intrinsic, framework constructs it from getBitFlags().
        if (is_subclass_of($type, \SchemaCraft\Primitives\BitmaskColumn::class)) {
            $basename = ($pos = strrpos($type, '\\')) !== false
                ? substr($type, $pos + 1)
                : $type;

            $flagFields = [];
            foreach (array_keys($type::getBitFlagsPublic()) as $flag) {
                $flagFields[] = SdkShapeField::scalar($flag, 'bool');
            }

            $flagsShape = self::synthesizedObject($basename.'FlagsData', $flagFields);

            return self::synthesizedObject($basename.'Data', [
                SdkShapeField::scalar('value', 'int'),
                SdkShapeField::nested('flags', $flagsShape),
            ]);
        }

        // DataSchema primitive — the class itself IS the object shape. Used when a property
        // is typed directly as a DataSchema subclass (no wrapper).
        if (is_subclass_of($type, \SchemaCraft\DataSchema::class)) {
            return self::object($type);
        }

        // Collection primitive — the subclass declares its item DataSchema via class-level
        // #[CollectionOf], and the framework introspects the attribute via the primitive's
        // own static itemClass() method. SDK emits {ItemBasename}Data[].
        if (is_subclass_of($type, \SchemaCraft\Primitives\CollectionColumn::class)) {
            return self::collectionOf($type::itemClass());
        }

        return null;
    }
}
