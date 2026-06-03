<?php

namespace SchemaCraft\Generator\Sdk;

use Illuminate\Filesystem\Filesystem;
use SchemaCraft\Config\ApiConfig;
use SchemaCraft\Contracts\SchemaCraftColumn;
use SchemaCraft\Exceptions\SdkGenerationException;
use SchemaCraft\Scanner\ResourceScanner;
use SchemaCraft\Scanner\SchemaScanner;
use Illuminate\Support\Str;

/**
 * The single source of truth for turning an API config + a set of schema classes into
 * the SdkSchemaContext map the SdkGenerator consumes.
 *
 * Why this exists: the discover -> scan routes -> enrich -> assign -> filter -> resolve-deps
 * pipeline used to be duplicated in two places (the visualizer's GenerateController::buildSdkFiles
 * and the CLI's GenerateSdkCommand). Two copies drift. Both callers now delegate here so the
 * GUI-generated SDK and the CLI-generated SDK are byte-for-byte identical, and any new pipeline
 * rule (like the undocumented-endpoint filter + warning below) lands in exactly one place.
 *
 * It is intentionally stateless and side-effect free: it collects warnings/errors instead of
 * printing or returning HTTP responses, leaving presentation to each caller.
 */
class SdkContextBuilder
{
    /**
     * Build the SDK schema-context map for the given API and allowed schema classes.
     *
     * @param  class-string[]  $schemaClasses  Already discovered + filtered to the API's configured schemas
     */
    public function build(ApiConfig $apiConfig, array $schemaClasses, bool $failOnMissingRoutes = true): SdkBuildResult
    {
        // 1. Discover registered routes (works for generated controllers, hand-written
        //    controllers, and action-based routes alike).
        $routeScanner = new RuntimeRouteScanner;
        $routeData = $routeScanner->scanAll(
            controllerNamespace: $apiConfig->controllerNamespace,
            schemaNamespace: $apiConfig->schemaNamespace,
            routePrefix: $apiConfig->routePrefix ?: null,
        );

        $schemaEndpoints = $routeData['schemas'] ?? [];
        $allowedSchemas = array_flip($schemaClasses);
        $discoveredSchemaClasses = [];

        $enricher = new EndpointEnricher;

        // 2. Enrich every schema-assigned endpoint — same data the API Docs panel uses.
        foreach ($schemaEndpoints as $schemaClass => &$endpointList) {
            $endpointList = array_map(fn ($ep) => $enricher->enrich($ep), $endpointList);
        }
        unset($endpointList);

        $sdkErrors = [];
        $sdkWarnings = [];

        // 3. Enrich unassigned endpoints and attempt to assign each to its schema via
        //    the resource's #[ResourceSchema]. An endpoint reaches "unassigned" when the
        //    route scanner could not derive a schema from the controller name.
        $unassigned = array_map(fn ($ep) => $enricher->enrich($ep), $routeData['unassigned'] ?? []);

        foreach ($unassigned as $endpoint) {
            $resourceClass = $endpoint['resolvedResourceClass'] ?? null;

            if ($resourceClass === null) {
                // No declared resource at all — we cannot know the response shape, so the
                // route is simply left out of the SDK (a warning, not an error).
                $sdkWarnings[] = [
                    'route' => $endpoint['method'].' '.$endpoint['path'],
                    'message' => 'No #[ApiResponse] — route excluded from the SDK.',
                ];

                continue;
            }

            $definition = (new ResourceScanner)->scanClass($resourceClass);

            if ($definition->schema === null) {
                // The resource is declared but it has no #[ResourceSchema], so we cannot place
                // its endpoint in any schema group. That's a misconfiguration the author must fix.
                $sdkErrors[] = [
                    'route' => $endpoint['method'].' '.$endpoint['path'],
                    'message' => class_basename($resourceClass).' has no #[ResourceSchema] — cannot assign to a schema group.',
                ];

                continue;
            }

            if (! isset($allowedSchemas[$definition->schema])) {
                continue;
            }

            $schemaEndpoints[$definition->schema][] = $endpoint;
        }

        // 4. Build one SdkSchemaContext per allowed schema that has endpoints.
        $schemas = [];
        foreach ($schemaEndpoints as $schemaClass => $endpoints) {
            if (! isset($allowedSchemas[$schemaClass])) {
                continue;
            }

            $modelName = $this->resolveModelName($schemaClass);
            $scanner = new SchemaScanner($schemaClass);
            $table = $scanner->scan();

            // Filter out undocumented endpoints (generic/missing return type, no #[ApiResponse]).
            // We cannot reliably generate a typed SDK method for them, so they're excluded — and,
            // NEW in the unified builder, each exclusion now emits a warning so the author knows a
            // route silently fell out of the SDK and can document it (the old GUI path filtered
            // silently). Action endpoints are never undocumented, so this only hits controllers.
            $documentedEndpoints = [];
            foreach ($endpoints as $endpoint) {
                if ($endpoint['responseUndocumented'] ?? false) {
                    $sdkWarnings[] = [
                        'route' => $endpoint['method'].' '.$endpoint['path'],
                        'message' => $endpoint['method'].' '.$endpoint['path'].': response is undocumented (add #[ApiResponse] or a typed resource return) — excluded from SDK.',
                    ];

                    continue;
                }

                $documentedEndpoints[] = $endpoint;
            }

            $customActions = [];
            foreach ($documentedEndpoints as $endpoint) {
                if ($endpoint['type'] === 'custom') {
                    $methods = explode('|', $endpoint['method']);
                    $httpMethod = strtolower($methods[0]);

                    $customActions[] = new SdkCustomAction(
                        name: $endpoint['action'],
                        httpMethod: $httpMethod,
                    );
                }
            }

            // The DTO is driven by the first documented endpoint that resolved response fields off
            // its Resource — exactly the data buildResponseFieldsFromResource() produced for the API
            // docs panel, so SDK and API docs always show the same shape.
            $resourceFields = null;
            $resourceClass = null;
            foreach ($documentedEndpoints as $endpoint) {
                if (isset($endpoint['responseFields'])) {
                    $resourceFields = $endpoint['responseFields'];
                    $resourceClass = $endpoint['resolvedResourceClass'] ?? null;
                    break;
                }
            }

            $schemas[$modelName] = new SdkSchemaContext(
                table: $table,
                customActions: $customActions,
                endpoints: $documentedEndpoints,
                resourceFields: $resourceFields,
                resourceClass: $resourceClass,
            );
            $discoveredSchemaClasses[$schemaClass] = true;
        }

        // 5. Surface controllers that exist on disk but registered no routes. The CLI SDK-gen
        //    paths (GenerateSdkCommand, GenerateController::sdkPreview/sdkGenerate) pass
        //    failOnMissingRoutes: true so generating an SDK with stale/incomplete routing
        //    fails loud — an SDK built from inferred verbs would call the wrong methods, so
        //    forcing the route registration to be fixed at the source is the right gate.
        //    The API docs path (GenerateController::apiRoutes) passes false so the visualizer
        //    can render the documentation it CAN compute, surfacing the missing-routes case
        //    as warnings the user can address incrementally. Same data, different presentation
        //    layer's tolerance — aligned with project_sdk_documentation_policy where warnings
        //    drive the visualizer's gating UX rather than killing the build.
        if ($apiConfig->controllerNamespace !== '') {
            $fs = new Filesystem;
            $actionScanner = new ControllerActionScanner;

            foreach ($schemaClasses as $schemaClass) {
                if (isset($discoveredSchemaClasses[$schemaClass])) {
                    continue;
                }

                $modelName = $this->resolveModelName($schemaClass);
                $controllerPath = $apiConfig->controllerPath($modelName);

                if (! $fs->exists($controllerPath)) {
                    continue;
                }

                if ($failOnMissingRoutes) {
                    throw SdkGenerationException::missingRoutes(
                        $schemaClass,
                        $controllerPath,
                        $actionScanner->scanFile($controllerPath),
                    );
                }

                $actions = $actionScanner->scanFile($controllerPath);
                $sdkWarnings[] = [
                    'route' => class_basename($schemaClass),
                    'message' => 'Controller at '.$controllerPath.' exists but no routes are registered for this schema. '
                        .'Discovered actions: '.(empty($actions) ? '(none)' : implode(', ', $actions)).'. '
                        .'Register the controller actions or remove the controller to clear this warning.',
                ];
            }
        }

        // 6. Resolve dependency Resources reached via the primary Resources' #[Resources\*]
        //    relationship attributes. The Resource layer is the SDK source-of-truth: each
        //    documented response Resource forms its own dep context, with its own resourceFields.
        //    The schema-walker (DependencyResolver) lives on for API/code/query gen — different
        //    concern (DB-touching codegen needs the schema relationship graph; SDK gen does not).
        $dependencySchemas = [];

        foreach ($schemas as $context) {
            $primaryResourceClass = null;
            foreach ($context->endpoints as $endpoint) {
                if (isset($endpoint['resolvedResourceClass'])) {
                    $primaryResourceClass = $endpoint['resolvedResourceClass'];
                    break;
                }
            }
            if ($primaryResourceClass === null) {
                continue;
            }

            $reached = $enricher->collectDepResources($primaryResourceClass);
            unset($reached[$primaryResourceClass]); // the entry is the primary, already registered

            foreach (array_keys($reached) as $depResourceClass) {
                $depModelName = SdkResourceNaming::modelNameFromResourceFqcn($depResourceClass);

                if (isset($schemas[$depModelName]) || isset($dependencySchemas[$depModelName])) {
                    continue;
                }

                $depResourceFields = $enricher->buildResponseFieldsFromResource($depResourceClass);
                if ($depResourceFields === null) {
                    continue;
                }

                // Schema-less Resources (e.g. contract Resources used for polymorphic shape)
                // register with table: null. SdkSchemaContext::$table is nullable for this case;
                // SdkGenerator routes them through generateFromFields automatically.
                $depDefinition = (new ResourceScanner)->scanClass($depResourceClass);
                $depTable = $depDefinition->schema !== null
                    ? (new SchemaScanner($depDefinition->schema))->scan()
                    : null;

                $dependencySchemas[$depModelName] = new SdkSchemaContext(
                    table: $depTable,
                    isDependencyOnly: true,
                    resourceFields: $depResourceFields,
                    resourceClass: $depResourceClass,
                );
            }
        }

        // 6b. Pull in DTOs for response models referenced by endpoints (e.g. a structured
        //     ActionResult returned by delete/archive). These are NOT reached by the relationship
        //     walk above — the only signal is an endpoint's responseModelName — yet the generated
        //     Resource method calls XData::fromArray(), so the DTO must exist or the SDK is invalid.
        //     We register them as dependency-only (a DTO, but no Resource/Client accessor).
        $responseDeps = $this->resolveResponseModelDependencies(
            $schemas,
            $dependencySchemas,
            $allowedSchemas,
        );
        foreach ($responseDeps as $depModelName => $depContext) {
            $dependencySchemas[$depModelName] = $depContext;
        }

        $allSchemas = array_merge($schemas, $dependencySchemas);

        // 6c. Register inner DTOs for rich column types. A column whose castType is a
        //     SchemaCraftColumn with a non-scalar SdkShape (collection/json-dto/bitmask)
        //     now emits a typed nested {X}Data DTO instead of a bare `array`. The DTO has
        //     no TableDefinition, so we register it as an inner-DTO dependency keyed by DTO
        //     name. Deduped across the whole SDK (and against real model DTOs) so a shared
        //     shape is emitted once. Recurses into nested DataSchemas/casts via collectInnerDtos.
        $allSchemas = $this->registerInnerColumnDtos($allSchemas);



        return new SdkBuildResult(
            schemas: $allSchemas,
            warnings: $sdkWarnings,
            errors: $sdkErrors,
        );
    }

