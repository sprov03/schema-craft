<?php

namespace SchemaCraft\Tests\Feature;

use SchemaCraft\Tests\TestCase;

class VisualizerRouteProductionTest extends TestCase
{
    public function test_visualizer_page_not_accessible_outside_local(): void
    {
        $response = $this->get('/_schema-craft');

        $response->assertNotFound();
    }

    public function test_api_not_accessible_outside_local(): void
    {
        $response = $this->getJson('/_schema-craft/api/schema');

        $response->assertNotFound();
    }

    public function test_apply_relationship_not_accessible_outside_local(): void
    {
        $response = $this->postJson('/_schema-craft/api/apply-relationship', [
            'schemaClass' => 'App\Schemas\DogSchema',
            'relationshipType' => 'belongsTo',
            'relatedModel' => 'App\Models\Owner',
        ]);

        $response->assertNotFound();
    }
}
