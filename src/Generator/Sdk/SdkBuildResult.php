<?php

namespace SchemaCraft\Generator\Sdk;

/**
 * Result of SdkContextBuilder::build().
 *
 * A tiny value object so the two callers (the visualizer's GenerateController and the
 * CLI's GenerateSdkCommand) can share the exact same pipeline output. Warnings and errors
 * are surfaced — never thrown — so each caller renders them in its own idiom (JSON for the
 * GUI, console components for the CLI) while the build still succeeds for the parts that work.
 */
class SdkBuildResult
{
    /**
     * @param  array<string, SdkSchemaContext>  $schemas  Keyed by model name (primary + dependency-only)
     * @param  array<int, array{route: string, message: string}>  $warnings  Non-fatal issues (e.g. undocumented endpoints excluded from the SDK)
     * @param  array<int, array{route: string, message: string}>  $errors  Resolvable-but-misconfigured issues (e.g. a resource with no #[ResourceSchema])
     */
    public function __construct(
        public readonly array $schemas,
        public readonly array $warnings = [],
        public readonly array $errors = [],
    ) {}

    /**
     * Project the build result to the unified API docs JSON shape consumed by both
     * the visualizer's API tab AND the SDK generator.
     *
     * Why this projection exists: the visualizer and the SDK are two views of the same
     * documented API. This is the single projection point both consumers use — the
     * visualizer's GenerateController::apiRoutes returns this exact shape; the SDK
     * pipeline consumes the SdkSchemaContext map directly. No visualizer-specific
     * carve-outs, no relatedFields inlining, no display-suffix dance. SdkResourceNaming
     * owns the canonical Resource → Model → Data naming transforms when needed.
     *
     * @return array{apiName: string, routeFile: ?string, routePrefix: ?string, schemas: array<string, array>, warnings: array, errors: array}
     */
    public function toApiDocsJson(string $apiName, ?string $routeFile, ?string $routePrefix): array
    {
        $schemas = [];
        foreach ($this->schemas as $modelName => $ctx) {
            $schemas[$modelName] = $this->projectSchema($ctx, $modelName);
        }

        return [
            'apiName' => $apiName,
            'routeFile' => $routeFile,
            'routePrefix' => $routePrefix,
            'schemas' => $schemas,
            // FQCN → absolute source-file path. Populated for every class FQCN that appears
            // in the payload (resourceClass, schemaClass, column types, relationship targets)
            // and resolves to a file via reflection. The visualizer renders an "open in IDE"
            // icon next to type references when an entry is present. Centralized map so the
            // same FQCN appearing in N places doesn't bloat the payload with N copies.
            'classSources' => $this->collectClassSources($schemas),
            'warnings' => $this->warnings,
            'errors' => $this->errors,
        ];
    }

    /**
     * Walk the projected schemas and collect FQCN → absolute file path for every class
     * reference that has a discoverable source file. Synthesized inner DTOs (bitmask
     * FlagsData, etc.) don't have source files — only declared classes do.
     *
     * @param  array<string, array>  $schemas
     * @return array<string, string>
     */
    private function collectClassSources(array $schemas): array
    {
        $fqcns = [];

        foreach ($schemas as $entry) {
            foreach (['resourceClass', 'schemaClass'] as $key) {
                if (! empty($entry[$key])) {
                    $fqcns[$entry[$key]] = true;
                }
            }
            foreach ($entry['columns'] ?? [] as $col) {
                if (! empty($col['type']) && is_string($col['type'])) {
                    $fqcns[$col['type']] = true;
                }
            }
            foreach ($entry['relationships'] ?? [] as $rel) {
                if (! empty($rel['relatedResource'])) {
                    $fqcns[$rel['relatedResource']] = true;
                }
            }
        }

        $sources = [];
        foreach (array_keys($fqcns) as $fqcn) {
            $path = $this->resolveSourceFile($fqcn);
            if ($path !== null) {
                $sources[$fqcn] = $path;
            }
        }

        return $sources;
    }

    private function resolveSourceFile(string $fqcn): ?string
    {
        if (! class_exists($fqcn) && ! interface_exists($fqcn) && ! enum_exists($fqcn)) {
            return null;
        }

        try {
            $file = (new \ReflectionClass($fqcn))->getFileName();
        } catch (\Throwable) {
            return null;
        }

        return $file === false ? null : $file;
    }

    private function projectSchema(SdkSchemaContext $ctx, string $modelName): array
    {
        return [
            'modelName' => $modelName,
            'resourceClass' => $ctx->resourceClass,
            'schemaClass' => $ctx->table?->schemaClass,
            'isDependencyOnly' => $ctx->isDependencyOnly,
            'tableName' => $ctx->table?->tableName,
            'columns' => $this->projectColumns($ctx),
            'relationships' => $this->projectRelationships($ctx),
            'endpoints' => $ctx->endpoints,
            'customActions' => array_map(
                fn (SdkCustomAction $a) => ['name' => $a->name, 'httpMethod' => $a->httpMethod],
                $ctx->customActions
            ),
        ];
    }

    private function projectColumns(SdkSchemaContext $ctx): array
    {
        if ($ctx->innerDto !== null) {
            return array_map(
                fn ($f) => ['name' => $f['name'], 'type' => $f['type'], 'nullable' => $f['nullable'] ?? false],
                $ctx->innerDto['fields'] ?? []
            );
        }

        if ($ctx->resourceFields !== null) {
            return $ctx->resourceFields['columns'];
        }

        // Schema-driven contexts (currently: step 6b response-model deps like ActionResultData).
        // Project table columns to the contract shape — name, type, nullable only. Schema-level
        // metadata (primary, autoIncrement, etc.) is intentionally NOT projected — the API
        // contract is Resource-shape across all consumers. Step-6b alignment will eventually
        // route these through resourceFields too; this branch is the temporary bridge.
        if ($ctx->table !== null) {
            return array_map(
                fn ($col) => [
                    'name' => $col->name,
                    'type' => $col->phpType ?? $col->columnType,
                    'nullable' => $col->nullable,
                ],
                $ctx->table->columns
            );
        }

        return [];
    }

    private function projectRelationships(SdkSchemaContext $ctx): array
    {
        if ($ctx->innerDto !== null) {
            return [];
        }

        if ($ctx->resourceFields !== null) {
            return array_map(
                fn ($rel) => [
                    'name' => $rel['name'],
                    'type' => $rel['type'],
                    'relatedResource' => $rel['relatedResource'] ?? null,
                    'isCollection' => $rel['isCollection'],
                ],
                $ctx->resourceFields['relationships']
            );
        }

        // Schema-driven contexts (step 6b response-model deps) — no Resource layer for these,
        // so relatedResource is null. The relationship's related model FQCN is the schema's
        // info; consumers needing Resource-backed nested rendering should route through the
        // Resource-walked path instead. Step-6b alignment will eventually make this branch
        // moot.
        if ($ctx->table !== null) {
            return array_map(
                fn ($rel) => [
                    'name' => $rel->name,
                    'type' => $rel->type,
                    'relatedResource' => null,
                    'isCollection' => SdkRelationshipCardinality::isCollection($rel->type),
                ],
                $ctx->table->relationships
            );
        }

        return [];
    }
}
