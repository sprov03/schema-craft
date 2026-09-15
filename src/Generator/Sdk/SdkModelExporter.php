<?php

namespace SchemaCraft\Generator\Sdk;

use SchemaCraft\Generator\Api\GeneratedFile;
use SchemaCraft\Scanner\SchemaResolver;
use SchemaCraft\Scanner\SchemaScanner;

/**
 * Orchestrates read-only model export from a set of schema classes.
 *
 * This is the SINGLE place that turns schema classes into model files, called by both the CLI
 * (ExportModelsCommand) and the visualizer SDK build (GenerateController::buildSdkFiles) so the two
 * paths can never drift. It scans each schema into a TableDefinition and hands the map to
 * SdkModelGenerator (which stays a pure map -> files emitter).
 */
class SdkModelExporter
{
    /**
     * Surfaced when an API pins a `schemas` subset. Model export is meant to include every model on
     * the connection; a subset can leave a cross-connection (or otherwise out-of-list) relation
     * pointing at a model that isn't in the package. It's a warning, not an error — the filter still
     * works — so the author on the generation page knows the risk.
     */
    public const SCHEMAS_FILTER_WARNING = "The API's 'schemas' filter is set, so model export is limited to those schemas. A relation to a model outside the list will be an unresolved reference in the exported package — model export is designed to include every model on the connection. Leave 'schemas' unset unless this is intended.";

    /**
     * @param  string[]  $schemaClasses  Connection-driven set — every model, NOT route-filtered.
     * @param  string  $namespace  SDK package base namespace (models nest under "{namespace}\Models").
     * @param  string  $sourceModelNamespace  Source model-namespace root to strip so each model's
     *                                         relative sub-namespace is preserved.
     * @param  bool  $schemasFilterActive  True when the caller narrowed $schemaClasses via the API's
     *                                      `schemas` list — triggers SCHEMAS_FILTER_WARNING.
     * @return array{files: array<string, GeneratedFile>, warnings: array<int, array{route: ?string, message: string}>}
     */
    public function export(array $schemaClasses, string $namespace, string $sourceModelNamespace, bool $schemasFilterActive = false): array
    {
        $contexts = [];
        $warnings = [];

        if ($schemasFilterActive) {
            $warnings[] = ['route' => null, 'message' => self::SCHEMAS_FILTER_WARNING];
        }

        foreach ($schemaClasses as $schemaClass) {
            try {
                $table = (new SchemaScanner($schemaClass))->scan();
            } catch (\Throwable $e) {
                // A single unscannable schema must not abort the export (or the SDK build it rides
                // inside) — skip it and surface why, so the rest still ships.
                $warnings[] = ['route' => null, 'message' => "Model export skipped [{$schemaClass}]: {$e->getMessage()}"];

                continue;
            }

            $modelName = class_basename(SchemaResolver::schemaToModelFqcn($schemaClass));
            $contexts[$modelName] = new SdkSchemaContext(table: $table);
        }

        return [
            'files' => (new SdkModelGenerator)->generate($contexts, $namespace, $sourceModelNamespace),
            'warnings' => $warnings,
        ];
    }
}