    /**
     * Find response models referenced by documented endpoints that aren't already in the schema
     * set, and build a dependency-only context (DTO only) for each.
     *
     * @param  array<string, SdkSchemaContext>  $schemas
     * @param  array<string, SdkSchemaContext>  $dependencySchemas
     * @param  array<class-string, int>  $allowedSchemas  Flipped schema-class lookup
     * @return array<string, SdkSchemaContext>
     */
    private function resolveResponseModelDependencies(
        array $schemas,
        array $dependencySchemas,
        array $allowedSchemas,
    ): array {
        $extra = [];

        foreach ($schemas as $context) {
            foreach ($context->endpoints as $endpoint) {
                $responseModelName = $endpoint['responseModelName'] ?? null;
                $resourceClass = $endpoint['resolvedResourceClass'] ?? null;

                if ($responseModelName === null || $resourceClass === null) {
                    continue;
                }

                // Already covered as a primary or relationship dependency — nothing to add.
                if (isset($schemas[$responseModelName])
                    || isset($dependencySchemas[$responseModelName])
                    || isset($extra[$responseModelName])
                ) {
                    continue;
                }

                // The DTO shape comes from the resource's backing #[ResourceSchema]. Without it we
                // can't scan a table to build the DTO, so we skip (the GUI/CLI already errored on
                // the no-#[ResourceSchema] case for unassigned endpoints).
                $definition = (new ResourceScanner)->scanClass($resourceClass);
                if ($definition->schema === null) {
                    continue;
                }

                $scanner = new SchemaScanner($definition->schema);
                $extra[$responseModelName] = new SdkSchemaContext(
                    table: $scanner->scan(),
                    isDependencyOnly: true,
                    resourceClass: $resourceClass,
                );
            }
        }

        return $extra;
    }

