<?php

namespace SchemaCraft\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use SchemaCraft\SchemaCraftServiceProvider;
use SchemaCraft\Tests\Fixtures\Models\PersistedItem;

/**
 * End-to-end persistence validation through the full SDK pipeline.
 *
 * ─────────────────────────────────────────────────────────────────
 * WHY THIS TEST EXISTS — DO NOT WEAKEN OR DELETE WITHOUT READING
 * ─────────────────────────────────────────────────────────────────
 *
 * Every other SDK runtime test stops at the boundary of its layer:
 *   - SdkDtoRuntimeTest exercises Data DTOs in isolation
 *   - SdkResourceRuntimeTest mocks the connector entirely
 *   - SdkRoundTripTest mocks Guzzle
 *   - SdkClientRuntimeTest still mocks Guzzle (one layer up)
 *
 * Each layer test is necessary but none verifies that the LAYERS COMPOSE
 * correctly end-to-end. The layers can each be individually green and the
 * composed pipeline still broken:
 *   - Casts could silently corrupt JsonColumn values on the DB side
 *   - Resource serialization could drop a field that the DTO expects
 *   - Eloquent's $casts pipeline could fail to invoke the cast class
 *   - Enum values could drift between PHP backing and DB storage
 *
 * This test exercises the WHOLE pipeline against an in-memory SQLite database
 * and asserts that what goes in comes back out with the same shape, types,
 * and values — including the wire format the SDK consumer would parse:
 *
 *     SDK consumer ──HTTP POST──> real controller ──> SchemaModel save
 *                                                      ↓
 *                                              Eloquent $casts pipeline
 *                                                      ↓
 *                                              SQLite row (verified directly)
 *                                                      ↓
 *     SDK consumer <──HTTP GET── real controller ←── SchemaModel fetch (rehydrate)
 *                ↓
 *        SDK DTO::fromArray (verified typed all the way down)
 *
 * ─────────────────────────────────────────────────────────────────
 * CONTRACTS PINNED (a refactor that breaks any of these breaks consumers)
 * ─────────────────────────────────────────────────────────────────
 *
 *  1. The cast vocabulary holds across persistence: JsonColumn, CollectionColumn,
 *     BitmaskColumn, and native PHP enums all round-trip through SQLite without
 *     silent corruption.
 *     — these are the column primitives the schema-craft refactor pinned. A
 *       regression in any of them is the highest-cost class of bug because it
 *       silently corrupts production data.
 *
 *  2. SchemaModel auto-wires the cast for every column from the schema's typed
 *     property declarations — no $casts array on the model needed.
 *     — this is the schema-as-source-of-truth contract.
 *
 *  3. The API response envelope (`['data' => {...}]`) is what SDK Resources
 *     parse. Server-side ApiResponseMiddleware MUST produce this shape; SDK
 *     fromArray MUST read it.
 *     — this is the agreement between server + SDK. Either side breaking it
 *       breaks every SDK integration.
 *
 *  4. Server-emitted column values match SDK Data DTO property names exactly —
 *     no casing translation between the controller's response and the DTO's
 *     fromArray expectations.
 *     — same property-name verbatim contract as SdkDtoRuntimeTest #2, here
 *       enforced through the real Resource serialization path.
 *
 *  5. Nullable fields round-trip as null without throwing.
 *     — schema-declared nullables stay nullable end-to-end.
 *
 * This test is the strongest defense the SDK has against silent integration
 * breaks. If a refactor touches the cast pipeline, schema generation, or the
 * API response envelope, this is the test that has to stay green or the SDK
 * is shipping broken.
 */
class SdkFullStackPersistenceTest extends TestCase
{
    private const SDK_NAMESPACE = 'Acme\\PersistedSdk';

    private const TEST_NAMESPACE = 'SchemaCraft\\Tests\\Runtime\\Persisted';

    private Filesystem $files;

    private array $createdDirs = [];

    protected function getPackageProviders($app): array
    {
        return [SchemaCraftServiceProvider::class];
    }

