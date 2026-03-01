<?php

namespace SchemaCraft\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use SchemaCraft\Tests\TestCase;

class GenerateControllerTest extends TestCase
{
    private Filesystem $files;

    private string $tempDir;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['env'] = 'local';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->tempDir = sys_get_temp_dir().'/gen-ctrl-test-'.uniqid();
        $this->files->makeDirectory($this->tempDir.'/app/Schemas', 0755, true);
        $this->files->makeDirectory($this->tempDir.'/app/Models', 0755, true);
        $this->files->makeDirectory($this->tempDir.'/app/Http/Controllers/Api', 0755, true);
        $this->files->makeDirectory($this->tempDir.'/app/Models/Services', 0755, true);
        $this->files->makeDirectory($this->tempDir.'/app/Http/Requests', 0755, true);
        $this->files->makeDirectory($this->tempDir.'/app/Resources', 0755, true);
        $this->files->makeDirectory($this->tempDir.'/database/factories', 0755, true);
        $this->files->makeDirectory($this->tempDir.'/tests/Unit', 0755, true);
        $this->files->makeDirectory($this->tempDir.'/tests/Feature/Controllers', 0755, true);
        $this->files->makeDirectory($this->tempDir.'/routes', 0755, true);
        $this->files->makeDirectory($this->tempDir.'/config', 0755, true);
        $this->files->makeDirectory($this->tempDir.'/bootstrap', 0755, true);

        $this->app->useAppPath($this->tempDir.'/app');
        $this->app->setBasePath($this->tempDir);

        $this->app['config']->set('schema-craft.schema_paths', [$this->tempDir.'/app/Schemas']);
        $this->app['config']->set('schema-craft.apis.default.namespaces', [
            'controller' => 'App\\Http\\Controllers\\Api',
            'service' => 'App\\Models\\Services',
            'request' => 'App\\Http\\Requests',
            'resource' => 'App\\Resources',
            'schema' => 'App\\Schemas',
            'model' => 'App\\Models',
        ]);
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    private function createSchemaFile(string $name, array $columns = []): void
    {
        $colDefs = '';
        foreach ($columns as $col) {
            $attrs = '';
            if (isset($col['type'])) {
                $attrs .= "    #[ColumnType('{$col['type']}')]\n";
            }
            $phpType = $col['phpType'] ?? 'string';
            $colDefs .= "{$attrs}    public {$phpType} \${$col['name']};\n\n";
        }

        $content = <<<PHP
<?php

namespace App\Schemas;

use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\ColumnType;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Schema;
use SchemaCraft\Traits\TimestampsSchema;

class {$name}Schema extends Schema
{
    use TimestampsSchema;

    #[Primary]
    #[AutoIncrement]
    public int \$id;

{$colDefs}}
PHP;

        $path = $this->tempDir."/app/Schemas/{$name}Schema.php";
        $this->files->put($path, $content);

        require_once $path;
    }

    private function createFakeController(string $modelName): void
    {
        $routePrefix = Str::snake(Str::pluralStudly($modelName), '-');
        $routeParam = Str::camel($modelName);

        $path = $this->tempDir."/app/Http/Controllers/Api/{$modelName}Controller.php";
        $content = <<<PHP
<?php

namespace App\Http\Controllers\Api;

use Illuminate\Support\Facades\Route;

class {$modelName}Controller
{
    public static function apiRoutes(): void
    {
        Route::get('{$routePrefix}', [{$modelName}Controller::class, 'getCollection']);
        Route::get('{$routePrefix}/{{$routeParam}}', [{$modelName}Controller::class, 'get']);
        Route::post('{$routePrefix}', [{$modelName}Controller::class, 'create']);
        Route::put('{$routePrefix}/{{{$routeParam}}}', [{$modelName}Controller::class, 'update']);
        Route::delete('{$routePrefix}/{{{$routeParam}}}', [{$modelName}Controller::class, 'delete']);
    }

    public function getCollection() {}
    public function get() {}
    public function create() {}
    public function update() {}
    public function delete() {}
}
PHP;
        $this->files->put($path, $content);
    }

    private function createFakeService(string $modelName): void
    {
        $path = $this->tempDir."/app/Models/Services/{$modelName}Service.php";
        $content = <<<PHP
<?php

namespace App\Models\Services;

class {$modelName}Service
{
}
PHP;
        $this->files->put($path, $content);
    }

    // ─── GET /api/generate/config ──────────────────────

