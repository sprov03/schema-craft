<?php

namespace SchemaCraft\Visualizer;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use ReflectionClass;
use SchemaCraft\Migration\SchemaDiscovery;
use SchemaCraft\QueryBuilder\IndexAnalyzer;
use SchemaCraft\QueryBuilder\QueryCodeGenerator;
use SchemaCraft\QueryBuilder\QueryCodeWriter;
use SchemaCraft\QueryBuilder\QueryDefinition;
use SchemaCraft\QueryBuilder\QueryDefinitionStorage;
use SchemaCraft\Writer\SchemaWriter;

class VisualizerController
{
    public function index(): Response
    {
        $html = file_get_contents(__DIR__.'/resources/visualizer.html');

        return new Response($html, 200, [
            'Content-Type' => 'text/html',
        ]);
    }

    public function api(): JsonResponse
    {
        $discovery = new SchemaDiscovery;
        $schemaClasses = $discovery->discover([app_path('Schemas')]);

        $analyzer = new SchemaAnalyzer($schemaClasses);
        $result = $analyzer->analyze();

        return new JsonResponse($result->toArray());
    }

    public function applyRelationship(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'schemaClass' => ['required', 'string'],
            'relationshipType' => ['required', 'string', 'in:belongsTo,hasMany,hasOne,belongsToMany,morphTo,morphOne,morphMany,morphToMany'],
            'relatedModel' => ['required', 'string'],
            'morphName' => ['nullable', 'string'],
        ]);

        $schemaClass = $validated['schemaClass'];

        if (! class_exists($schemaClass)) {
            return new JsonResponse([
                'success' => false,
                'message' => "Schema class not found: {$schemaClass}",
            ], 404);
        }

        $reflection = new ReflectionClass($schemaClass);
        $filePath = $reflection->getFileName();

        $writer = new SchemaWriter(new Filesystem);
        $result = $writer->addRelationship(
            schemaFilePath: $filePath,
            relationshipType: $validated['relationshipType'],
            relatedModelFqcn: $validated['relatedModel'],
            morphName: $validated['morphName'] ?? null,
        );

        return new JsonResponse([
            'success' => $result->success,
            'message' => $result->message,
        ], $result->success ? 200 : 422);
    }

    // ─── Query Builder API ──────────────────────────────────────

    /**
     * List all saved query definitions.
     */
    public function listQueries(): JsonResponse
    {
        $storage = new QueryDefinitionStorage(new Filesystem);

        return new JsonResponse($storage->list());
    }

    /**
     * Load a single saved query definition.
     */
    public function loadQuery(string $name): JsonResponse
    {
        $storage = new QueryDefinitionStorage(new Filesystem);

        try {
            $query = $storage->load($name);

            return new JsonResponse($query->toArray());
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Save (create or update) a query definition.
     */
    public function saveQuery(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'regex:/^[a-zA-Z][a-zA-Z0-9]*$/'],
            'baseTable' => ['required', 'string'],
            'baseModel' => ['required', 'string'],
            'baseSchema' => ['nullable', 'string'],
            'joins' => ['sometimes', 'array'],
            'conditions' => ['sometimes', 'array'],
            'conditionGroups' => ['sometimes', 'array'],
            'whereHas' => ['sometimes', 'array'],
            'sorts' => ['sometimes', 'array'],
            'selects' => ['sometimes', 'array'],
            'output' => ['sometimes', 'array'],
            'indexSuggestions' => ['sometimes', 'array'],
        ]);

        $query = QueryDefinition::fromArray($validated);
        $storage = new QueryDefinitionStorage(new Filesystem);
        $path = $storage->save($query);

        return new JsonResponse([
            'success' => true,
            'path' => $path,
            'message' => "Query definition [{$query->name}] saved.",
        ]);
    }

    /**
     * Delete a saved query definition.
     */
    public function deleteQuery(string $name): JsonResponse
    {
        $storage = new QueryDefinitionStorage(new Filesystem);

        if (! $storage->exists($name)) {
            return new JsonResponse([
                'success' => false,
                'message' => "Query definition [{$name}] not found.",
            ], 404);
        }

        $storage->delete($name);

        return new JsonResponse([
            'success' => true,
            'message' => "Query definition [{$name}] deleted.",
        ]);
    }

    /**
     * Return the API configurations for the output panel dropdown.
     */
    public function queryConfig(): JsonResponse
    {
        $apis = config('schema-craft.apis', []);
        $defaultApi = config('schema-craft.default', 'default');

        return new JsonResponse([
            'apis' => $apis,
            'defaultApi' => $defaultApi,
        ]);
    }

    /**
     * Generate PHP code from a query definition and write files to disk.
     */
    public function generateQuery(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'regex:/^[a-zA-Z][a-zA-Z0-9]*$/'],
            'baseTable' => ['required', 'string'],
            'baseModel' => ['required', 'string'],
            'baseSchema' => ['nullable', 'string'],
            'joins' => ['sometimes', 'array'],
            'conditions' => ['sometimes', 'array'],
            'conditionGroups' => ['sometimes', 'array'],
            'whereHas' => ['sometimes', 'array'],
            'sorts' => ['sometimes', 'array'],
            'selects' => ['sometimes', 'array'],
            'output' => ['sometimes', 'array'],
            'indexSuggestions' => ['sometimes', 'array'],
            'api' => ['sometimes', 'string'],
        ]);

        $query = QueryDefinition::fromArray($validated);

        // Auto-save the definition
        $storage = new QueryDefinitionStorage(new Filesystem);
        $storage->save($query);

        // Resolve API config
        $selectedApi = $validated['api'] ?? config('schema-craft.default', 'default');
        $apiConfig = config("schema-craft.apis.{$selectedApi}", []);

        // Analyze indexes
        $indexAnalyzer = new IndexAnalyzer;
        $indexSuggestions = $indexAnalyzer->analyze($query);

        // Generate code
        $generator = new QueryCodeGenerator;
        $writer = new QueryCodeWriter(new Filesystem);
        $generatedFiles = [];
        $writtenFiles = [];

        if ($query->wantsScope()) {
            $scopeCode = $generator->generateScope($query);
            $generatedFiles['scope'] = [
                'type' => 'scope',
                'code' => $scopeCode,
                'description' => "Scope method on {$query->modelClass()} model",
            ];

            $writtenFiles['scope'] = $writer->writeScope($query, $scopeCode)->toArray();
            $writtenFiles['scopePhpDoc'] = $writer->writeScopePhpDoc($query, $generator)->toArray();
        }

        if ($query->wantsApiEndpoint() || $query->wantsInlineController()) {
            $controllerNamespace = $apiConfig['namespaces']['controller'] ?? 'App\\Http\\Controllers\\Api';
            $requestNamespace = $apiConfig['namespaces']['request'] ?? 'App\\Http\\Requests';
            $resourceNamespace = $apiConfig['namespaces']['resource'] ?? 'App\\Resources';

            $controllerFqcn = $controllerNamespace.'\\'.$query->modelClass().'Controller';
            $requestFqcn = $requestNamespace.'\\'.ucfirst($query->name).'Request';
            $resourceFqcn = $resourceNamespace.'\\'.$query->modelClass().'Resource';

            $controllerMethodCode = $generator->generateControllerMethod($query);
            $formRequestCode = $generator->generateFormRequest($query, $requestNamespace);
            $routeCode = $generator->generateRouteRegistration($query, $controllerFqcn);

            $generatedFiles['controllerMethod'] = [
                'type' => 'controller_method',
                'code' => $controllerMethodCode,
                'description' => "Controller method: {$query->name}()",
            ];
            $generatedFiles['formRequest'] = [
                'type' => 'form_request',
                'code' => $formRequestCode,
                'description' => ucfirst($query->name).'Request form request class',
            ];
            $generatedFiles['route'] = [
                'type' => 'route',
                'code' => $routeCode,
                'description' => 'Route registration line',
            ];

            $writtenFiles['formRequest'] = $writer->writeFormRequest($query, $formRequestCode, $requestNamespace)->toArray();
            $writtenFiles['controller'] = $writer->writeControllerMethod(
                $query,
                $controllerMethodCode,
                $controllerFqcn,
                $requestFqcn,
                $resourceFqcn,
            )->toArray();
            $writtenFiles['route'] = $writer->writeRouteRegistration(
                $query,
                $routeCode,
                $controllerFqcn,
                $apiConfig,
            )->toArray();

            // Auto-generate Resource class and all dependency resources
            $modelNamespace = $apiConfig['namespaces']['model'] ?? 'App\\Models';
            $resourceResults = $writer->writeResourceWithDependencies($query, $resourceNamespace, $modelNamespace);
            foreach ($resourceResults as $resourceClass => $result) {
                $writtenFiles["resource:{$resourceClass}"] = $result->toArray();
            }
        }

        return new JsonResponse([
            'success' => true,
            'generated' => $generatedFiles,
            'written' => $writtenFiles,
            'indexSuggestions' => $indexSuggestions,
            'message' => "Generated code for [{$query->name}].",
        ]);
    }

    /**
     * Preview the SQL for a query definition.
     */
    public function previewSql(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string'],
            'baseTable' => ['required', 'string'],
            'baseModel' => ['required', 'string'],
            'baseSchema' => ['nullable', 'string'],
            'joins' => ['sometimes', 'array'],
            'conditions' => ['sometimes', 'array'],
            'conditionGroups' => ['sometimes', 'array'],
            'whereHas' => ['sometimes', 'array'],
            'sorts' => ['sometimes', 'array'],
            'selects' => ['sometimes', 'array'],
            'output' => ['sometimes', 'array'],
        ]);

        $query = QueryDefinition::fromArray($validated);
        $generator = new QueryCodeGenerator;

        return new JsonResponse([
            'sql' => $generator->buildSqlPreview($query),
        ]);
    }
}
