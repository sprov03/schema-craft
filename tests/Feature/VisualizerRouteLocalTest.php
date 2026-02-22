<?php

namespace SchemaCraft\Tests\Feature;

use SchemaCraft\Tests\TestCase;

class VisualizerRouteLocalTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['env'] = 'local';
    }

    public function test_visualizer_page_returns_html(): void
    {
        $response = $this->get('/_schema-craft');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $response->assertSee('SchemaCraft');
    }

    public function test_api_returns_json_with_expected_structure(): void
    {
        $response = $this->getJson('/_schema-craft/api/schema');

        $response->assertOk();
        $response->assertJsonStructure([
            'summary' => ['modelCount', 'relationshipCount', 'issueCount'],
            'schemas',
            'issues',
        ]);
    }
}
