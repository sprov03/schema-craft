<?php

namespace SchemaCraft\Generators\InputTypes;

use SchemaCraft\Config\ApiConfig;
use SchemaCraft\Config\ConfigResolver;
use SchemaCraft\Config\ConnectionConfig;
use SchemaCraft\Generators\GeneratorSchemaContext;
use SchemaCraft\Generators\InputDefinition;
use SchemaCraft\Scanner\ResourceScanner;

/**
 * Input type for registering an action onto one or more APIs.
 *
 * Lists every configured API; each can be checked to register the action, and each checked API
 * picks the resource its endpoint returns — scoped to that API's own resource set (an API's
 * resources are namespaced per API). The picker also allows creating a resource inline, so a
 * missing resource never blocks registration.
 *
 * SchemaCraft-internal by design: the set of APIs and their resource scoping are package
 * primitives, not project-tunable knobs — unlike filamentPlacements, which surfaces project
 * Filament panels. Resolves to a simple { apiName => resourceFqcn } map, which a generator hands
 * straight to ApiRegistration::writesFor() in its inlineTemplates().
 *
 * Usage in a generator:
 *
 *     'apis' => fn ($data) => Input::apiRegistration('Register On APIs', 'schema'),
 */
class ApiRegistrationInputType implements InputType
{
    public function resolve(mixed $rawValue, InputDefinition $definition, array $resolved): mixed
    {
        if (! is_array($rawValue)) {
            return [];
        }

        // Frontend posts { apiName => resourceFqcn } for checked APIs only. Drop any entry
        // without a chosen resource — a checked API with no resource can't be registered.
        $map = [];
        foreach ($rawValue as $apiName => $resourceFqcn) {
            if (is_string($apiName) && is_string($resourceFqcn) && $resourceFqcn !== '') {
                $map[$apiName] = $resourceFqcn;
            }
        }

        return $map;
    }

    public function toFrontend(InputDefinition $definition, array $resolved = []): array
    {
        $context = $definition->schemaKey !== null ? ($resolved[$definition->schemaKey] ?? null) : null;
        $schemaClass = $context instanceof GeneratorSchemaContext ? $context->schemaClass : null;
        $modelBasename = $context instanceof GeneratorSchemaContext ? class_basename($context->modelClass) : null;

        $apis = [];
        foreach (ConfigResolver::allApiNames() as $apiName) {
            $apiConfig = ConfigResolver::resolve($apiName);

            $apis[] = [
                'name' => $apiName,
                'label' => $apiConfig->name,
                'routeFile' => $apiConfig->routeFile,
                'schema' => $schemaClass,
                'resources' => $this->resourcesForSchema($apiConfig, $schemaClass, $modelBasename),
            ];
        }

        return [
            'renderAs' => 'apiRegistration',
            'apis' => $apis,
        ];
    }

    /**
     * Resources in one API that belong to the selected schema, as [{class, name}]. Mirrors how
     * availableActions() associates resources to a schema: schema-declared resources via
     * #[ResourceSchema], plus manual resources whose name starts with the model basename.
     *
     * @return array<int, array{class: string, name: string}>
     */
    private function resourcesForSchema(ApiConfig $apiConfig, ?string $schemaClass, ?string $modelBasename): array
    {
        $dir = base_path(ConnectionConfig::namespaceToDirectory($apiConfig->resourceNamespace));
        $grouped = (new ResourceScanner)->scanDirectory($apiConfig->resourceNamespace, $dir);

        $defs = $grouped[$schemaClass] ?? [];

        foreach ($grouped['manual'] ?? [] as $def) {
            if ($modelBasename !== null && str_starts_with($def->name, $modelBasename)) {
                $defs[] = $def;
            }
        }

        return array_map(fn ($d) => ['class' => $d->class, 'name' => $d->name], $defs);
    }
}