    /**
     * Walk every schema's columns and register an inner-DTO context for each rich
     * column type whose SdkShape is non-scalar (collection / json-dto / bitmask).
     *
     * Dedup rules: an inner DTO is keyed by its DTO name. It is NOT registered if a
     * real model DTO already owns that key (a primary/dependency schema named the same),
     * since that file is already emitted — the inner shape just reuses the existing type.
     * Nested DataSchemas/casts are pulled in recursively by SdkDataGenerator::collectInnerDtos.
     *
     * @param  array<string, SdkSchemaContext>  $allSchemas
     * @return array<string, SdkSchemaContext>
     */
    private function registerInnerColumnDtos(array $allSchemas): array
    {
        $generator = new SdkDataGenerator;

        // Names already owned by a real model DTO ({Model}Data) — never shadow these.
        $existingDataNames = [];
        foreach (array_keys($allSchemas) as $modelName) {
            $existingDataNames[$modelName.'Data'] = true;
        }

        $innerDtos = [];

        foreach ($allSchemas as $context) {
            // Schema-backed contexts: walk table columns for shape-carrying columns. The Schema
            // scanner already stashes the DataSchema FQCN on `dataSchemaClass` (single-object
            // columns) or `collectionItemClass` (collection columns) — read it directly rather
            // than re-resolving from the cast type. Bitmask columns still flow through
            // SdkShape::forType using the cast class identity. Scalar columns produce no shape.
            if ($context->table !== null) {
                foreach ($context->table->columns as $column) {
                    if ($column->dataSchemaClass !== null) {
                        $shape = \SchemaCraft\Generator\Sdk\SdkShape::object($column->dataSchemaClass);
                    } elseif ($column->collectionItemClass !== null) {
                        $shape = \SchemaCraft\Generator\Sdk\SdkShape::collectionOf($column->collectionItemClass);
                    } elseif (is_string($column->castType)) {
                        $shape = \SchemaCraft\Generator\Sdk\SdkShape::forType($column->castType);
                        if ($shape === null || $shape->isScalar()) {
                            continue;
                        }
                    } else {
                        continue;
                    }

                    // Collect this shape's DTO + every nested DTO it references, deduped by name.
                    $innerDtos = $generator->collectInnerDtos($shape, $innerDtos);
                }

                continue;
            }

            // Schema-less Resources: same introspection on the declared property types. Single
            // helper (SdkShape::forType) — no separate "schema vs resource property" branching.
            if ($context->resourceFields !== null) {
                foreach ($context->resourceFields['columns'] as $col) {
                    $type = $col['type'] ?? null;
                    if (! is_string($type)) {
                        continue;
                    }

                    $shape = \SchemaCraft\Generator\Sdk\SdkShape::forType($type);
                    if ($shape === null || $shape->isScalar()) {
                        continue;
                    }

                    $innerDtos = $generator->collectInnerDtos($shape, $innerDtos);
                }
            }
        }

        foreach ($innerDtos as $dtoName => $definition) {
            // Already emitted as a real model DTO or already registered as inner — skip.
            if (isset($existingDataNames[$dtoName]) || isset($allSchemas[$dtoName])) {
                continue;
            }

            $allSchemas[$dtoName] = new SdkSchemaContext(
                isDependencyOnly: true,
                innerDto: $definition,
            );
        }

        return $allSchemas;
    }

    private function resolveModelName(string $schemaClass): string
    {
        $className = class_basename($schemaClass);

        return Str::beforeLast($className, 'Schema') ?: $className;
    }
}
