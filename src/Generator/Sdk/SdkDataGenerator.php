<?php

namespace SchemaCraft\Generator\Sdk;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use ReflectionEnum;
use RuntimeException;
use SchemaCraft\Contracts\GeneratesSdkType;
use SchemaCraft\Contracts\SchemaCraftColumn;
use SchemaCraft\DataSchema;
use SchemaCraft\Exceptions\SdkGenerationException;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Scanner\RelationshipDefinition;
use SchemaCraft\Scanner\TableDefinition;

/**
 * Generates a Data Transfer Object (DTO) class from a TableDefinition.
 *
 * The DTO represents the JSON response shape from the API resource,
 * with typed properties and a static fromArray() factory.
 * Generated code is PHP 7.4 compatible.
 */
class SdkDataGenerator
{
    private const TIMESTAMP_COLUMNS = ['created_at', 'updated_at'];

    private const SOFT_DELETE_COLUMNS = ['deleted_at'];

    /**
     * Generate the DTO class PHP code.
     */
    public function generate(
        TableDefinition $table,
        string $dataNamespace,
        string $modelName,
    ): string {
        $dataClassName = $modelName.'Data';
        $properties = $this->buildProperties($table, $dataNamespace, $dataClassName);
        $constructorAssignments = $this->buildConstructorAssignments($table, $dataNamespace);
        $constructorParams = $this->buildConstructorParams($table, $dataNamespace, $dataClassName);
        $fromArrayAssignments = $this->buildFromArrayAssignments($table, $dataNamespace);

        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = "namespace {$dataNamespace};";
        $lines[] = '';
        $lines[] = "class {$dataClassName}";
        $lines[] = '{';

        // Property declarations
        foreach ($properties as $prop) {
            $lines[] = "    {$prop}";
            $lines[] = '';
        }

        // Constructor
        $lines[] = '    /**';
        foreach ($constructorParams as $param) {
            $lines[] = "     * @param {$param}";
        }
        $lines[] = '     */';
        $lines[] = '    public function __construct(';

        $paramNames = $this->buildConstructorParamNames($table, $dataNamespace);
        foreach ($paramNames as $i => $paramLine) {
            $comma = $i < count($paramNames) - 1 ? ',' : '';
            $lines[] = "        {$paramLine}{$comma}";
        }

        $lines[] = '    ) {';
        foreach ($constructorAssignments as $assignment) {
            $lines[] = "        {$assignment}";
        }
        $lines[] = '    }';

        // fromArray factory
        $lines[] = '';
        $lines[] = '    /**';
        $lines[] = '     * @param array $data';
        $lines[] = '     * @return self';
        $lines[] = '     */';
        $lines[] = '    public static function fromArray(array $data)';
        $lines[] = '    {';
        $lines[] = '        return new self(';

        foreach ($fromArrayAssignments as $i => $assignment) {
            $comma = $i < count($fromArrayAssignments) - 1 ? ',' : '';
            $lines[] = "            {$assignment}{$comma}";
        }

        $lines[] = '        );';
        $lines[] = '    }';
        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Generate a DTO class from the output of buildResponseFieldsFromResource().
     *
     * This is the canonical entry point when a resource class is known. Both the API docs
     * and SDK generator call buildResponseFieldsFromResource() once and share the result,
     * so the DTO always matches exactly what the API docs display.
     *
     * @param  array{columns: array<int, array{name: string, type: string, nullable: bool, computed?: bool, innerDtoName?: string}>, relationships: array<int, array{name: string, type: string, relatedResource: string, isCollection: bool}>}  $fields
     */
    public function generateFromFields(
        array $fields,
        string $dataNamespace,
        string $dataClassName,
    ): string {
        $columns = $fields['columns'] ?? [];
        $relationships = $fields['relationships'] ?? [];

        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = "namespace {$dataNamespace};";
        $lines[] = '';
        $lines[] = "class {$dataClassName}";
        $lines[] = '{';

        // Property declarations
        // Why no casing translation: SDK property names match the JSON wire keys
        // verbatim so consumers don't have to translate between conventions and a
        // future toArray() round-trips symmetrically.
        foreach ($columns as $col) {
            $phpType = $this->phpTypeForColumn($col, $dataClassName);
            // A collection field is never null (always a DataCollection, empty at worst) — its
            // documented nullability is deliberately ignored.
            if (($col['nullable'] ?? false) && empty($col['isCollection'])) {
                $lines[] = "    /** @var {$phpType}|null */";
            } else {
                $lines[] = "    /** @var {$phpType} */";
            }
            $lines[] = '    public $'.$col['name'].';';
            $lines[] = '';
        }

        foreach ($relationships as $rel) {
            $relDataClass = SdkResourceNaming::dtoNameFromResourceFqcn($rel['relatedResource']);
            if ($rel['isCollection']) {
                // Non-nullable: to-many relations are always present as a collection.
                $lines[] = '    /** @var '.$this->collectionDocType($relDataClass).' */';
            } else {
                $lines[] = "    /** @var {$relDataClass}|null */";
            }
            $lines[] = '    public $'.$rel['name'].';';
            $lines[] = '';
        }

        // Constructor PHPDoc + signature
        $lines[] = '    /**';
        foreach ($columns as $col) {
            $phpType = $this->phpTypeForColumn($col, $dataClassName);
            $nullSuffix = (($col['nullable'] ?? false) && empty($col['isCollection'])) ? '|null' : '';
            $lines[] = "     * @param {$phpType}{$nullSuffix} \${$col['name']}";
        }
        foreach ($relationships as $rel) {
            $relDataClass = SdkResourceNaming::dtoNameFromResourceFqcn($rel['relatedResource']);
            $colType = $rel['isCollection'] ? $this->collectionDocType($relDataClass) : $relDataClass;
            $nullSuffix = $rel['isCollection'] ? '' : '|null';
            $lines[] = "     * @param {$colType}{$nullSuffix} \${$rel['name']}";
        }
        $lines[] = '     */';
        $lines[] = '    public function __construct(';

        $allParams = [];
        foreach ($columns as $col) {
            $allParams[] = ($col['nullable'] ?? false) ? "\${$col['name']} = null" : "\${$col['name']}";
        }
        foreach ($relationships as $rel) {
            $allParams[] = "\${$rel['name']} = null";
        }

        foreach ($allParams as $i => $param) {
            $comma = $i < count($allParams) - 1 ? ',' : '';
            $lines[] = "        {$param}{$comma}";
        }

        $lines[] = '    ) {';
        foreach ($columns as $col) {
            // Collection fields coalesce a null/omitted arg to an empty DataCollection so the
            // property is always a collection, even on direct construction.
            $suffix = ! empty($col['isCollection']) ? ' ?? collect()' : '';
            $lines[] = "        \$this->{$col['name']} = \${$col['name']}{$suffix};";
        }
        foreach ($relationships as $rel) {
            $suffix = $rel['isCollection'] ? ' ?? collect()' : '';
            $lines[] = "        \$this->{$rel['name']} = \${$rel['name']}{$suffix};";
        }
        $lines[] = '    }';

        // fromArray factory
        $lines[] = '';
        $lines[] = '    /**';
        $lines[] = '     * @param array $data';
        $lines[] = '     * @return self';
        $lines[] = '     */';
        $lines[] = '    public static function fromArray(array $data)';
        $lines[] = '    {';
        $lines[] = '        return new self(';

        $assignments = [];
        foreach ($columns as $col) {
            $key = $col['name'];

            // Columns whose projection carries innerDtoName must wrap the wire data in the
            // inner DTO's fromArray — otherwise the runtime value is a bare array and the
            // typed property's @var annotation lies. Same treatment as relationships, just
            // sourced from the column projection (set by EndpointEnricher for JsonColumn /
            // CollectionColumn / BitmaskColumn fields).
            if (! empty($col['innerDtoName'])) {
                $dto = $col['innerDtoName'];
                if (! empty($col['isCollection'])) {
                    $assignments[] = $this->collectionFromArray($key, $dto);
                } else {
                    $assignments[] = "isset(\$data['{$key}']) ? {$dto}::fromArray(\$data['{$key}']) : null";
                }

                continue;
            }

            $assignments[] = "isset(\$data['{$key}']) ? \$data['{$key}'] : null";
        }
        foreach ($relationships as $rel) {
            $relDataClass = SdkResourceNaming::dtoNameFromResourceFqcn($rel['relatedResource']);
            if ($rel['isCollection']) {
                $assignments[] = $this->collectionFromArray($rel['name'], $relDataClass);
            } else {
                $assignments[] = "isset(\$data['{$rel['name']}']) ? {$relDataClass}::fromArray(\$data['{$rel['name']}']) : null";
            }
        }

        foreach ($assignments as $i => $assignment) {
            $comma = $i < count($assignments) - 1 ? ',' : '';
            $lines[] = "            {$assignment}{$comma}";
        }

        $lines[] = '        );';
        $lines[] = '    }';
        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Generate an inner DTO class from an SdkShape's resolved field set.
     *
     * This is the DataSchema/bitmask counterpart to generate()/generateFromFields():
     * the rich column types (collection/json-dto/bitmask) describe a nested object
     * shape that has no TableDefinition, so we emit it from the field list the shape
     * (or the reflected DataSchema) produced. Unlike generateFromFields(), the field
     * `type` here is ALREADY the final PHPDoc type ({X}Data / {X}Data[] / scalar) —
     * collectInnerDtos() resolved it — so we emit it verbatim rather than re-running
     * resourcePhpType() (which would choke on a DTO name that isn't a real class).
     *
     * @param  array{name: string, fields: array<int, array{name: string, type: string, nullable: bool}>}  $dto
     */
    public function generateFromInnerDto(array $dto, string $dataNamespace): string
    {
        $fields = $dto['fields'];
        $className = $dto['name'];

        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = "namespace {$dataNamespace};";
        $lines[] = '';
        $lines[] = "class {$className}";
        $lines[] = '{';

        // Property declarations — types are already resolved; names served verbatim. Collection
        // field types ("{X}Data[]") are shown as DataCollection<{X}Data> in the docblock; the raw
        // "[]" marker is kept internally for the fromArray/toArray detection below.
        foreach ($fields as $f) {
            $type = $this->fieldDocType($f['type']);
            // Collection fields are never null (always a DataCollection) — ignore their nullability.
            $nullable = $f['nullable'] && ! $this->isCollectionType($f['type']);
            $lines[] = $nullable ? "    /** @var {$type}|null */" : "    /** @var {$type} */";
            $lines[] = '    public $'.$f['name'].';';
            $lines[] = '';
        }

        // Constructor PHPDoc + signature
        $lines[] = '    /**';
        foreach ($fields as $f) {
            $nullSuffix = ($f['nullable'] && ! $this->isCollectionType($f['type'])) ? '|null' : '';
            $lines[] = '     * @param '.$this->fieldDocType($f['type']).$nullSuffix." \${$f['name']}";
        }
        $lines[] = '     */';
        $lines[] = '    public function __construct(';

        $params = array_map(
            fn ($f) => $f['nullable'] ? "\${$f['name']} = null" : "\${$f['name']}",
            $fields,
        );
        foreach ($params as $i => $param) {
            $comma = $i < count($params) - 1 ? ',' : '';
            $lines[] = "        {$param}{$comma}";
        }

        $lines[] = '    ) {';
        foreach ($fields as $f) {
            $suffix = $this->isCollectionType($f['type']) ? ' ?? collect()' : '';
            $lines[] = "        \$this->{$f['name']} = \${$f['name']}{$suffix};";
        }
        $lines[] = '    }';

        // fromArray factory. Inner DTOs need their nested-DTO fields wrapped in
        // {InnerDto}::fromArray() — same correctness reason as the column-projection
        // path: without the wrapping, a field annotated @var TestBitmaskFlagsData would
        // arrive as a bare array at runtime. Detection is type-string-based here because
        // the inner-DTO field shape carries the final PHPDoc type already ('XData' for
        // a nested DTO, 'XData[]' for a collection, 'int'/'string'/etc. for a scalar).
        $lines[] = '';
        $lines[] = '    /**';
        $lines[] = '     * @param array $data';
        $lines[] = '     * @return self';
        $lines[] = '     */';
        $lines[] = '    public static function fromArray(array $data)';
        $lines[] = '    {';
        $lines[] = '        return new self(';

        $scalarTypes = ['int', 'string', 'bool', 'float', 'array', 'mixed'];

        foreach ($fields as $i => $f) {
            $comma = $i < count($fields) - 1 ? ',' : '';
            $type = $f['type'];
            $name = $f['name'];

            // Collection of inner DTOs: "{X}Data[]" — hydrate each item and wrap in a DataCollection.
            if (str_ends_with($type, '[]') && str_ends_with(substr($type, 0, -2), 'Data')) {
                $itemDto = substr($type, 0, -2);
                $lines[] = '            '.$this->collectionFromArray($name, $itemDto).$comma;

                continue;
            }

            // Singular inner DTO: "{X}Data" (and not a scalar PHP type that happens to be one word)
            if (! in_array($type, $scalarTypes, true) && str_ends_with($type, 'Data')) {
                $lines[] = "            isset(\$data['{$name}']) ? {$type}::fromArray(\$data['{$name}']) : null{$comma}";

                continue;
            }

            // Scalar / opaque — raw passthrough.
            $lines[] = "            isset(\$data['{$name}']) ? \$data['{$name}'] : null{$comma}";
        }

        $lines[] = '        );';
        $lines[] = '    }';

        // toArray() — the wire representation, symmetric to fromArray(). A request DTO is posted
        // as $request->toArray(), so nested DTO fields must serialize back to plain arrays (mirror
        // of the fromArray() type handling above). Harmless for response DTOs, which simply gain a
        // round-trip method.
        $lines[] = '';
        $lines[] = '    /**';
        $lines[] = '     * @return array';
        $lines[] = '     */';
        $lines[] = '    public function toArray()';
        $lines[] = '    {';
        $lines[] = '        return [';

        foreach ($fields as $f) {
            $type = $f['type'];
            $name = $f['name'];

            // Collection field: serialise each item to its wire array. Collection::toArray() would
            // NOT recurse into the DTOs (they aren't Arrayable), so map explicitly. collect(...)
            // tolerates a raw array too, in case a consumer built the DTO with a plain array.
            if (str_ends_with($type, '[]') && str_ends_with(substr($type, 0, -2), 'Data')) {
                $lines[] = "            '{$name}' => \$this->{$name} === null ? null : collect(\$this->{$name})->map(function (\$item) { return \$item->toArray(); })->all(),";

                continue;
            }

            if (! in_array($type, $scalarTypes, true) && str_ends_with($type, 'Data')) {
                $lines[] = "            '{$name}' => \$this->{$name} === null ? null : \$this->{$name}->toArray(),";

                continue;
            }

            $lines[] = "            '{$name}' => \$this->{$name},";
        }

        $lines[] = '        ];';
        $lines[] = '    }';

        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Flatten an SdkShape into the set of inner DTOs that must be emitted for it,
     * deduped by DTO name. Recurses into nested DataSchemas, nested casts
     * (SchemaCraftColumn properties), and synthesized sub-objects (bitmask flags),
     * so the type is documented all the way to the bottom.
     *
     * @param  array<string, array{name: string, fields: array}>  $collected  Accumulator, keyed by DTO name
     * @return array<string, array{name: string, fields: array}>
     */
    public function collectInnerDtos(SdkShape $shape, array $collected = []): array
    {
        if ($shape->isScalar()) {
            return $collected;
        }

        $dtoName = $shape->dtoName();

        // Already emitted under this name — stop (also breaks DataSchema cycles).
        if ($dtoName === null || isset($collected[$dtoName])) {
            return $collected;
        }

        // Reserve the slot first so a self-referential DataSchema doesn't recurse forever.
        $collected[$dtoName] = ['name' => $dtoName, 'fields' => []];

        if ($shape->synthesizedFields !== null) {
            // Synthesized object (bitmask) — fields are spelled out explicitly.
            [$fields, $collected] = $this->fieldsFromSynthesized($shape->synthesizedFields, $collected);
        } else {
            // DataSchema-backed object/collection — reflect the class's fields.
            [$fields, $collected] = $this->fieldsFromDataSchema($shape->dataSchemaClass, $collected);
        }

        $collected[$dtoName]['fields'] = $fields;

        return $collected;
    }

    /**
     * Build the field list for a synthesized object and recurse into nested shapes.
     *
     * @param  \SchemaCraft\Generator\Sdk\SdkShapeField[]  $synthesizedFields
     * @param  array<string, array>  $collected
     * @return array{0: array<int, array{name: string, type: string, nullable: bool}>, 1: array<string, array>}
     */
    private function fieldsFromSynthesized(array $synthesizedFields, array $collected): array
    {
        $fields = [];

        foreach ($synthesizedFields as $f) {
            if ($f->shape !== null) {
                // Nested synthesized/DataSchema sub-object (e.g. bitmask flags) — recurse.
                $collected = $this->collectInnerDtos($f->shape, $collected);
                $fields[] = [
                    'name' => $f->name,
                    'type' => $this->shapeToPhpType($f->shape),
                    'nullable' => $f->nullable,
                ];

                continue;
            }

            $fields[] = ['name' => $f->name, 'type' => $f->scalarType ?? 'mixed', 'nullable' => $f->nullable];
        }

        return [$fields, $collected];
    }

    /**
     * Reflect a DataSchema subclass into a DTO field list, recursing into nested
     * DataSchemas and nested SchemaCraftColumn casts.
     *
     * @param  class-string<DataSchema>  $dataSchemaClass
     * @param  array<string, array>  $collected
     * @return array{0: array<int, array{name: string, type: string, nullable: bool}>, 1: array<string, array>}
     */
    private function fieldsFromDataSchema(string $dataSchemaClass, array $collected): array
    {
        $fields = [];

        foreach ($dataSchemaClass::fieldDescriptors() as $prop) {
            $name = $prop['name'];
            $nullable = $prop['nullable'];

            // Nested DataSchema -> recurse into its own DTO, reference it by type.
            if ($prop['isDataSchema']) {
                $nestedShape = SdkShape::object($prop['typeName']);
                $collected = $this->collectInnerDtos($nestedShape, $collected);
                $fields[] = ['name' => $name, 'type' => $nestedShape->dtoName(), 'nullable' => $nullable];

                continue;
            }

            // Property whose cast is one of the three recognized primitives -> framework
            // introspects its shape (recurse). SchemaCraftColumn types outside the primitives
            // get sdkType()'d as a flat scalar — same code path as builtins below.
            if ($prop['typeName'] !== null && ! $prop['isBuiltin']) {
                $castShape = SdkShape::forType($prop['typeName']);
                if ($castShape !== null) {
                    $collected = $this->collectInnerDtos($castShape, $collected);
                    $fields[] = ['name' => $name, 'type' => $this->shapeToPhpType($castShape), 'nullable' => $nullable];

                    continue;
                }
            }

            // Backed enum -> its backing scalar type.
            if ($prop['isBackedEnum']) {
                $fields[] = [
                    'name' => $name,
                    'type' => (new ReflectionEnum($prop['typeName']))->getBackingType()->getName(),
                    'nullable' => $nullable,
                ];

                continue;
            }

            // Datetime -> string (ISO8601 on the wire).
            if ($prop['isDatetime']) {
                $fields[] = ['name' => $name, 'type' => 'string', 'nullable' => $nullable];

                continue;
            }

            // Builtin scalar -> the existing scalar mapping. Guarded the same as schema/resource
            // fields: a DataSchema with a bare `array`/untypeable field would otherwise smuggle an
            // untyped field into an inner DTO, bypassing the column/resource-level guard.
            $fields[] = [
                'name' => $name,
                'type' => $this->guardTyped($this->scalarFromTypeName($prop['typeName']), SdkShape::dtoNameFor($dataSchemaClass), $name),
                'nullable' => $nullable,
            ];
        }

        return [$fields, $collected];
    }

    /**
     * Map a builtin PHP type name from DataSchema reflection to a PHPDoc scalar.
     */
    private function scalarFromTypeName(?string $typeName): string
    {
        return match ($typeName) {
            'int' => 'int',
            'bool' => 'bool',
            'float' => 'float',
            'array' => 'array',
            'string' => 'string',
            default => 'mixed',
        };
    }

    /**
     * Map a type string from ResourceScanner (PHP type or column type) to a PHPDoc-safe type.
     *
     * Resolution order mirrors the table-driven phpType(): scalars first, then well-known
     * stdlib classes that SchemaCraftType's docblock excuses from the contract
     * (DateTimeInterface family), then BackedEnum backing types via reflection, then
     * anything implementing GeneratesSdkType. Any other class throws — silent fallbacks
     * here would let custom value objects ship as the wrong type in the SDK without
     * anyone noticing. The escape hatch is the contract, not a one-off match arm.
     */
    /**
     * Resolve a column's PHP type for the DTO. Two paths:
     *   - The column already carries SDK-aware shape info from the projection (innerDtoName +
     *     isCollection). Trust it — `{X}Data[]` for collections, `{X}Data` for singles.
     *   - Otherwise fall back to type-string introspection via resourcePhpType().
     *
     * Used by both the property block and the constructor signature so the two stay in sync.
     */
    private function phpTypeForColumn(array $col, string $context): string
    {
        if (! empty($col['innerDtoName'])) {
            return ! empty($col['isCollection'])
                ? $this->collectionDocType($col['innerDtoName'])
                : $col['innerDtoName'];
        }

        return $this->resourcePhpType($col['type'], $context, $col['name']);
    }

    /**
     * Convert an internal collection MARKER type ("{X}Data[]") to its docblock form
     * (DataCollection<{X}Data>). Non-collection / scalar types pass through unchanged. Used where
     * the internal "{X}Data[]" string must stay intact for `[]` detection but the emitted docblock
     * should show the real collection type.
     */
    private function fieldDocType(string $type): string
    {
        if ($this->isCollectionType($type)) {
            return $this->collectionDocType(substr($type, 0, -2));
        }

        return $type;
    }

    /** True when an internal field type string is the collection MARKER "{X}Data[]". */
    private function isCollectionType(string $type): bool
    {
        return str_ends_with($type, '[]') && str_ends_with(substr($type, 0, -2), 'Data');
    }

    private function resourcePhpType(string $type, string $context, string $field): string
    {
        $scalar = match ($type) {
            'int', 'integer' => 'int',
            'bool', 'boolean' => 'bool',
            'float', 'double', 'decimal', 'numeric' => 'float',
            'array', 'json' => 'array',
            'string', 'text', 'date', 'datetime', 'timestamp' => 'string',
            default => null,
        };

        if ($scalar !== null) {
            return $this->guardTyped($scalar, $context, $field);
        }

        if (class_exists($type) || interface_exists($type)) {
            if ($type === \DateTimeInterface::class || is_subclass_of($type, \DateTimeInterface::class)) {
                return 'string';
            }

            if (is_subclass_of($type, \BackedEnum::class)) {
                return (new ReflectionEnum($type))->getBackingType()->getName();
            }

            // DataSchema-typed property: the DataSchema IS the object shape — wrapper class
            // not needed. SdkShape::forType handles this branch and returns SdkShape::object($type).
            if (is_subclass_of($type, \SchemaCraft\DataSchema::class)) {
                $shape = SdkShape::forType($type);

                return $this->guardTyped($this->shapeToPhpType($shape), $context, $field);
            }

            if (is_subclass_of($type, GeneratesSdkType::class)) {
                // Bitmask primitive — framework introspection emits the synthesized wire shape.
                // Other SchemaCraftColumn implementations (like the DataSchema-as-column-type pattern /
                // JsonCollectionCast) fall back to the flat sdkType(); they're not used as
                // property types directly — only as castType on column definitions.
                $shape = SdkShape::forType($type);
                if ($shape !== null) {
                    return $this->guardTyped($this->shapeToPhpType($shape), $context, $field);
                }

                return $this->guardTyped($type::sdkType(), $context, $field);
            }
        }

        throw SdkGenerationException::unsupportedResourceType($type);
    }

    /**
     * Phase B guard: the SDK must be typed to the bottom, so a field resolving to a bare
     * `array` is a hard error (no opt-out). Centralised so every resolution path fails the
     * same way, naming the offending DTO + field.
     */
    private function guardTyped(string $resolved, string $context, string $field): string
    {
        // Both `array` (shape unknown) and `mixed` (no type at all) fail the "typed to the
        // bottom" contract — no opt-out.
        if ($resolved === 'array' || $resolved === 'mixed') {
            throw SdkGenerationException::untypedField($context, $field, $resolved);
        }

        return $resolved;
    }

    /**
     * Map an SdkShape to the PHPDoc type hint used in a generated DTO property.
     *
     * collection -> {Item}Data[]   object -> {X}Data   scalar -> the scalar string.
     */
    private function shapeToPhpType(SdkShape $shape): string
    {
        if ($shape->isCollection()) {
            return $shape->dtoName().'[]';
        }

        if ($shape->isObject()) {
            return $shape->dtoName();
        }

        return $shape->scalarType ?? 'array';
    }

    /**
     * Build the property declarations with PHPDoc types.
     *
     * @return string[]
     */
    private function buildProperties(TableDefinition $table, string $dataNamespace, string $dataClassName): array
    {
        $properties = [];
        $hiddenSet = array_flip($table->hidden);
        $managedColumns = $this->getManagedColumns($table);
        $managedSet = array_flip($managedColumns);

        // Regular columns
        foreach ($table->columns as $column) {
            if (isset($hiddenSet[$column->name]) || isset($managedSet[$column->name])) {
                continue;
            }

            $type = $this->phpType($column, $dataClassName);
            $propName = $this->propertyName($column->name);

            // Collection columns are never null (always a DataCollection) — ignore their nullability.
            if ($column->nullable && $column->collectionItemClass === null) {
                $properties[] = "/** @var {$type}|null */\n    public \${$propName};";
            } else {
                $properties[] = "/** @var {$type} */\n    public \${$propName};";
            }
        }

        // Timestamp columns
        if ($table->hasTimestamps) {
            foreach (self::TIMESTAMP_COLUMNS as $col) {
                if (! isset($hiddenSet[$col])) {
                    $propName = $this->propertyName($col);
                    $properties[] = "/** @var string|null */\n    public \${$propName};";
                }
            }
        }

        // Soft delete column
        if ($table->hasSoftDeletes) {
            foreach (self::SOFT_DELETE_COLUMNS as $col) {
                if (! isset($hiddenSet[$col])) {
                    $propName = $this->propertyName($col);
                    $properties[] = "/** @var string|null */\n    public \${$propName};";
                }
            }
        }

        // Relationships (non-BelongsTo) — emitted as nullable. Resources now lazy-load on
        // access, so the field is always present in the response, but the related row itself
        // can still be null (e.g. orphaned FK). DTO shape narrowing is a follow-up.
        foreach ($table->relationships as $rel) {
            if ($rel->type === 'belongsTo' || isset($hiddenSet[$rel->name])) {
                continue;
            }

            $properties[] = $this->buildRelationshipPropertyDeclaration($rel);
        }

        return $properties;
    }

    /**
     * Build constructor parameter names (for the function signature).
     *
     * @return string[]
     */
    private function buildConstructorParamNames(TableDefinition $table, string $dataNamespace): array
    {
        $params = [];
        $hiddenSet = array_flip($table->hidden);
        $managedColumns = $this->getManagedColumns($table);
        $managedSet = array_flip($managedColumns);

        foreach ($table->columns as $column) {
            if (isset($hiddenSet[$column->name]) || isset($managedSet[$column->name])) {
                continue;
            }

            $propName = $this->propertyName($column->name);

            if ($column->nullable) {
                $params[] = "\${$propName} = null";
            } else {
                $params[] = "\${$propName}";
            }
        }

        if ($table->hasTimestamps) {
            foreach (self::TIMESTAMP_COLUMNS as $col) {
                if (! isset($hiddenSet[$col])) {
                    $params[] = '$'.$this->propertyName($col).' = null';
                }
            }
        }

        if ($table->hasSoftDeletes) {
            foreach (self::SOFT_DELETE_COLUMNS as $col) {
                if (! isset($hiddenSet[$col])) {
                    $params[] = '$'.$this->propertyName($col).' = null';
                }
            }
        }

        foreach ($table->relationships as $rel) {
            if ($rel->type === 'belongsTo' || isset($hiddenSet[$rel->name])) {
                continue;
            }

            $params[] = '$'.$rel->name.' = null';
        }

        return $params;
    }

    /**
     * Build constructor PHPDoc @param lines.
     *
     * @return string[]
     */
    private function buildConstructorParams(TableDefinition $table, string $dataNamespace, string $dataClassName): array
    {
        $params = [];
        $hiddenSet = array_flip($table->hidden);
        $managedColumns = $this->getManagedColumns($table);
        $managedSet = array_flip($managedColumns);

        foreach ($table->columns as $column) {
            if (isset($hiddenSet[$column->name]) || isset($managedSet[$column->name])) {
                continue;
            }

            $type = $this->phpType($column, $dataClassName);
            $propName = $this->propertyName($column->name);
            $nullSuffix = ($column->nullable && $column->collectionItemClass === null) ? '|null' : '';
            $params[] = "{$type}{$nullSuffix} \${$propName}";
        }

        if ($table->hasTimestamps) {
            foreach (self::TIMESTAMP_COLUMNS as $col) {
                if (! isset($hiddenSet[$col])) {
                    $params[] = 'string|null $'.$this->propertyName($col);
                }
            }
        }

        if ($table->hasSoftDeletes) {
            foreach (self::SOFT_DELETE_COLUMNS as $col) {
                if (! isset($hiddenSet[$col])) {
                    $params[] = 'string|null $'.$this->propertyName($col);
                }
            }
        }

        foreach ($table->relationships as $rel) {
            if ($rel->type === 'belongsTo' || isset($hiddenSet[$rel->name])) {
                continue;
            }

            $relatedDataClass = class_basename($rel->relatedModel).'Data';

            if (SdkRelationshipCardinality::isCollection($rel->type)) {
                $params[] = $this->collectionDocType($relatedDataClass)." \${$rel->name}";
            } elseif (SdkRelationshipCardinality::isSingular($rel->type)) {
                $params[] = "{$relatedDataClass}|null \${$rel->name}";
            } else {
                $params[] = "mixed \${$rel->name}";
            }
        }

        return $params;
    }

    /**
     * Build constructor body assignments ($this->x = $x).
     *
     * @return string[]
     */
    private function buildConstructorAssignments(TableDefinition $table, string $dataNamespace): array
    {
        $assignments = [];
        $hiddenSet = array_flip($table->hidden);
        $managedColumns = $this->getManagedColumns($table);
        $managedSet = array_flip($managedColumns);

        foreach ($table->columns as $column) {
            if (isset($hiddenSet[$column->name]) || isset($managedSet[$column->name])) {
                continue;
            }

            $propName = $this->propertyName($column->name);
            // Collection columns coalesce null → empty DataCollection (never null).
            $suffix = $column->collectionItemClass !== null ? ' ?? collect()' : '';
            $assignments[] = "\$this->{$propName} = \${$propName}{$suffix};";
        }

        if ($table->hasTimestamps) {
            foreach (self::TIMESTAMP_COLUMNS as $col) {
                if (! isset($hiddenSet[$col])) {
                    $propName = $this->propertyName($col);
                    $assignments[] = "\$this->{$propName} = \${$propName};";
                }
            }
        }

        if ($table->hasSoftDeletes) {
            foreach (self::SOFT_DELETE_COLUMNS as $col) {
                if (! isset($hiddenSet[$col])) {
                    $propName = $this->propertyName($col);
                    $assignments[] = "\$this->{$propName} = \${$propName};";
                }
            }
        }

        foreach ($table->relationships as $rel) {
            if ($rel->type === 'belongsTo' || isset($hiddenSet[$rel->name])) {
                continue;
            }

            $suffix = SdkRelationshipCardinality::isCollection($rel->type) ? ' ?? collect()' : '';
            $assignments[] = "\$this->{$rel->name} = \${$rel->name}{$suffix};";
        }

        return $assignments;
    }

    /**
     * Build the fromArray() assignment expressions (positional, not named).
     *
     * @return string[]
     */
    private function buildFromArrayAssignments(TableDefinition $table, string $dataNamespace): array
    {
        $assignments = [];
        $hiddenSet = array_flip($table->hidden);
        $managedColumns = $this->getManagedColumns($table);
        $managedSet = array_flip($managedColumns);

        // Regular columns. Shape-carrying columns (DataSchema-typed or CollectionColumn
        // with an item DataSchema) need their wire data wrapped in the inner DTO's
        // fromArray — same treatment the field-based path uses for innerDtoName columns,
        // sourced from the ColumnDefinition's dataSchemaClass / collectionItemClass fields.
        foreach ($table->columns as $column) {
            if (isset($hiddenSet[$column->name]) || isset($managedSet[$column->name])) {
                continue;
            }

            if ($column->collectionItemClass !== null) {
                $itemDtoName = class_basename($column->collectionItemClass).'Data';
                $assignments[] = $this->collectionFromArray($column->name, $itemDtoName);

                continue;
            }
            if ($column->dataSchemaClass !== null) {
                $dtoName = class_basename($column->dataSchemaClass).'Data';
                $assignments[] = "isset(\$data['{$column->name}']) ? {$dtoName}::fromArray(\$data['{$column->name}']) : null";

                continue;
            }

            $default = $column->nullable ? ' ?? null' : '';
            $assignments[] = "isset(\$data['{$column->name}']) ? \$data['{$column->name}']{$default} : null";
        }

        // Timestamp columns
        if ($table->hasTimestamps) {
            foreach (self::TIMESTAMP_COLUMNS as $col) {
                if (! isset($hiddenSet[$col])) {
                    $assignments[] = "isset(\$data['{$col}']) ? \$data['{$col}'] : null";
                }
            }
        }

        // Soft delete column
        if ($table->hasSoftDeletes) {
            foreach (self::SOFT_DELETE_COLUMNS as $col) {
                if (! isset($hiddenSet[$col])) {
                    $assignments[] = "isset(\$data['{$col}']) ? \$data['{$col}'] : null";
                }
            }
        }

        // Relationships
        foreach ($table->relationships as $rel) {
            if ($rel->type === 'belongsTo' || isset($hiddenSet[$rel->name])) {
                continue;
            }

            $assignments[] = $this->buildRelationshipFromArray($rel);
        }

        return $assignments;
    }

    /**
     * Docblock type for a to-many field: the typed wrapper DataCollection<XData>. The internal
     * "{X}Data[]" form remains the collection MARKER used for detection elsewhere — this is only the
     * shape we WRITE into @var/@param so consumers see a real collection type.
     */
    private function collectionDocType(string $elementDto): string
    {
        // Real Illuminate\Support\Collection (declared as a package dependency, resolved to the
        // consuming Laravel app's own version) — full Collection contract, referenced by FQCN so no
        // import management is needed in the generated DTOs.
        return "\\Illuminate\\Support\\Collection<int, {$elementDto}>";
    }

    /**
     * fromArray hydration for a to-many field: map each wire item through {Element}::fromArray, then
     * wrap in a DataCollection so the property is a real typed collection object, not a bare array.
     *
     * A to-many field is NEVER null: the API Resource always emits the relation (empty at worst,
     * never whenLoaded-gated — see SchemaCraftResource::toArray), so an absent/empty payload hydrates
     * to an EMPTY Collection, not null. Nullability is intentionally ignored for this field type.
     */
    private function collectionFromArray(string $dataKey, string $elementDto): string
    {
        return "isset(\$data['{$dataKey}']) ? collect(\$data['{$dataKey}'])->map(function (array \$item) { return {$elementDto}::fromArray(\$item); }) : collect()";
    }

    private function buildRelationshipPropertyDeclaration(RelationshipDefinition $rel): string
    {
        if (SdkRelationshipCardinality::isCollection($rel->type)) {
            $relatedDataClass = class_basename($rel->relatedModel).'Data';

            // Non-nullable — a to-many relation is always a collection (empty at worst).
            return '/** @var '.$this->collectionDocType($relatedDataClass)." */\n    public \${$rel->name};";
        }

        if (SdkRelationshipCardinality::isSingular($rel->type)) {
            $relatedDataClass = class_basename($rel->relatedModel).'Data';

            return "/** @var {$relatedDataClass}|null */\n    public \${$rel->name};";
        }

        return "/** @var mixed */\n    public \${$rel->name};";
    }

    private function buildRelationshipFromArray(RelationshipDefinition $rel): string
    {
        $relatedDataClass = class_basename($rel->relatedModel).'Data';

        if (SdkRelationshipCardinality::isCollection($rel->type)) {
            return $this->collectionFromArray($rel->name, $relatedDataClass);
        }

        if (SdkRelationshipCardinality::isSingular($rel->type)) {
            return "isset(\$data['{$rel->name}']) ? {$relatedDataClass}::fromArray(\$data['{$rel->name}']) : null";
        }

        return "isset(\$data['{$rel->name}']) ? \$data['{$rel->name}'] : null";
    }

    /**
     * @return string[]
     */
    private function getManagedColumns(TableDefinition $table): array
    {
        $managed = [];

        if ($table->hasTimestamps) {
            $managed = array_merge($managed, self::TIMESTAMP_COLUMNS);
        }

        if ($table->hasSoftDeletes) {
            $managed = array_merge($managed, self::SOFT_DELETE_COLUMNS);
        }

        return $managed;
    }

    /**
     * Map a ColumnDefinition to its PHP type hint.
     *
     * If the column has a castType that is a SchemaCraftColumn, its sdkType() is used.
     * BackedEnum backing types are resolved via reflection.
     * Any other existing class that does NOT implement SchemaCraftColumn throws.
     */
    private function phpType(ColumnDefinition $column, string $context): string
    {
        // Shape-carrying columns: the SchemaScanner already stashed the DataSchema FQCN on
        // the column, so the SDK pipeline reads it directly rather than re-resolving from the
        // cast type. {X}Data for a single object, {X}Data[] for a collection.
        if ($column->dataSchemaClass !== null) {
            return $this->guardTyped(
                $this->shapeToPhpType(SdkShape::object($column->dataSchemaClass)),
                $context,
                $column->name
            );
        }
        if ($column->collectionItemClass !== null) {
            // Emit DataCollection<{X}Data> in the docblock (guardTyped still validates the raw
            // "{X}Data[]" marker first). Detection in buildFromArrayAssignments uses collectionItemClass.
            return $this->fieldDocType($this->guardTyped(
                $this->shapeToPhpType(SdkShape::collectionOf($column->collectionItemClass)),
                $context,
                $column->name
            ));
        }

        if ($column->castType !== null && class_exists($column->castType)) {
            if (is_subclass_of($column->castType, \BackedEnum::class)) {
                return (new ReflectionEnum($column->castType))->getBackingType()->getName();
            }

            // Bitmask primitive — framework introspection emits the synthesized wire shape.
            // Other SchemaCraftColumn implementations (rare) fall back to the flat sdkType().
            if (is_subclass_of($column->castType, SchemaCraftColumn::class)) {
                $shape = SdkShape::forType($column->castType);
                if ($shape !== null) {
                    return $this->guardTyped($this->shapeToPhpType($shape), $context, $column->name);
                }

                return $this->guardTyped($column->castType::sdkType(), $context, $column->name);
            }

            // Custom Eloquent cast without SchemaCraftColumn — throw.
            // Built-ins like DateTime/Carbon don't implement CastsAttributes and fall through.
            if (is_subclass_of($column->castType, CastsAttributes::class)) {
                throw new RuntimeException(
                    "Cast class [{$column->castType}] must implement SchemaCraftColumn. "
                    .'Use a DataSchema-typed property, a Collection + #[CollectionOf] property, '
                    .'a Bitmask subclass, or implement SchemaCraftColumn directly. No fallback is provided.'
                );
            }
        }

        $typeMap = [
            'integer' => 'int',
            'bigInteger' => 'int',
            'smallInteger' => 'int',
            'tinyInteger' => 'int',
            'unsignedBigInteger' => 'int',
            'unsignedInteger' => 'int',
            'unsignedSmallInteger' => 'int',
            'unsignedTinyInteger' => 'int',
            'boolean' => 'bool',
            'decimal' => 'float',
            'float' => 'float',
            'double' => 'float',
            'json' => 'array',
            'timestamp' => 'string',
            'dateTime' => 'string',
            'dateTimeTz' => 'string',
            'date' => 'string',
        ];

        return $this->guardTyped($typeMap[$column->columnType] ?? 'string', $context, $column->name);
    }

    /**
     * SDK property name for a column — the column name verbatim.
     *
     * No casing translation: the DTO property must match the JSON wire key exactly so
     * consumers never translate between conventions and a future toArray() round-trips
     * symmetrically (same rationale as the resource-driven generateFromFields path).
     */
    private function propertyName(string $columnName): string
    {
        return $columnName;
    }
}
