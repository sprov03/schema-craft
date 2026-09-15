<?php

namespace SchemaCraft\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Orchestra\Testbench\TestCase;
use SchemaCraft\SchemaCraftServiceProvider;

/**
 * Runtime validation of generated SDK Data DTOs.
 *
 * ─────────────────────────────────────────────────────────────────
 * WHY THIS TEST EXISTS — DO NOT WEAKEN OR DELETE WITHOUT READING
 * ─────────────────────────────────────────────────────────────────
 *
 * Data DTOs are what consumers actually HOLD after every API call. They are
 * the type contract the SDK exposes: $catalog->name, $catalog->attributes_json->color,
 * $catalog->price_history[0]->amount — all the way down. Every consumer's IDE
 * autocomplete, every type-checker, every dot-access in user code depends on
 * the DTO classes being shaped exactly as they're documented.
 *
 * SdkGoldenTest pins the source text via string match — but a regression can
 * emit syntactically-valid-looking PHP whose runtime behavior is broken
 * (missing inner-DTO instantiation, wrong array→DTO mapping, properties
 * declared but not assigned in fromArray, etc.). String tests miss that
 * class of regression. This test catches it by actually invoking fromArray
 * and asserting that every property is the expected typed value all the way
 * down through nested DTOs.
 *
 * This is also the test that found 3 latent generator bugs on 2026-06-02
 * (innerDtoName columns hydrated as bare arrays instead of typed instances).
 * The static metadata in golden tests said "type is CatalogAttributesData";
 * at runtime, the property held an array. Only fromArray invocation catches
 * the gap between annotation and runtime behavior.
 *
 * ─────────────────────────────────────────────────────────────────
 * CONTRACTS PINNED (a refactor that breaks any of these breaks consumers)
 * ─────────────────────────────────────────────────────────────────
 *
 *  1. Static `fromArray(array $data): self` is the hydration entry point on
 *     every generated DTO.
 *     — consumers (and the generated Resource code) call ::fromArray; renaming
 *       it breaks every consumer + every Resource simultaneously.
 *
 *  2. Wire keys === property names, verbatim — no casing translation.
 *     — wire `attributes_json` → property `$dto->attributes_json`. A regression
 *       that introduced camelCase mapping would break every consumer's
 *       property access in one stroke. (Comment in SdkDataGenerator:127 says
 *       this is the explicit design.)
 *
 *  3. Public properties on the DTO.
 *     — `$dto->property` is the consumer access pattern. `private` + getters
 *       would break every documented usage example.
 *
 *  4. Inner-DTO instantiation in fromArray:
 *     - JsonColumn field → typed `{Inner}Data` instance, not raw array
 *     - CollectionColumn field → typed Collection<int, {Inner}Data>, not raw array
 *     - BitmaskColumn field → typed `{Inner}Data` (with .value + .flags DTO)
 *     - Singular relationship → typed `{Related}Data` instance
 *     - Collection relationship → typed Collection<int, {Related}Data>
 *     — these are why consumers don't have to manually array-index nested data.
 *     — collections are a real Illuminate Collection (foreach / [] / ->first() / ->count()),
 *       not a bare array, so consumers get an explicit typed container with IDE support.
 *
 *  5. Missing-from-payload fields hydrate to `null` (no throw).
 *     — server may omit unset optional fields; SDK must accept partial payloads
 *       without exploding. Required-but-missing is also `null` (server contract
 *       enforces required, not the SDK).
 *
 *  6. BackedEnum columns hydrate as their primitive backing value (string or int),
 *     NOT a PHP-enum instance. Consumers compare with the wire constant directly.
 *     — generating the DTO field as a typed PHP enum would force every
 *       consumer codebase to import the SDK's enums; the design avoids that.
 */
class SdkDtoRuntimeTest extends TestCase
{
    private const SDK_NAMESPACE = 'Acme\\CatalogSdk';

    /**
     * Namespace the generated DTOs are eval'd into. Stable so test methods can refer
     * to specific class FQCNs (e.g. CatalogData::class) in assertions.
     */
    private const TEST_NAMESPACE = 'SchemaCraft\\Tests\\Runtime\\Sdk';

