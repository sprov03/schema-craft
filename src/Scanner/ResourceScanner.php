<?php

namespace SchemaCraft\Scanner;

use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use SchemaCraft\Attributes\CollectionOf;
use SchemaCraft\Attributes\Resources\Computed;
use SchemaCraft\Attributes\Resources\ResourceSchema;
use SchemaCraft\SchemaCraftResource;

/**
 * Scans a resource namespace directory and returns ResourceDefinition objects
 * grouped by schema class.
 *
 * SchemaCraftResource subclasses are fully reflected — properties, relationships,
 * and computed methods are extracted directly from the class.
 *
 * Plain JsonResource subclasses are treated as manual resources: schema association
 * is null and file contents are returned for display in the visualizer.
 */
class ResourceScanner
{
    /**
     * Scan the resource directory and return all ResourceDefinitions grouped by schema.
     *
     * @return array<string, ResourceDefinition[]> Keyed by schema FQCN; 'manual' key for resources with no schema.
     */
    public function scanDirectory(string $resourceNamespace, string $resourceDirectory): array
    {
        $grouped = [];

        if (! is_dir($resourceDirectory)) {
            return $grouped;
        }

        foreach (glob($resourceDirectory.'/*Resource.php') as $file) {
            $className = pathinfo($file, PATHINFO_FILENAME);
            $fqcn = $resourceNamespace.'\\'.$className;

            if (! class_exists($fqcn)) {
                continue;
            }

            $definition = $this->scanClass($fqcn, $file);

            $key = $definition->schema ?? 'manual';
            $grouped[$key][] = $definition;
        }

        return $grouped;
    }

    /**
     * Scan a single resource class file and return its ResourceDefinition.
     */
    public function scanClass(string $fqcn, ?string $filePath = null): ResourceDefinition
    {
        $reflection = new ReflectionClass($fqcn);
        $name = $reflection->getShortName();

        // Manual resource — extends JsonResource but not SchemaCraftResource
        if (! $reflection->isSubclassOf(SchemaCraftResource::class)) {
            return new ResourceDefinition(
                class: $fqcn,
                name: $name,
                schema: null,
                isManual: true,
                fileContents: $filePath ? file_get_contents($filePath) : null,
            );
        }

        // Read #[ResourceSchema] for schema association
        $schema = null;
        $schemaAttrs = $reflection->getAttributes(ResourceSchema::class);
        if (! empty($schemaAttrs)) {
            $schema = $schemaAttrs[0]->newInstance()->schema;
        }

        $properties = [];
        $relationships = [];

        // Detection comes from the shared TypedPropertyReflector (parameterized to
        // SchemaCraftResource as the shape base). The columns-vs-relationships SPLIT stays here as
        // a response-side adapter: a nested-shape whose target is a Resource is a relationship;
        // a collection whose ITEM is a Resource is a collection relationship; everything else is
        // a column. The bare-Collection guard and #[Computed] methods are response-only overlays.
        foreach (TypedPropertyReflector::scan($fqcn, SchemaCraftResource::class) as $d) {
            $propName = $d['name'];
            $type = $d['typeName'] ?? 'mixed';
            $nullable = $d['nullable'];

            // Singular relationship: property typed as another SchemaCraftResource.
            if ($d['isNestedShape']) {
                $relationships[] = [
                    'name' => $propName,
                    'type' => 'singular',
                    'resource' => $d['nestedShapeClass'],
                    'isCollection' => false,
                    'nullable' => $nullable,
                ];

                continue;
            }

            // Collection: discriminate by item class (same rule as before).
            //   - item is a SchemaCraftResource → collection relationship (item is another Resource)
            //   - item is a DataSchema          → typed JSON-array column ({Item}Data[])
            if ($d['isCollection'] && $d['collectionItemClass'] !== null) {
                $itemClass = $d['collectionItemClass'];

                if (class_exists($itemClass) && is_subclass_of($itemClass, SchemaCraftResource::class)) {
                    $relationships[] = [
                        'name' => $propName,
                        'type' => 'collection',
                        'resource' => $itemClass,
                        'isCollection' => true,
                        'nullable' => false,
                    ];
                } else {
                    $properties[] = [
                        'name' => $propName,
                        'type' => $type,
                        'nullable' => $nullable,
                        'collectionItemClass' => $itemClass,
                    ];
                }

                continue;
            }

            // Hard-fail on the misconfiguration case: bare Collection-typed property without
            // #[CollectionOf]. PHP reflection can't read collection item types; silently treating
            // it as a scalar would produce broken SDK output. Caught at scan time so the developer
            // sees the cause immediately. Exempt: rich-type collections implementing SchemaCraftColumn
            // (they carry their item type via the contract). This guard has no DataSchema equivalent
            // — DataSchema deliberately allows a bare `array` — so it stays a response-side overlay.
            if ($type !== 'mixed' && class_exists($type)
                && is_a($type, \Illuminate\Support\Collection::class, true)
                && ! is_subclass_of($type, \SchemaCraft\Contracts\SchemaCraftColumn::class)
            ) {
                throw new \RuntimeException(
                    "Resource property [{$fqcn}::\${$propName}] is typed as {$type} but has no #[CollectionOf(X::class)] attribute. "
                    ."PHP's type system can't carry collection item types — declare the item type via the attribute, "
                    ."e.g. #[CollectionOf(CatalogVariantResource::class)] public Collection \${$propName};"
                );
            }

            // Scalar / column property.
            $properties[] = [
                'name' => $propName,
                'type' => $type,
                'nullable' => $nullable,
            ];
        }

        $computed = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $fqcn) {
                continue;
            }

            if (! empty($method->getAttributes(Computed::class))) {
                $returnType = $method->getReturnType();
                $returnTypeName = $returnType instanceof \ReflectionNamedType ? $returnType->getName() : null;
                $computed[] = [
                    'name' => $method->getName(),
                    'returnType' => $returnTypeName,
                ];
            }
        }

        return new ResourceDefinition(
            class: $fqcn,
            name: $name,
            schema: $schema,
            isManual: false,
            properties: $properties,
            relationships: $relationships,
            computed: $computed,
        );
    }
}
