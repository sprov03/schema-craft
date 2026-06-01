<?php

namespace SchemaCraft\Scanner;

use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use SchemaCraft\Attributes\Resources\CollectionOf;
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

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getDeclaringClass()->getName() !== $fqcn) {
                continue;
            }

            $propName = $property->getName();
            $reflectionType = $property->getType();
            $type = $reflectionType instanceof \ReflectionNamedType ? $reflectionType->getName() : 'mixed';
            $nullable = $reflectionType instanceof \ReflectionNamedType ? $reflectionType->allowsNull() : true;

            // Singular relationship: property type is itself a SchemaCraftResource subclass.
            // The property type carries everything (target FQCN, cardinality, nullability) — no
            // attribute is required. Replaces the redundant pre-cutover #[BelongsTo] / #[HasOne]
            // attributes which encoded Laravel relationship mechanics the Resource layer ignored.
            if ($type !== 'mixed' && class_exists($type) && is_subclass_of($type, SchemaCraftResource::class)) {
                $relationships[] = [
                    'name' => $propName,
                    'type' => 'singular',
                    'resource' => $type,
                    'isCollection' => false,
                    'nullable' => $nullable,
                ];

                continue;
            }

            // Collection relationship: #[CollectionOf(X::class)] supplies the item type that PHP's
            // type system can't carry on a collection property. The Resource layer doesn't care
            // which DB-side mechanic produces the collection (hasMany vs belongsToMany vs morphMany
            // vs hasManyThrough — all collapse to "collection of X" at this layer).
            if ($collectionAttrs = $property->getAttributes(CollectionOf::class)) {
                $relationships[] = [
                    'name' => $propName,
                    'type' => 'collection',
                    'resource' => $collectionAttrs[0]->newInstance()->resource,
                    'isCollection' => true,
                    'nullable' => false,
                ];

                continue;
            }

            // Hard-fail on the misconfiguration case: bare Collection-typed property without
            // #[CollectionOf]. PHP reflection can't read collection item types; silently treating
            // it as a scalar would produce broken SDK output. Caught at scan time so the developer
            // sees the cause immediately rather than chasing it through generated code.
            //
            // Exempt: rich-type collections that implement SchemaCraftColumn (e.g. subclasses of
            // AbstractCollectionType — they extend Laravel's Collection but declare their shape
            // via sdkShape(). The type contract carries the item type info instead).
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