    private Filesystem $files;

    private array $createdDirs = [];

    protected function getPackageProviders($app): array
    {
        return [SchemaCraftServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set(
            'schema-craft.apis.default.namespaces.controller',
            'SchemaCraft\\Tests\\Fixtures\\Api',
        );
        $app['config']->set(
            'schema-craft.apis.default.namespaces.schema',
            'SchemaCraft\\Tests\\Fixtures\\Schemas',
        );
    }

    protected function defineRoutes($router): void
    {
        $router->prefix('api')->middleware('api')->group(function () {
            \SchemaCraft\Tests\Fixtures\Api\CatalogController::apiRoutes();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->files = new Filesystem;

        // Generate + eval once. The class_exists guards inside ensure repeated runs
        // (parallel/sequential within one process) don't trip "Cannot redeclare class."
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

    /**
     * Generate the catalog SDK once, then eval each Data DTO file into the test namespace.
     *
     * Why we rewrite the namespace at eval time: the generator emits `namespace Acme\CatalogSdk\Data`
     * which we don't want polluting the test runtime's class table (and can't load via composer
     * autoload during a unit test anyway). Stripping the namespace declaration and prepending our
     * own gives us a stable, test-owned class table to assert against.
     */
    private function generateAndLoadSdk(): void
    {
        // Sentinel class — already loaded? Skip generation + eval.
        if (class_exists(self::TEST_NAMESPACE.'\\Data\\CatalogData')) {
            return;
        }

        $outputPath = base_path('packages/catalog-sdk-runtime');
        $this->createdDirs[] = $outputPath;

        $this->artisan('schema:generate-sdk', [
            '--schema-path' => [dirname(__DIR__).'/Fixtures/Schemas'],
            '--path' => 'packages/catalog-sdk-runtime',
            '--name' => 'acme/catalog-sdk',
            '--namespace' => self::SDK_NAMESPACE,
            '--client' => 'CatalogClient',
        ])->assertSuccessful();

        // Load every Data DTO. Order doesn't matter for class definition (PHP doesn't
        // resolve references at eval time, only at instantiation), so a simple glob walk
        // works regardless of inter-DTO dependencies.
        $dataDir = $outputPath.'/src/Data';
        foreach ($this->files->files($dataDir) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $this->evalGeneratedClass($file->getContents());
        }
    }

    /**
     * Strip the generator's `<?php` + `namespace ...;` lines, then eval into the test
     * namespace. The remaining body — class declaration + methods — runs verbatim,
     * which means the SAME source the consumer would load is what we exercise here.
     */
    private function evalGeneratedClass(string $source): void
    {
        $stripped = preg_replace('/^<\?php\s+namespace\s+\S+;\s+/s', '', $source);

        // Defend against a malformed generated file (e.g., missing namespace decl) —
        // a silent eval failure on an unrecognized buffer would be worse than crashing
        // the test loudly.
        if ($stripped === $source) {
            throw new \RuntimeException(
                'Generated DTO file did not begin with the expected <?php + namespace declaration.'
            );
        }

        eval('namespace '.self::TEST_NAMESPACE.'\\Data; '.$stripped);
    }

    private function dto(string $shortName): string
    {
        return self::TEST_NAMESPACE.'\\Data\\'.$shortName;
    }

    // ─────────────────────────────────────────────────────────────
    // Layer 1: scalar columns
    // ─────────────────────────────────────────────────────────────

    public function test_scalar_columns_hydrate_with_declared_types(): void
    {
        $data = ($this->dto('CatalogData'))::fromArray([
            'id' => 42,
            'name' => 'Widget',
            'subtitle' => 'A round one',
            'is_active' => true,
            'price' => 9.99,
            'display_label' => 'Widget — 9.99',
        ]);

        $this->assertSame(42, $data->id);
        $this->assertSame('Widget', $data->name);
        $this->assertSame('A round one', $data->subtitle);
        $this->assertTrue($data->is_active);
        $this->assertSame(9.99, $data->price);
        $this->assertSame('Widget — 9.99', $data->display_label);
    }

    public function test_nullable_scalar_columns_hydrate_to_null_when_missing(): void
    {
        $data = ($this->dto('CatalogData'))::fromArray([
            'id' => 1,
            'name' => 'Minimal',
            // 'subtitle' omitted — declared nullable on CatalogResource
        ]);

        $this->assertSame(1, $data->id);
        $this->assertNull($data->subtitle);
    }

    public function test_missing_required_scalar_hydrates_to_null_without_throwing(): void
    {
        // Generator's fromArray uses isset() checks — a missing 'id' becomes null
        // even though the property is typed non-nullable. This is the current generator
        // behavior; if it ever changes to throw, this test needs updating.
        $data = ($this->dto('CatalogData'))::fromArray([
            'name' => 'NoIdYet',
        ]);

        $this->assertNull($data->id);
        $this->assertSame('NoIdYet', $data->name);
    }

    // ─────────────────────────────────────────────────────────────
    // Layer 2: BackedEnum columns
    // ─────────────────────────────────────────────────────────────

    public function test_string_backed_enum_column_hydrates_as_string(): void
    {
        // PostStatus is a string-backed enum. The SDK does not generate enum classes —
        // it emits the backing scalar as the field type. So the runtime value is just the
        // backing string, not an enum instance. The XxxOptions companion class declares
        // the valid values for consumers; the DTO field itself is a scalar.
        $data = ($this->dto('CatalogData'))::fromArray([
            'id' => 1, 'name' => 'EnumTest',
            'status' => 'Published',
        ]);

        $this->assertSame('Published', $data->status);
    }

    public function test_int_backed_enum_column_hydrates_as_int(): void
    {
        $data = ($this->dto('CatalogData'))::fromArray([
            'id' => 1, 'name' => 'EnumTest',
            'tier' => 2,
        ]);

        $this->assertSame(2, $data->tier);
    }

    // ─────────────────────────────────────────────────────────────
    // Layer 3: JsonColumn — nested DTO hydration
    // ─────────────────────────────────────────────────────────────

    public function test_json_column_field_hydrates_as_typed_nested_dto(): void
    {
        $data = ($this->dto('CatalogData'))::fromArray([
            'id' => 1, 'name' => 'Widget',
            'attributes_json' => [
                'color' => 'red',
                'material' => 'metal',
                'weight_grams' => 250,
            ],
        ]);

        $this->assertInstanceOf($this->dto('CatalogAttributesData'), $data->attributes_json);
        $this->assertSame('red', $data->attributes_json->color);
        $this->assertSame('metal', $data->attributes_json->material);
        $this->assertSame(250, $data->attributes_json->weight_grams);
    }

    public function test_json_column_field_hydrates_to_null_when_omitted(): void
    {
        $data = ($this->dto('CatalogData'))::fromArray([
            'id' => 1, 'name' => 'Widget',
            // attributes_json omitted
        ]);

        $this->assertNull($data->attributes_json);
    }

    public function test_second_json_column_field_hydrates_independently(): void
    {
        // Catalog has BOTH attributes_json AND spec — independent JsonColumn fields.
        // Verifies the generator emits separate fromArray calls for each, not one shared.
        $data = ($this->dto('CatalogData'))::fromArray([
            'id' => 1, 'name' => 'Widget',
            'spec' => ['sku' => 'WID-001', 'weight' => 250, 'material' => 'aluminum'],
        ]);

        $this->assertInstanceOf($this->dto('TestSpecData'), $data->spec);
        $this->assertSame('WID-001', $data->spec->sku);
        $this->assertSame(250, $data->spec->weight);
        $this->assertSame('aluminum', $data->spec->material);
    }

    // ─────────────────────────────────────────────────────────────
    // Layer 4: CollectionColumn — typed array hydration
    // ─────────────────────────────────────────────────────────────

    public function test_collection_column_items_hydrate_as_typed_array(): void
    {
        $data = ($this->dto('CatalogData'))::fromArray([
            'id' => 1, 'name' => 'Widget',
            'price_history' => [
                ['changed_at' => '2026-01-01', 'amount' => 8.99],
                ['changed_at' => '2026-02-01', 'amount' => 9.49],
                ['changed_at' => '2026-03-01', 'amount' => 9.99],
            ],
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $data->price_history);
        $this->assertCount(3, $data->price_history);

        foreach ($data->price_history as $point) {
            $this->assertInstanceOf($this->dto('TestPricePointData'), $point);
        }

        $this->assertSame('2026-01-01', $data->price_history[0]->changed_at);
        $this->assertSame(8.99, $data->price_history[0]->amount);
        $this->assertSame(9.99, $data->price_history[2]->amount);
    }

    public function test_empty_collection_column_hydrates_to_empty_array(): void
    {
        $data = ($this->dto('CatalogData'))::fromArray([
            'id' => 1, 'name' => 'Widget',
            'price_history' => [],
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $data->price_history);
        $this->assertCount(0, $data->price_history);
    }

    // ─────────────────────────────────────────────────────────────
    // Layer 5: BitmaskColumn — {value, flags} wire shape
    // ─────────────────────────────────────────────────────────────

    public function test_bitmask_column_hydrates_value_and_typed_flags_dto(): void
    {
        $data = ($this->dto('CatalogData'))::fromArray([
            'id' => 1, 'name' => 'Widget',
            'permissions' => [
                'value' => 3,
                'flags' => ['READ' => true, 'WRITE' => true, 'EXECUTE' => false],
            ],
        ]);

        $this->assertInstanceOf($this->dto('TestBitmaskData'), $data->permissions);
        $this->assertSame(3, $data->permissions->value);

        $this->assertInstanceOf($this->dto('TestBitmaskFlagsData'), $data->permissions->flags);
        $this->assertTrue($data->permissions->flags->READ);
        $this->assertTrue($data->permissions->flags->WRITE);
        $this->assertFalse($data->permissions->flags->EXECUTE);
    }

    // ─────────────────────────────────────────────────────────────
    // Layer 6: Resource relationships
    // ─────────────────────────────────────────────────────────────

    public function test_singular_relationship_hydrates_as_typed_dto(): void
    {
        $data = ($this->dto('CatalogData'))::fromArray([
            'id' => 1, 'name' => 'Widget',
            'brand' => ['id' => 7, 'name' => 'Acme Brands'],
        ]);

        $this->assertInstanceOf($this->dto('CatalogBrandData'), $data->brand);
        $this->assertSame(7, $data->brand->id);
        $this->assertSame('Acme Brands', $data->brand->name);
    }

    public function test_collection_relationship_hydrates_as_typed_array(): void
    {
        $data = ($this->dto('CatalogData'))::fromArray([
            'id' => 1, 'name' => 'Widget',
            'variants' => [
                ['id' => 11, 'sku' => 'V-1', 'price' => 9.99],
                ['id' => 12, 'sku' => 'V-2', 'price' => 10.99],
            ],
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $data->variants);
        $this->assertCount(2, $data->variants);

        foreach ($data->variants as $variant) {
            $this->assertInstanceOf($this->dto('CatalogVariantData'), $variant);
        }

        $this->assertSame('V-1', $data->variants[0]->sku);
        $this->assertSame('V-2', $data->variants[1]->sku);
    }

    public function test_null_relationship_hydrates_to_null(): void
    {
        $data = ($this->dto('CatalogData'))::fromArray([
            'id' => 1, 'name' => 'Widget',
            // brand and shipment omitted — both singular + declared nullable on Resource
        ]);

        // Singular relations follow documented nullability → null when absent.
        $this->assertNull($data->brand);
        $this->assertNull($data->shipment);
    }

    public function test_absent_collection_hydrates_to_empty_data_collection_not_null(): void
    {
        // The API Resource always emits a to-many (empty at worst, never whenLoaded-gated), so the
        // SDK never yields null for a collection field — an omitted collection becomes an EMPTY
        // DataCollection. Nullability is ignored for collections; consumers can always foreach safely.
        $data = ($this->dto('CatalogData'))::fromArray([
            'id' => 1, 'name' => 'Widget',
            // variants (collection relationship) and price_history (collection column) omitted
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $data->variants);
        $this->assertCount(0, $data->variants);

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $data->price_history);
        $this->assertCount(0, $data->price_history);
    }

    // ─────────────────────────────────────────────────────────────
    // Layer 7: full-depth round-trip
    // ─────────────────────────────────────────────────────────────

    public function test_full_depth_hydration_walks_every_primitive_in_one_payload(): void
    {
        // Exercises EVERY DTO type at runtime in a single fromArray() call — this is the
        // test that fails loudest if any inner-DTO instantiation regresses. If a refactor
        // breaks the array→DTO mapping for ANY primitive, this single assertion catches it.
        $payload = [
            'id' => 100,
            'name' => 'Big Widget',
            'subtitle' => 'Premium model',
            'is_active' => true,
            'price' => 49.99,
            'status' => 'Published',
            'tier' => 3,
            'display_label' => 'Big Widget — 49.99',

            'attributes_json' => [
                'color' => 'blue',
                'material' => 'titanium',
                'weight_grams' => 800,
            ],

            'spec' => [
                'sku' => 'BIG-100',
                'weight' => 800,
                'material' => 'titanium',
            ],

            'permissions' => [
                'value' => 7,
                'flags' => ['READ' => true, 'WRITE' => true, 'EXECUTE' => true],
            ],

            'price_history' => [
                ['changed_at' => '2026-01-01', 'amount' => 45.00],
                ['changed_at' => '2026-03-01', 'amount' => 49.99],
            ],

            'brand' => ['id' => 7, 'name' => 'Acme'],
            'shipment' => ['id' => 88, 'tracking_code' => 'TRK-88'],
            'variants' => [
                ['id' => 11, 'sku' => 'V-1', 'price' => 49.99],
            ],
            'reviews' => [
                ['id' => 501, 'rating' => 5, 'body' => 'great'],
            ],
        ];

        $data = ($this->dto('CatalogData'))::fromArray($payload);

        // Scalars
        $this->assertSame(100, $data->id);
        $this->assertSame('Big Widget', $data->name);
        $this->assertSame('Premium model', $data->subtitle);
        $this->assertSame(49.99, $data->price);

        // Enums
        $this->assertSame('Published', $data->status);
        $this->assertSame(3, $data->tier);

        // JsonColumn fields
        $this->assertInstanceOf($this->dto('CatalogAttributesData'), $data->attributes_json);
        $this->assertSame('blue', $data->attributes_json->color);
        $this->assertInstanceOf($this->dto('TestSpecData'), $data->spec);
        $this->assertSame('BIG-100', $data->spec->sku);

        // Bitmask
        $this->assertInstanceOf($this->dto('TestBitmaskData'), $data->permissions);
        $this->assertSame(7, $data->permissions->value);
        $this->assertTrue($data->permissions->flags->EXECUTE);

        // CollectionColumn — a real DataCollection, indexable + countable
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $data->price_history);
        $this->assertCount(2, $data->price_history);
        $this->assertInstanceOf($this->dto('TestPricePointData'), $data->price_history[0]);
        $this->assertSame(45.00, $data->price_history[0]->amount);

        // Singular relationships
        $this->assertInstanceOf($this->dto('CatalogBrandData'), $data->brand);
        $this->assertSame(7, $data->brand->id);
        $this->assertInstanceOf($this->dto('CatalogShipmentData'), $data->shipment);

        // Collection relationships — Illuminate Collections
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $data->variants);
        $this->assertCount(1, $data->variants);
        $this->assertInstanceOf($this->dto('CatalogVariantData'), $data->variants[0]);
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $data->reviews);
        $this->assertCount(1, $data->reviews);
        $this->assertInstanceOf($this->dto('CatalogReviewData'), $data->reviews[0]);

        // Computed field
        $this->assertSame('Big Widget — 49.99', $data->display_label);
    }
}
