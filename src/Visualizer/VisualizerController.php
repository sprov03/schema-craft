<?php

namespace SchemaCraft\Visualizer;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use ReflectionClass;
use SchemaCraft\Migration\SchemaDiscovery;
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
}