    public function test_config_returns_apis_and_schemas(): void
    {
        $this->createSchemaFile('GenWidget', [
            ['name' => 'name', 'phpType' => 'string'],
        ]);

        $response = $this->getJson('/_schema-craft/api/generate/config');

        $response->assertOk();
        $response->assertJsonStructure(['apis', 'schemas']);

        $this->assertContains('default', $response->json('apis'));

        $schemas = $response->json('schemas');
        $widget = collect($schemas)->firstWhere('modelName', 'GenWidget');
        $this->assertNotNull($widget);
        $this->assertArrayHasKey('hasController', $widget);
        $this->assertArrayHasKey('hasService', $widget);
    }

    public function test_config_detects_existing_controller(): void
    {
        $this->createSchemaFile('GenGadget', [
            ['name' => 'name', 'phpType' => 'string'],
        ]);
        $this->createFakeController('GenGadget');

        $response = $this->getJson('/_schema-craft/api/generate/config');

        $response->assertOk();
        $schemas = $response->json('schemas');
        $gadget = collect($schemas)->firstWhere('modelName', 'GenGadget');
        $this->assertNotNull($gadget);
        $this->assertTrue($gadget['hasController']);
    }

    // ─── POST /api/generate/preview ────────────────────

    public function test_generate_preview_returns_api_stack_files(): void
    {
        $this->createSchemaFile('GenItem', [
            ['name' => 'title', 'phpType' => 'string'],
        ]);

        $response = $this->postJson('/_schema-craft/api/generate/preview', [
            'schema' => 'App\\Schemas\\GenItemSchema',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $files = $response->json('files');
        $this->assertNotEmpty($files);

        // Should include controller, service, requests, resource, factory, tests
        $paths = collect($files)->pluck('path')->all();
        $this->assertTrue(
            collect($paths)->contains(fn ($p) => str_contains($p, 'GenItemController')),
            'Should contain controller file',
        );
        $this->assertTrue(
            collect($paths)->contains(fn ($p) => str_contains($p, 'GenItemService')),
            'Should contain service file',
        );

        // Files should NOT be written to disk
        foreach ($files as $f) {
            $this->assertFileDoesNotExist($this->tempDir.'/'.$f['path']);
        }
    }

    public function test_generate_preview_without_factory_or_tests(): void
    {
        $this->createSchemaFile('GenThing', [
            ['name' => 'label', 'phpType' => 'string'],
        ]);

        $response = $this->postJson('/_schema-craft/api/generate/preview', [
            'schema' => 'App\\Schemas\\GenThingSchema',
            'noFactory' => true,
            'noTest' => true,
        ]);

        $response->assertOk();

        $paths = collect($response->json('files'))->pluck('path')->all();
        $this->assertFalse(
            collect($paths)->contains(fn ($p) => str_contains($p, 'Factory')),
            'Should not contain factory file',
        );
        $this->assertFalse(
            collect($paths)->contains(fn ($p) => str_contains($p, 'Test.php')),
            'Should not contain test files',
        );
    }

    public function test_generate_preview_rejects_missing_schema(): void
    {
        $response = $this->postJson('/_schema-craft/api/generate/preview', [
            'schema' => 'App\\Schemas\\NonExistentSchema',
        ]);

        $response->assertNotFound();
        $response->assertJson(['success' => false]);
    }

    // ─── POST /api/generate ────────────────────────────

    public function test_generate_writes_files_to_disk(): void
    {
        $this->createSchemaFile('GenProduct', [
            ['name' => 'name', 'phpType' => 'string'],
        ]);

        $response = $this->postJson('/_schema-craft/api/generate', [
            'schema' => 'App\\Schemas\\GenProductSchema',
            'noFactory' => true,
            'noTest' => true,
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $files = $response->json('files');
        $createdFiles = collect($files)->filter(fn ($f) => $f['created'] ?? false);
        $this->assertGreaterThan(0, $createdFiles->count());
    }

    public function test_generate_skips_existing_without_force(): void
    {
        $this->createSchemaFile('GenDoodad', [
            ['name' => 'label', 'phpType' => 'string'],
        ]);

        // First generate
        $this->postJson('/_schema-craft/api/generate', [
            'schema' => 'App\\Schemas\\GenDoodadSchema',
            'noFactory' => true,
            'noTest' => true,
        ]);

        // Second generate without force
        $response = $this->postJson('/_schema-craft/api/generate', [
            'schema' => 'App\\Schemas\\GenDoodadSchema',
            'noFactory' => true,
            'noTest' => true,
            'force' => false,
        ]);

        $response->assertOk();
        $files = $response->json('files');
        $skippedFiles = collect($files)->filter(fn ($f) => $f['skipped'] ?? false);
        $this->assertGreaterThan(0, $skippedFiles->count());
    }

    public function test_generate_overwrites_with_force(): void
    {
        $this->createSchemaFile('GenGizmo', [
            ['name' => 'name', 'phpType' => 'string'],
        ]);

        // First generate
        $this->postJson('/_schema-craft/api/generate', [
            'schema' => 'App\\Schemas\\GenGizmoSchema',
            'noFactory' => true,
            'noTest' => true,
        ]);

        // Second generate with force
        $response = $this->postJson('/_schema-craft/api/generate', [
            'schema' => 'App\\Schemas\\GenGizmoSchema',
            'noFactory' => true,
            'noTest' => true,
            'force' => true,
        ]);

        $response->assertOk();
        $files = $response->json('files');
        $createdFiles = collect($files)->filter(fn ($f) => $f['created'] ?? false);
        $this->assertGreaterThan(0, $createdFiles->count());
        $skippedFiles = collect($files)->filter(fn ($f) => $f['skipped'] ?? false);
        $this->assertCount(0, $skippedFiles);
    }

    // ─── POST /api/generate/action/preview ─────────────

    public function test_action_preview_returns_request_and_patches(): void
    {
        $this->createSchemaFile('GenOrder', [
            ['name' => 'total', 'phpType' => 'int'],
        ]);
        $this->createFakeController('GenOrder');
        $this->createFakeService('GenOrder');

        $response = $this->postJson('/_schema-craft/api/generate/action/preview', [
            'schema' => 'App\\Schemas\\GenOrderSchema',
            'action' => 'cancel',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $files = $response->json('files');
        $this->assertNotEmpty($files);

        // Should have a new request file
        $paths = collect($files)->pluck('path')->all();
        $this->assertTrue(
            collect($paths)->contains(fn ($p) => str_contains($p, 'CancelGenOrderRequest')),
            'Should contain new request file',
        );
    }

    public function test_action_requires_existing_controller(): void
    {
        $this->createSchemaFile('GenPlanet', [
            ['name' => 'name', 'phpType' => 'string'],
        ]);

        $response = $this->postJson('/_schema-craft/api/generate/action/preview', [
            'schema' => 'App\\Schemas\\GenPlanetSchema',
            'action' => 'explode',
        ]);

        $response->assertUnprocessable();
        $response->assertJson(['success' => false]);
    }

    // ─── POST /api/create-api ──────────────────────────

    public function test_create_api_creates_route_file(): void
    {
        // Create a minimal config file
        $this->files->put($this->tempDir.'/config/schema-craft.php', $this->files->get(
            dirname(__DIR__, 2).'/config/schema-craft.php',
        ));

        // Create a bootstrap/app.php with withRouting
        $this->files->put($this->tempDir.'/bootstrap/app.php', <<<'PHP'
<?php

use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        health: '/up',
    )
    ->create();
PHP);

        $response = $this->postJson('/_schema-craft/api/create-api', [
            'name' => 'partner',
            'prefix' => 'partner-api',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        // Route file should exist
        $this->assertFileExists($this->tempDir.'/routes/partner-api.php');
    }

    public function test_create_api_rejects_duplicate_name(): void
    {
        $response = $this->postJson('/_schema-craft/api/create-api', [
            'name' => 'default',
        ]);

        $response->assertUnprocessable();
        $response->assertJson(['success' => false]);
    }

    // ─── GET /api/sdk/config ───────────────────────────

    public function test_sdk_config_returns_settings(): void
    {
        $response = $this->getJson('/_schema-craft/api/sdk/config?api=default');

        $response->assertOk();
        $response->assertJsonStructure(['path', 'name', 'namespace', 'client', 'version']);
    }

    // ─── Validation ────────────────────────────────────

    public function test_generate_preview_requires_schema(): void
    {
        $response = $this->postJson('/_schema-craft/api/generate/preview', []);

        $response->assertUnprocessable();
    }

    public function test_action_preview_requires_schema_and_action(): void
    {
        $response = $this->postJson('/_schema-craft/api/generate/action/preview', [
            'schema' => 'App\\Schemas\\SomeSchema',
        ]);

        $response->assertUnprocessable();
    }

    public function test_create_api_requires_name(): void
    {
        $response = $this->postJson('/_schema-craft/api/create-api', []);

        $response->assertUnprocessable();
    }

    // ─── GET /api/generate/stack-detail ────────────────

    public function test_stack_detail_returns_standard_endpoints(): void
    {
        $this->createSchemaFile('GenRobot', [
            ['name' => 'name', 'phpType' => 'string'],
        ]);
        $this->createFakeController('GenRobot');
        $this->createFakeService('GenRobot');

        $response = $this->getJson('/_schema-craft/api/generate/stack-detail?schema=App%5CSchemas%5CGenRobotSchema');

        $response->assertOk();
        $response->assertJsonStructure(['modelName', 'routePrefix', 'hasController', 'hasService', 'endpoints']);

        $this->assertEquals('GenRobot', $response->json('modelName'));
        $this->assertTrue($response->json('hasController'));
        $this->assertTrue($response->json('hasService'));

        $endpoints = $response->json('endpoints');
        $this->assertCount(5, $endpoints);

        $methods = collect($endpoints)->pluck('method')->all();
        $this->assertContains('GET', $methods);
        $this->assertContains('POST', $methods);
        $this->assertContains('PUT', $methods);
        $this->assertContains('DELETE', $methods);

        $actions = collect($endpoints)->pluck('action')->all();
        $this->assertContains('getCollection', $actions);
        $this->assertContains('get', $actions);
        $this->assertContains('create', $actions);
        $this->assertContains('update', $actions);
        $this->assertContains('delete', $actions);
    }

    public function test_stack_detail_returns_404_for_missing_controller(): void
    {
        $this->createSchemaFile('GenGhost', [
            ['name' => 'name', 'phpType' => 'string'],
        ]);

        $response = $this->getJson('/_schema-craft/api/generate/stack-detail?schema=App%5CSchemas%5CGenGhostSchema');

        $response->assertNotFound();
        $response->assertJson(['success' => false]);
    }

    public function test_stack_detail_includes_custom_actions(): void
    {
        $this->createSchemaFile('GenTask', [
            ['name' => 'title', 'phpType' => 'string'],
        ]);

        // Create a controller with custom actions and Route definitions
        $path = $this->tempDir.'/app/Http/Controllers/Api/GenTaskController.php';
        $content = <<<'PHP'
<?php

namespace App\Http\Controllers\Api;

use Illuminate\Support\Facades\Route;

class GenTaskController
{
    public static function apiRoutes(): void
    {
        Route::get('gen-tasks', [GenTaskController::class, 'getCollection']);
        Route::get('gen-tasks/{genTask}', [GenTaskController::class, 'get']);
        Route::post('gen-tasks', [GenTaskController::class, 'create']);
        Route::put('gen-tasks/{genTask}', [GenTaskController::class, 'update']);
        Route::delete('gen-tasks/{genTask}', [GenTaskController::class, 'delete']);
        Route::put('gen-tasks/{genTask}/complete', [GenTaskController::class, 'complete']);
        Route::delete('gen-tasks/{genTask}/archive', [GenTaskController::class, 'archive']);
    }

    public function getCollection() {}
    public function get() {}
    public function create() {}
    public function update() {}
    public function delete() {}

    public function complete() {}
    public function archive() {}
}
PHP;
        $this->files->put($path, $content);
        $this->createFakeService('GenTask');

        $response = $this->getJson('/_schema-craft/api/generate/stack-detail?schema=App%5CSchemas%5CGenTaskSchema');

        $response->assertOk();

        $endpoints = $response->json('endpoints');
        // 5 standard + 2 custom (complete, archive)
        $this->assertCount(7, $endpoints);

        $customEndpoints = collect($endpoints)->where('type', 'custom')->values()->all();
        $this->assertCount(2, $customEndpoints);

        $customActions = collect($customEndpoints)->pluck('action')->all();
        $this->assertContains('complete', $customActions);
        $this->assertContains('archive', $customActions);

        // Custom actions should have their actual HTTP methods from the Route:: definitions
        $archiveEndpoint = collect($customEndpoints)->firstWhere('action', 'archive');
        $this->assertEquals('DELETE', $archiveEndpoint['method']);
    }

    public function test_stack_detail_with_empty_routes_returns_no_endpoints(): void
    {
        $this->createSchemaFile('GenEmpty', [
            ['name' => 'name', 'phpType' => 'string'],
        ]);

        // Create a controller with an empty apiRoutes() method
        $path = $this->tempDir.'/app/Http/Controllers/Api/GenEmptyController.php';
        $content = <<<'PHP'
<?php

namespace App\Http\Controllers\Api;

class GenEmptyController
{
    public static function apiRoutes(): void
    {
    }
}
PHP;
        $this->files->put($path, $content);

        $response = $this->getJson('/_schema-craft/api/generate/stack-detail?schema=App%5CSchemas%5CGenEmptySchema');

        $response->assertOk();

        $endpoints = $response->json('endpoints');
        $this->assertCount(0, $endpoints);
    }

    public function test_config_includes_endpoint_count(): void
    {
        $this->createSchemaFile('GenAnt', [
            ['name' => 'name', 'phpType' => 'string'],
        ]);
        $this->createFakeController('GenAnt');

        $response = $this->getJson('/_schema-craft/api/generate/config');

        $response->assertOk();

        $schemas = $response->json('schemas');
        $ant = collect($schemas)->firstWhere('modelName', 'GenAnt');
        $this->assertNotNull($ant);
        $this->assertArrayHasKey('endpointCount', $ant);
        $this->assertEquals(5, $ant['endpointCount']);
    }

    public function test_config_respects_api_parameter(): void
    {
        $this->createSchemaFile('GenBee', [
            ['name' => 'name', 'phpType' => 'string'],
        ]);

        // Controller exists for default API (already created in the standard location)
        $this->createFakeController('GenBee');

        // With default API, should have controller
        $response = $this->getJson('/_schema-craft/api/generate/config?api=default');
        $schemas = $response->json('schemas');
        $bee = collect($schemas)->firstWhere('modelName', 'GenBee');
        $this->assertTrue($bee['hasController']);
    }

    // ─── Stack detail field metadata ─────────────────

    public function test_stack_detail_returns_field_metadata(): void
    {
        $this->createSchemaFile('GenCar', [
            ['name' => 'make', 'phpType' => 'string'],
            ['name' => 'year', 'phpType' => 'int', 'type' => 'integer'],
        ]);
        $this->createFakeController('GenCar');

        $response = $this->getJson('/_schema-craft/api/generate/stack-detail?schema=App%5CSchemas%5CGenCarSchema');

        $response->assertOk();
        $response->assertJsonStructure([
            'modelName',
            'routePrefix',
            'routeParam',
            'hasController',
            'hasService',
            'endpoints',
            'fields',
            'editableFields',
            'relationships',
        ]);

        // Should have fields including id, make, year, timestamps
        $fields = $response->json('fields');
        $this->assertNotEmpty($fields);

        $fieldNames = collect($fields)->pluck('name')->all();
        $this->assertContains('id', $fieldNames);
        $this->assertContains('make', $fieldNames);
        $this->assertContains('year', $fieldNames);

        // id should not be editable
        $idField = collect($fields)->firstWhere('name', 'id');
        $this->assertFalse($idField['editable']);
        $this->assertTrue($idField['primary']);

        // make should be editable
        $makeField = collect($fields)->firstWhere('name', 'make');
        $this->assertTrue($makeField['editable']);
    }

    public function test_stack_detail_returns_editable_fields_with_rules(): void
    {
        $this->createSchemaFile('GenBook', [
            ['name' => 'title', 'phpType' => 'string'],
            ['name' => 'pages', 'phpType' => 'int', 'type' => 'integer'],
        ]);
        $this->createFakeController('GenBook');

        $response = $this->getJson('/_schema-craft/api/generate/stack-detail?schema=App%5CSchemas%5CGenBookSchema');

        $response->assertOk();

        $editableFields = $response->json('editableFields');
        $this->assertNotEmpty($editableFields);

        // title should have string rules
        $titleField = collect($editableFields)->firstWhere('name', 'title');
        $this->assertNotNull($titleField);
        $this->assertStringContainsString('required', $titleField['rules']);
        $this->assertStringContainsString('string', $titleField['rules']);

        // pages should have integer rules
        $pagesField = collect($editableFields)->firstWhere('name', 'pages');
        $this->assertNotNull($pagesField);
        $this->assertStringContainsString('integer', $pagesField['rules']);

        // id and timestamps should NOT be in editable fields
        $editableNames = collect($editableFields)->pluck('name')->all();
        $this->assertNotContains('id', $editableNames);
        $this->assertNotContains('created_at', $editableNames);
        $this->assertNotContains('updated_at', $editableNames);
    }

    // ─── Action without service ──────────────────────

    public function test_action_works_without_service_file(): void
    {
        $this->createSchemaFile('GenShip', [
            ['name' => 'name', 'phpType' => 'string'],
        ]);
        $this->createFakeController('GenShip');
        // Note: no service file created

        $response = $this->postJson('/_schema-craft/api/generate/action/preview', [
            'schema' => 'App\\Schemas\\GenShipSchema',
            'action' => 'launch',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        // Should have request file + controller, but no service
        $files = $response->json('files');
        $this->assertNotEmpty($files);

        $paths = collect($files)->pluck('path')->all();
        $this->assertTrue(
            collect($paths)->contains(fn ($p) => str_contains($p, 'LaunchGenShipRequest')),
            'Should contain new request file',
        );
        $this->assertTrue(
            collect($paths)->contains(fn ($p) => str_contains($p, 'GenShipController')),
            'Should contain controller file',
        );
        // Should NOT include service file since it doesn't exist
        $this->assertFalse(
            collect($paths)->contains(fn ($p) => str_contains($p, 'Service')),
            'Should not contain service file',
        );
    }

    public function test_action_write_works_without_service_file(): void
    {
        $this->createSchemaFile('GenPlane', [
            ['name' => 'model', 'phpType' => 'string'],
        ]);
        $this->createFakeController('GenPlane');
        // Note: no service file created

        $response = $this->postJson('/_schema-craft/api/generate/action', [
            'schema' => 'App\\Schemas\\GenPlaneSchema',
            'action' => 'takeoff',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $files = $response->json('files');
        $paths = collect($files)->pluck('path')->all();

        // Should have request + controller, no service
        $this->assertTrue(
            collect($paths)->contains(fn ($p) => str_contains($p, 'TakeoffGenPlaneRequest')),
        );
        $this->assertFalse(
            collect($paths)->contains(fn ($p) => str_contains($p, 'Service')),
        );

        // Request file should exist on disk
        $requestFile = collect($files)->first(fn ($f) => str_contains($f['path'], 'Request'));
        $this->assertNotNull($requestFile);
        $this->assertFileExists($this->tempDir.'/'.$requestFile['path']);
    }

    // ─── GET /api/generate/resource-detail ───────────

    public function test_resource_detail_returns_fields_and_relationships(): void
    {
        $this->createSchemaFile('GenStar', [
            ['name' => 'name', 'phpType' => 'string'],
            ['name' => 'brightness', 'phpType' => 'int', 'type' => 'integer'],
        ]);

        $response = $this->getJson('/_schema-craft/api/generate/resource-detail?schema=App%5CSchemas%5CGenStarSchema');

        $response->assertOk();
        $response->assertJsonStructure(['modelName', 'fields', 'relationships']);
        $this->assertEquals('GenStar', $response->json('modelName'));

        $fields = $response->json('fields');
        $this->assertNotEmpty($fields);

        $fieldNames = collect($fields)->pluck('name')->all();
        $this->assertContains('id', $fieldNames);
        $this->assertContains('name', $fieldNames);
        $this->assertContains('brightness', $fieldNames);
    }

    public function test_resource_detail_returns_404_for_missing_schema(): void
    {
        $response = $this->getJson('/_schema-craft/api/generate/resource-detail?schema=App%5CSchemas%5CNonExistentSchema');

        $response->assertNotFound();
        $response->assertJson(['success' => false]);
    }

    public function test_resource_detail_does_not_require_controller(): void
    {
        $this->createSchemaFile('GenMoon', [
            ['name' => 'radius', 'phpType' => 'int', 'type' => 'integer'],
        ]);
        // Note: no controller created

        $response = $this->getJson('/_schema-craft/api/generate/resource-detail?schema=App%5CSchemas%5CGenMoonSchema');

        $response->assertOk();
        $this->assertEquals('GenMoon', $response->json('modelName'));
        $this->assertNotEmpty($response->json('fields'));
    }

    // ─── Filament install status ──────────────────────

    public function test_filament_install_status_when_not_installed(): void
    {
        $response = $this->getJson('/_schema-craft/api/filament/install-status');

        $response->assertOk();
        $response->assertJson([
            'installed' => false,
            'path' => 'app/Providers/Filament/AdminPanelProvider.php',
        ]);
    }

    public function test_filament_install_status_when_installed(): void
    {
        $this->files->ensureDirectoryExists($this->tempDir.'/app/Providers/Filament');
        $this->files->put(
            $this->tempDir.'/app/Providers/Filament/AdminPanelProvider.php',
            '<?php // stub'
        );

        $response = $this->getJson('/_schema-craft/api/filament/install-status');

        $response->assertOk();
        $response->assertJson([
            'installed' => true,
        ]);
    }
}