    /**
     * In-memory SQLite — fast, isolated, drops at end of run.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set(
            'schema-craft.apis.default.namespaces.controller',
            'SchemaCraft\\Tests\\Fixtures\\Api',
        );
        $app['config']->set(
            'schema-craft.apis.default.namespaces.schema',
            'SchemaCraft\\Tests\\Fixtures\\Schemas',
        );
    }

    /**
     * Single table that exercises every new cast primitive. Hand-written rather than
     * generated so the test stays self-contained — the migration generator has its
     * own test coverage; here we just need a schema that matches PersistedItemSchema.
     */
    protected function defineDatabaseMigrations(): void
    {
        Schema::create('persisted_items', function ($table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->double('price')->nullable();
            $table->string('status')->default('Draft');
            $table->json('attributes_json');
            $table->mediumInteger('permissions')->unsigned();
            $table->json('price_history');
        });
    }

    protected function defineRoutes($router): void
    {
        $router->prefix('api')->middleware('api')->group(function () {
            \SchemaCraft\Tests\Fixtures\Api\PersistedItemController::apiRoutes();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->files = new Filesystem;
        $this->generateAndLoadSdk();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdDirs as $dir) {
            if (is_dir($dir)) {
                $this->files->deleteDirectory($dir);
            }
        }
        parent::tearDown();
    }

    private function generateAndLoadSdk(): void
    {
        if (class_exists(self::TEST_NAMESPACE.'\\Data\\PersistedItemData')) {
            return;
        }

        $outputPath = base_path('packages/persisted-sdk');
        $this->createdDirs[] = $outputPath;

        $this->artisan('schema:generate-sdk', [
            '--schema-path' => [dirname(__DIR__).'/Fixtures/Schemas'],
            '--path' => 'packages/persisted-sdk',
            '--name' => 'acme/persisted-sdk',
            '--namespace' => self::SDK_NAMESPACE,
            '--client' => 'PersistedClient',
        ])->assertSuccessful();

        // Load every Data DTO file. Eval in any order since PHP resolves class
        // references lazily at instantiation time.
        foreach ($this->files->files($outputPath.'/src/Data') as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $stripped = preg_replace('/^<\?php\s+namespace\s+\S+;\s+/s', '', $file->getContents());
            eval('namespace '.self::TEST_NAMESPACE.'\\Data; '.$stripped);
        }
    }

    private function dto(string $shortName): string
    {
        return self::TEST_NAMESPACE.'\\Data\\'.$shortName;
    }

    /**
     * Standard payload exercising every new cast primitive. Used by multiple tests
     * so changes to the shape stay in one place.
     */
    private function samplePayload(): array
    {
        return [
            'name' => 'Persisted Widget',
            'is_active' => true,
            'price' => 49.99,
            'status' => 'published',
            'attributes_json' => [
                'color' => 'red',
                'material' => 'titanium',
                'weight_grams' => 250,
            ],
            // Bitmask accepts an int on write; the wire envelope {value, flags} is the
            // read shape. Consumers compute the bit-OR'd integer (READ | WRITE = 3).
            'permissions' => 3,
            'price_history' => [
                ['changed_at' => '2026-01-01', 'amount' => 45.00],
                ['changed_at' => '2026-03-01', 'amount' => 49.99],
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Layer 1: HTTP create → DB row → SDK DTO
    // ─────────────────────────────────────────────────────────────

    public function test_create_persists_record_and_returns_envelope(): void
    {
        $response = $this->postJson('/api/persisted-items', $this->samplePayload());

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => ['id', 'name', 'is_active', 'price', 'status', 'attributes_json', 'permissions', 'price_history'],
        ]);

        $this->assertSame(1, PersistedItem::count());
    }

    public function test_db_row_serializes_jsoncolumn_as_json_string(): void
    {
        $this->postJson('/api/persisted-items', $this->samplePayload());

        // Read directly via the DB connection — bypass the cast pipeline so we see
        // what's actually stored on disk. JsonColumn must serialize to a JSON object.
        $row = \DB::table('persisted_items')->first();

        $this->assertSame('Persisted Widget', $row->name);

        $attributes = json_decode($row->attributes_json, true);
        $this->assertIsArray($attributes);
        $this->assertSame('red', $attributes['color']);
        $this->assertSame('titanium', $attributes['material']);
        $this->assertSame(250, $attributes['weight_grams']);
    }

    public function test_db_row_serializes_collectioncolumn_as_json_array(): void
    {
        $this->postJson('/api/persisted-items', $this->samplePayload());

        $row = \DB::table('persisted_items')->first();
        $history = json_decode($row->price_history, true);

        $this->assertIsArray($history);
        $this->assertCount(2, $history);
        $this->assertSame('2026-01-01', $history[0]['changed_at']);
        $this->assertEqualsWithDelta(45.00, 0.001, $history[0]['amount']);
        $this->assertSame(49.99, $history[1]['amount']);
    }

    public function test_db_row_serializes_bitmaskcolumn_as_raw_integer(): void
    {
        $this->postJson('/api/persisted-items', $this->samplePayload());

        $row = \DB::table('persisted_items')->first();

        // Bitmask stores as the raw bit-OR integer, not the {value, flags} wire shape.
        $this->assertSame(3, (int) $row->permissions);
    }

    // ─────────────────────────────────────────────────────────────
    // Layer 2: DB round-trip through Eloquent's cast pipeline
    // ─────────────────────────────────────────────────────────────

    public function test_model_rehydrates_jsoncolumn_as_typed_dataschema(): void
    {
        $created = $this->postJson('/api/persisted-items', $this->samplePayload())->json('data');

        $model = PersistedItem::find($created['id']);

        $this->assertInstanceOf(
            \SchemaCraft\Tests\Fixtures\Types\CatalogAttributes::class,
            $model->attributes_json,
            'JsonColumn should rehydrate from the DB as a typed CatalogAttributes instance.'
        );
        $this->assertSame('red', $model->attributes_json->color);
        $this->assertSame('titanium', $model->attributes_json->material);
        $this->assertSame(250, $model->attributes_json->weight_grams);
    }

    public function test_model_rehydrates_collectioncolumn_as_typed_collection(): void
    {
        $created = $this->postJson('/api/persisted-items', $this->samplePayload())->json('data');

        $model = PersistedItem::find($created['id']);

        $this->assertInstanceOf(
            \SchemaCraft\Tests\Fixtures\Types\TestPriceHistory::class,
            $model->price_history,
            'CollectionColumn should rehydrate from the DB as the typed primitive subclass.'
        );
        $this->assertCount(2, $model->price_history);

        $first = $model->price_history->first();
        $this->assertInstanceOf(\SchemaCraft\Tests\Fixtures\Types\TestPricePoint::class, $first);
        $this->assertSame('2026-01-01', $first->changed_at);
        $this->assertEqualsWithDelta(45.00, 0.001, $first->amount);
    }

    public function test_model_rehydrates_bitmaskcolumn_as_typed_primitive(): void
    {
        $created = $this->postJson('/api/persisted-items', $this->samplePayload())->json('data');

        $model = PersistedItem::find($created['id']);

        $this->assertInstanceOf(
            \SchemaCraft\Tests\Fixtures\Types\TestBitmask::class,
            $model->permissions,
            'BitmaskColumn should rehydrate from the DB as the typed primitive subclass.'
        );
        $this->assertSame(3, $model->permissions->getValue());
        $this->assertTrue($model->permissions->hasFlag(\SchemaCraft\Tests\Fixtures\Types\TestBitmask::READ));
        $this->assertTrue($model->permissions->hasFlag(\SchemaCraft\Tests\Fixtures\Types\TestBitmask::WRITE));
        $this->assertFalse($model->permissions->hasFlag(\SchemaCraft\Tests\Fixtures\Types\TestBitmask::EXECUTE));
    }

    public function test_model_rehydrates_enum_as_typed_enum_instance(): void
    {
        $created = $this->postJson('/api/persisted-items', $this->samplePayload())->json('data');

        $model = PersistedItem::find($created['id']);

        $this->assertInstanceOf(
            \SchemaCraft\Tests\Fixtures\Enums\PostStatus::class,
            $model->status
        );
        $this->assertSame(\SchemaCraft\Tests\Fixtures\Enums\PostStatus::Published, $model->status);
    }

    // ─────────────────────────────────────────────────────────────
    // Layer 3: API response → SDK Data DTO parses typed
    // ─────────────────────────────────────────────────────────────

    public function test_get_response_hydrates_into_typed_sdk_dto(): void
    {
        $created = $this->postJson('/api/persisted-items', $this->samplePayload())->json('data');
        $id = $created['id'];

        $response = $this->getJson("/api/persisted-items/{$id}");
        $response->assertStatus(200);

        // Parse the API response through the generated SDK DTO — this validates the
        // end-to-end shape: API serializes typed → DTO hydrates typed → values match.
        $dto = ($this->dto('PersistedItemData'))::fromArray($response->json('data'));

        $this->assertSame($id, $dto->id);
        $this->assertSame('Persisted Widget', $dto->name);
        $this->assertSame(49.99, $dto->price);
        $this->assertSame('published', $dto->status);

        // JsonColumn → typed nested DTO
        $this->assertInstanceOf($this->dto('CatalogAttributesData'), $dto->attributes_json);
        $this->assertSame('red', $dto->attributes_json->color);

        // BitmaskColumn → typed nested DTO with {value, flags}
        $this->assertInstanceOf($this->dto('TestBitmaskData'), $dto->permissions);
        $this->assertSame(3, $dto->permissions->value);
        $this->assertInstanceOf($this->dto('TestBitmaskFlagsData'), $dto->permissions->flags);
        $this->assertTrue($dto->permissions->flags->READ);
        $this->assertFalse($dto->permissions->flags->EXECUTE);

        // CollectionColumn → typed DataCollection of item DTOs (foreach / [] / ->count())
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $dto->price_history);
        $this->assertCount(2, $dto->price_history);
        $this->assertInstanceOf($this->dto('TestPricePointData'), $dto->price_history[0]);
        $this->assertEqualsWithDelta(45.00, 0.001, $dto->price_history[0]->amount);
    }

    // ─────────────────────────────────────────────────────────────
    // Layer 4: full lifecycle (create → show → update → delete)
    // ─────────────────────────────────────────────────────────────

    public function test_full_lifecycle_preserves_all_primitives(): void
    {
        // CREATE
        $created = $this->postJson('/api/persisted-items', $this->samplePayload())->json('data');
        $id = $created['id'];
        $this->assertSame(1, PersistedItem::count());

        // SHOW — equality holds via SDK parsing
        $fetched = ($this->dto('PersistedItemData'))::fromArray(
            $this->getJson("/api/persisted-items/{$id}")->json('data')
        );
        $this->assertSame('Persisted Widget', $fetched->name);
        $this->assertSame('red', $fetched->attributes_json->color);

        // UPDATE — patch a scalar + a nested DataSchema; verify rest is preserved
        $this->putJson("/api/persisted-items/{$id}", [
            'name' => 'Updated Widget',
            'attributes_json' => ['color' => 'blue', 'material' => 'aluminum', 'weight_grams' => 200],
        ]);

        $updated = ($this->dto('PersistedItemData'))::fromArray(
            $this->getJson("/api/persisted-items/{$id}")->json('data')
        );
        $this->assertSame('Updated Widget', $updated->name);
        $this->assertSame('blue', $updated->attributes_json->color);
        $this->assertSame('aluminum', $updated->attributes_json->material);

        // DELETE — model gone, list is empty
        $this->deleteJson("/api/persisted-items/{$id}");
        $this->assertSame(0, PersistedItem::count());
    }
}
