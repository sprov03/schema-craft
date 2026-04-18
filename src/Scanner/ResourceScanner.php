<?php

namespace SchemaCraft\Scanner;

use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use SchemaCraft\Attributes\Resources\BelongsTo;
use SchemaCraft\Attributes\Resources\Computed;
use SchemaCraft\Attributes\Resources\HasMany;
use SchemaCraft\Attributes\Resources\HasOne;
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
            $type = $property->getType()?->getName() ?? 'mixed';

            if ($attrs = $property->getAttributes(HasMany::class)) {
                $relationships[] = [
                    'name' => $propName,
                    'type' => 'hasMany',
                    'resource' => $attrs[0]->newInstance()->resource,
                ];
            } elseif ($attrs = $property->getAttributes(HasOne::class)) {
                $relationships[] = [
                    'name' => $propName,
                    'type' => 'hasOne',
                    'resource' => $attrs[0]->newInstance()->resource,
                ];
            } elseif ($attrs = $property->getAttributes(BelongsTo::class)) {
                $relationships[] = [
                    'name' => $propName,
                    'type' => 'belongsTo',
                    'resource' => $attrs[0]->newInstance()->resource,
                ];
            } else {
                $properties[] = [
                    'name' => $propName,
                    'type' => $type,
                ];
            }
        }

        $computed = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $fqcn) {
                continue;
            }

            if (! empty($method->getAttributes(Computed::class))) {
                $computed[] = $method->getName();
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
