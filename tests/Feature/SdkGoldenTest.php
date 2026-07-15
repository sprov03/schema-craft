<?php

namespace SchemaCraft\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Orchestra\Testbench\TestCase;
use SchemaCraft\SchemaCraftServiceProvider;

/**
 * Golden / baseline regression test for the schema-craft SDK generator.
 *
 * This is test infrastructure: it pins the EXACT generated DTO + Resource output
 * for one cohesive demo domain (Catalog) that exercises every distinct SDK
 * generation scenario. It is the safety net for an upcoming refactor — assertions
 * capture CURRENT behavior, including a couple of suspected generator bugs that are
 * intentionally NOT fixed here (flagged with "NOTE: current behavior" below).
 *
 * Wiring (learned from the generator source, must hold for routes to resolve):
 *   - RuntimeRouteScanner maps CatalogController -> CatalogSchema by name.
 *   - The Catalog DTO is generated from CatalogResource (the resource-driven
 *     generateFromFields path) because getCollection() resolves that resource and
 *     the CLI takes the first resolving endpoint's fields as the DTO source.
 *   - Dependency-only DTOs (CatalogVariantData, etc.) are also resource-driven:
 *     EndpointEnricher::collectDepResources reaches them via the primary
 *     Resource's #[Resources\*] attributes, and they use the same
 *     generateFromFields path as the primary, with resourceFields populated
 *     from each Resource's declared shape.
 */
class SdkGoldenTest extends TestCase
{
    private Filesystem $files;

    private array $createdDirs = [];

    /** Cache of generated file contents, keyed by relative path. */
    private array $generated = [];

    protected function getPackageProviders($app): array
    {
        return [SchemaCraftServiceProvider::class];
    }

    /**
     * Point the SDK config at the Catalog fixture namespaces so RuntimeRouteScanner
     * can match the fixture controller routes to the fixture schemas.
     */
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

    /** Register the Catalog fixture routes so they're discoverable at runtime. */
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
     * Generate the Catalog SDK once and cache the file contents for assertions.
     */
    private function sdk(): array
    {
        if (! empty($this->generated)) {
            return $this->generated;
        }

        $outputPath = base_path('packages/catalog-sdk');
        $this->createdDirs[] = $outputPath;

        $this->artisan('schema:generate-sdk', [
            '--schema-path' => [dirname(__DIR__).'/Fixtures/Schemas'],
            '--path' => 'packages/catalog-sdk',
            '--name' => 'acme/catalog-sdk',
            '--namespace' => 'Acme\\CatalogSdk',
            '--client' => 'CatalogClient',
        ])->assertSuccessful();

        foreach ($this->files->allFiles($outputPath) as $f) {
            $this->generated[$f->getRelativePathname()] = $f->getContents();
        }

        return $this->generated;
    }

    private function file(string $relativePath): string
    {
        $sdk = $this->sdk();

        $this->assertArrayHasKey($relativePath, $sdk, "Expected generated file [{$relativePath}] to exist.");

        return $sdk[$relativePath];
    }

    // ─────────────────────────────────────────────────────────────
    // Scenario: which files get generated (primary Resource + Client,
    // dependency DTOs, NO dependency Resources/Client accessors).
    // ─────────────────────────────────────────────────────────────

    public function test_generated_file_set(): void
    {
        $sdk = $this->sdk();
        $paths = array_keys($sdk);

        // Primary schema gets a Resource + a DTO; client + connector + exceptions exist.
        $this->assertContains('src/Resources/CatalogResource.php', $paths);
        $this->assertContains('src/Data/CatalogData.php', $paths);
        $this->assertContains('src/CatalogClient.php', $paths);
        $this->assertContains('src/SdkConnector.php', $paths);

        // Dependency schemas reached via child relationships get DTOs only.
        $this->assertContains('src/Data/CatalogVariantData.php', $paths);   // HasMany on CatalogResource
        $this->assertContains('src/Data/CatalogShipmentData.php', $paths);  // HasOne on CatalogResource
        $this->assertContains('src/Data/CatalogSupplierData.php', $paths);  // HasMany on CatalogVariantResource (suppliers + brandSuppliers)
        $this->assertContains('src/Data/CatalogReviewData.php', $paths);    // HasMany on CatalogResource
        $this->assertContains('src/Data/ReviewableContractData.php', $paths); // schema-less contract; BelongsTo on CatalogReviewResource
        $this->assertContains('src/Data/CatalogBrandData.php', $paths);     // BelongsTo on CatalogResource (also HasOne on CatalogSupplierResource)

        // A response model referenced only by an endpoint (ActionResultResource on delete/archive)
        // is pulled into the SDK as a DTO so XData::fromArray() resolves — even though ActionResult
        // has no relationship edge and no API surface of its own.
        $this->assertContains('src/Data/ActionResultData.php', $paths);

        // ...but it is dependency-only: no Resource and no client accessor.
        $this->assertNotContains('src/Resources/ActionResultResource.php', $paths);

        // Dependencies get NO SDK Resource and NO client accessor.
        $this->assertNotContains('src/Resources/CatalogVariantResource.php', $paths);
        $this->assertNotContains('src/Resources/CatalogShipmentResource.php', $paths);

        // The manual JsonResource endpoint no longer references a CatalogManualData DTO
        // (it returns the raw array), so none is generated. (see manual() assertions).
        $this->assertNotContains('src/Data/CatalogManualData.php', $paths);
    }

    public function test_client_only_exposes_primary_resource_accessor(): void
    {
        $client = $this->file('src/CatalogClient.php');

        $this->assertStringContainsString('class CatalogClient', $client);
        $this->assertStringContainsString('public function catalogs()', $client);

        // Dependency schemas must not leak into client accessors.
        $this->assertStringNotContainsString('variants()', $client);
        $this->assertStringNotContainsString('CatalogVariantResource', $client);
        $this->assertStringNotContainsString('suppliers()', $client);
    }

    // ─────────────────────────────────────────────────────────────
    // The primary CatalogData DTO is RESOURCE-DRIVEN (generateFromFields):
    // its shape comes from CatalogResource's typed properties, computed
    // methods, and HasMany/HasOne/BelongsTo attributes — NOT schema columns.
    // ─────────────────────────────────────────────────────────────

    public function test_catalog_dto_scalar_column_types(): void
    {
        $dto = $this->file('src/Data/CatalogData.php');

        // Scenario: int column
        $this->assertStringContainsString("/** @var int */\n    public \$id;", $dto);
        // Scenario: string column (required, non-null)
        $this->assertStringContainsString("/** @var string */\n    public \$name;", $dto);
        // Scenario: nullable string column
        $this->assertStringContainsString("/** @var string|null */\n    public \$subtitle;", $dto);
        // Scenario: bool column — name served verbatim (snake_case is_active, no translation)
        $this->assertStringContainsString("/** @var bool */\n    public \$is_active;", $dto);
        // Scenario: decimal/float column
        $this->assertStringContainsString("/** @var float */\n    public \$price;", $dto);
    }

    public function test_catalog_dto_enum_column_types(): void
    {
        $dto = $this->file('src/Data/CatalogData.php');

        // Scenario: string-backed enum -> resolves to its backing type (string)
        $this->assertStringContainsString("/** @var string */\n    public \$status;", $dto);

        // Scenario: int-backed enum -> resolves to its backing type (int)
        $this->assertStringContainsString("/** @var int */\n    public \$tier;", $dto);
    }

    public function test_catalog_dto_custom_type_columns_emit_typed_nested_dtos(): void
    {
        $dto = $this->file('src/Data/CatalogData.php');

        // Scenario: bitmask type -> typed object DTO (was `array`). The shape is the
        // synthesized {value, flags} object, named after the type basename.
        $this->assertStringContainsString("/** @var TestBitmaskData */\n    public \$permissions;", $dto);
        // Scenario: JSON-DTO type -> typed object DTO backed by its DataSchema (TestSpec).
        $this->assertStringContainsString("/** @var TestSpecData */\n    public \$spec;", $dto);
        // Scenario: collection type -> typed DataCollection-of-DTO (item DataSchema is TestPricePoint),
        // served verbatim in snake_case.
        $this->assertStringContainsString("/** @var DataCollection<TestPricePointData> */\n    public \$price_history;", $dto);
        // Scenario: json column with a declared shape -> typed CatalogAttributesData (not bare array).
        $this->assertStringContainsString("/** @var CatalogAttributesData */\n    public \$attributes_json;", $dto);
    }

    public function test_custom_type_inner_dto_files_are_generated(): void
    {
        $sdk = $this->sdk();
        $paths = array_keys($sdk);

        // Each rich column type now emits a typed nested DTO file, deduped by name across
        // the whole SDK. These are dependency-only (no Resource, no client accessor).
        $this->assertContains('src/Data/TestBitmaskData.php', $paths);       // bitmask wrapper
        $this->assertContains('src/Data/TestBitmaskFlagsData.php', $paths);  // bitmask flags sub-object
        $this->assertContains('src/Data/TestSpecData.php', $paths);          // json-dto object (TestSpec)
        $this->assertContains('src/Data/TestPricePointData.php', $paths);    // collection item (TestPricePoint)
        $this->assertContains('src/Data/CatalogAttributesData.php', $paths); // json column shape (CatalogAttributes)

        // Inner DTOs are dependency-only — no Resource files for them.
        $this->assertNotContains('src/Resources/TestBitmaskResource.php', $paths);
        $this->assertNotContains('src/Resources/TestSpecResource.php', $paths);
        $this->assertNotContains('src/Resources/TestPricePointResource.php', $paths);
    }

    public function test_bitmask_inner_dto_is_fully_typed_value_and_flags(): void
    {
        // The bitmask wrapper DTO is {value: int, flags: TestBitmaskFlagsData} — documented
        // to the bottom rather than collapsed to `array`.
        $wrapper = $this->file('src/Data/TestBitmaskData.php');
        $this->assertStringContainsString('class TestBitmaskData', $wrapper);
        $this->assertStringContainsString("/** @var int */\n    public \$value;", $wrapper);
        $this->assertStringContainsString("/** @var TestBitmaskFlagsData */\n    public \$flags;", $wrapper);

        // The flags sub-object has one bool field per declared flag (READ/WRITE/EXECUTE).
        $flags = $this->file('src/Data/TestBitmaskFlagsData.php');
        $this->assertStringContainsString('class TestBitmaskFlagsData', $flags);
        $this->assertStringContainsString("/** @var bool */\n    public \$READ;", $flags);
        $this->assertStringContainsString("/** @var bool */\n    public \$WRITE;", $flags);
        $this->assertStringContainsString("/** @var bool */\n    public \$EXECUTE;", $flags);
    }

    public function test_json_dto_inner_dto_reflects_backing_data_schema(): void
    {
        // The JSON-DTO type's object shape comes from its backing DataSchema (TestSpec),
        // reflected into typed fields (string/int/nullable string) — not a bare `array`.
        $spec = $this->file('src/Data/TestSpecData.php');
        $this->assertStringContainsString('class TestSpecData', $spec);
        $this->assertStringContainsString("/** @var string */\n    public \$sku;", $spec);
        $this->assertStringContainsString("/** @var int */\n    public \$weight;", $spec);
        $this->assertStringContainsString("/** @var string|null */\n    public \$material;", $spec);
    }

    public function test_collection_item_inner_dto_reflects_item_data_schema(): void
    {
        // The collection type's item DTO comes from its item DataSchema (TestPricePoint).
        $item = $this->file('src/Data/TestPricePointData.php');
        $this->assertStringContainsString('class TestPricePointData', $item);
        $this->assertStringContainsString("/** @var string */\n    public \$changed_at;", $item);
        $this->assertStringContainsString("/** @var float */\n    public \$amount;", $item);
    }

    public function test_catalog_dto_computed_field(): void
    {
        $dto = $this->file('src/Data/CatalogData.php');

        // Scenario: #[Computed] method displayLabel() -> snake_cased key (display_label),
        // return-type string. Now consistent with the plain columns, which are also served
        // verbatim in snake_case — one naming convention throughout the DTO.
        $this->assertStringContainsString("/** @var string */\n    public \$display_label;", $dto);
    }

    public function test_catalog_dto_hidden_column_excluded(): void
    {
        $dto = $this->file('src/Data/CatalogData.php');

        // Scenario: #[Hidden] internal_notes is never declared on the resource and
        // never appears in the DTO. (Resource-driven path only emits declared fields.)
        $this->assertStringNotContainsString('internalNotes', $dto);
        $this->assertStringNotContainsString('internal_notes', $dto);
    }

    public function test_catalog_dto_resource_relationships(): void
    {
        $dto = $this->file('src/Data/CatalogData.php');

        // Scenario: resource BelongsTo -> single nested DTO, nullable
        $this->assertStringContainsString("/** @var CatalogBrandData|null */\n    public \$brand;", $dto);
        // Scenario: resource HasMany -> array of DTOs, nullable
        $this->assertStringContainsString("/** @var DataCollection<CatalogVariantData> */\n    public \$variants;", $dto);
        // Scenario: resource HasOne -> single nested DTO, nullable
        $this->assertStringContainsString("/** @var CatalogShipmentData|null */\n    public \$shipment;", $dto);

        // fromArray hydration: collection maps each item; singles hydrate directly.
        $this->assertStringContainsString(
            "isset(\$data['variants']) ? new DataCollection(array_map(function (array \$item) { return CatalogVariantData::fromArray(\$item); }, \$data['variants'])) : new DataCollection()",
            $dto,
        );
        $this->assertStringContainsString(
            "isset(\$data['brand']) ? CatalogBrandData::fromArray(\$data['brand']) : null",
            $dto,
        );
        $this->assertStringContainsString(
            "isset(\$data['shipment']) ? CatalogShipmentData::fromArray(\$data['shipment']) : null",
            $dto,
        );
    }

    public function test_catalog_dto_does_not_include_timestamps_in_resource_path(): void
    {
        $dto = $this->file('src/Data/CatalogData.php');

        // Although CatalogSchema uses Timestamps + SoftDeletes, the RESOURCE-driven DTO emits
        // only fields declared on CatalogResource, which does not declare
        // created_at/updated_at/deleted_at, so they are ABSENT here. They DO appear in
        // CatalogShipmentData because CatalogShipmentResource declares them explicitly
        // (see CatalogShipmentData test).
        $this->assertStringNotContainsString('created_at', $dto);
        $this->assertStringNotContainsString('updated_at', $dto);
        $this->assertStringNotContainsString('deleted_at', $dto);
    }

    // ─────────────────────────────────────────────────────────────
    // The CatalogResource (SDK client resource) — one method per route,
    // verbatim names, response shape derived per endpoint.
    // ─────────────────────────────────────────────────────────────

    public function test_resource_documented_collection_response(): void
    {
        $res = $this->file('src/Resources/CatalogResource.php');

        // Scenario: documented collection response (#[ApiResponse collection: true], GET no {id}).
        $this->assertStringContainsString('     * @return Collection<int, CatalogData>', $res);
        $this->assertStringContainsString('    public function getCollection()', $res);
        $this->assertStringContainsString("\$response = \$this->connector->get('api/catalog');", $res);
        $this->assertStringContainsString('return collect($response[\'data\'])->map(function (array $item) {', $res);
        $this->assertStringContainsString('return CatalogData::fromArray($item);', $res);
    }

    public function test_resource_documented_single_response_via_return_type(): void
    {
        $res = $this->file('src/Resources/CatalogResource.php');

        // Scenario: documented single response via typed JsonResource return hint (GET with {param}).
        // The route param is {catalog}, so the method binds a $catalog param (int|string) that the
        // path interpolation consumes — no more undefined variable.
        $this->assertStringContainsString('     * @param int|string $catalog', $res);
        $this->assertStringContainsString("     * @return CatalogData\n     */\n    public function get(\$catalog)", $res);
        $this->assertStringContainsString('$response = $this->connector->get("api/catalog/{$catalog}");', $res);
        $this->assertStringContainsString('return CatalogData::fromArray($response[\'data\']);', $res);
    }

    public function test_resource_create_with_body_from_form_request(): void
    {
        $res = $this->file('src/Resources/CatalogResource.php');

        // Scenario: create with body — params come from CreateCatalogRequest rules,
        // type-inferred (integer -> int, boolean -> bool, numeric -> float), documented single response.
        $this->assertStringContainsString('    public function create(', $res);
        // Required params (name, is_active, price, tier) come first in stable rule order,
        // then the optional ones — a valid PHP signature (no required-after-optional).
        // Param names are the snake_case wire keys (no casing translation).
        $this->assertStringContainsString('        $name,', $res);
        $this->assertStringContainsString('        $is_active,', $res);
        $this->assertStringContainsString('        $price,', $res);
        $this->assertStringContainsString('        $tier,', $res);
        $this->assertStringContainsString('        $subtitle = null,', $res);
        $this->assertStringContainsString('        $attributes_json = null,', $res);
        $this->assertStringContainsString('        $price_history = null', $res);

        // boolean rule now infers 'bool' and integer infers 'int' — both documented correctly.
        $this->assertStringContainsString('     * @param bool $is_active', $res);
        $this->assertStringContainsString('     * @param int $tier', $res);
        $this->assertStringContainsString('     * @param float $price', $res);

        // Body keys are the snake_case rule field names; param names match them verbatim.
        $this->assertStringContainsString("\$response = \$this->connector->post('api/catalog', [", $res);
        $this->assertStringContainsString("'is_active' => \$is_active,", $res);
        $this->assertStringContainsString("'attributes_json' => \$attributes_json,", $res);
        $this->assertStringContainsString("'price_history' => \$price_history,", $res);
        $this->assertStringContainsString('return CatalogData::fromArray($response[\'data\']);', $res);
    }

    public function test_resource_update_with_body(): void
    {
        $res = $this->file('src/Resources/CatalogResource.php');

        // Scenario: update with body (all nullable rules -> all optional params), documented single.
        // The {catalog} path param leads the signature, before the optional body params.
        $this->assertStringContainsString('    public function update($catalog, $name = null, $price = null)', $res);
        $this->assertStringContainsString('$response = $this->connector->put("api/catalog/{$catalog}", [', $res);
        $this->assertStringContainsString("'name' => \$name,", $res);
        $this->assertStringContainsString("'price' => \$price,", $res);
        $this->assertStringContainsString('return CatalogData::fromArray($response[\'data\']);', $res);
    }

    public function test_resource_void_delete(): void
    {
        $res = $this->file('src/Resources/CatalogResource.php');

        // Scenario: DELETE documented with #[ApiResponse(ActionResultResource)] -> returns a
        // structured ActionResultData instead of void. {catalog} path param is bound on the method.
        $this->assertStringContainsString("     * @return ActionResultData\n     */\n    public function delete(\$catalog)", $res);
        // No body params, so DELETE flows through the has-response/empty-body branch: it still
        // passes an empty [] body argument, then hydrates the structured result DTO.
        $this->assertStringContainsString('$response = $this->connector->delete("api/catalog/{$catalog}", []);', $res);
        $this->assertStringContainsString("return ActionResultData::fromArray(\$response['data']);", $res);
    }

    public function test_resource_custom_void_action(): void
    {
        $res = $this->file('src/Resources/CatalogResource.php');

        // Scenario: custom action POST catalog/{catalog}/archive documented with
        // #[ApiResponse(ActionResultResource)] -> returns ActionResultData (was void before).
        $this->assertStringContainsString("     * @return ActionResultData\n     */\n    public function archive(\$catalog)", $res);
        $this->assertStringContainsString('$response = $this->connector->post("api/catalog/{$catalog}/archive", []);', $res);
        $this->assertStringContainsString("return ActionResultData::fromArray(\$response['data']);", $res);
    }

    public function test_resource_undocumented_method(): void
    {
        $res = $this->file('src/Resources/CatalogResource.php');

        // Scenario: the one UNDOCUMENTED method (report — no return type, no #[ApiResponse]) is now
        // FILTERED OUT of the SDK entirely by SdkContextBuilder. The generated Resource has no
        // report() method at all, and a warning was emitted for it (asserted in
        // test_undocumented_endpoint_emits_warning). No phantom DTO is referenced either.
        $this->assertStringNotContainsString('public function report(', $res);
        $this->assertStringNotContainsString('report', $res);
        $this->assertStringNotContainsString('ReportData', $res);
    }

    public function test_undocumented_endpoint_emits_warning(): void
    {
        // The CatalogController->report() route has no #[ApiResponse] and no typed resource return,
        // so it is excluded from the SDK. The CLI surfaces that exclusion as a console warning so an
        // author notices a route silently fell out of the generated client.
        $outputPath = base_path('packages/catalog-sdk-warn');
        $this->createdDirs[] = $outputPath;

        $this->artisan('schema:generate-sdk', [
            '--schema-path' => [dirname(__DIR__).'/Fixtures/Schemas'],
            '--path' => 'packages/catalog-sdk-warn',
            '--name' => 'acme/catalog-sdk',
            '--namespace' => 'Acme\\CatalogSdk',
            '--client' => 'CatalogClient',
        ])
            ->expectsOutputToContain('GET /api/catalog/{catalog}/report: response is undocumented')
            ->assertSuccessful();
    }

    public function test_resource_manual_json_resource_method(): void
    {
        $res = $this->file('src/Resources/CatalogResource.php');

        // Scenario: manual JsonResource (opaque toArray) — the scanner records the resource as
        // responseManualResource (no introspectable fields) and NO responseModelName, so the
        // method returns the raw decoded data array instead of a phantom CatalogManualData DTO.
        $this->assertStringContainsString('     * @return array', $res);
        $this->assertStringContainsString('    public function manual($catalog)', $res);
        $this->assertStringContainsString('$response = $this->connector->get("api/catalog/{$catalog}/manual");', $res);
        $this->assertStringContainsString('return $response[\'data\'];', $res);

        // The phantom DTO must never be referenced anywhere in the resource.
        $this->assertStringNotContainsString('CatalogManualData', $res);
    }

    // ─────────────────────────────────────────────────────────────
    // Dependency DTOs use the SCHEMA-DRIVEN generate() path. This is where
    // the relationship collection/singular/mixed mapping is observable, and
    // where the suspected belongsToMany/hasManyThrough divergence lives.
    // ─────────────────────────────────────────────────────────────

    public function test_dependency_dto_relationship_shapes(): void
    {
        $dto = $this->file('src/Data/CatalogVariantData.php');

        // Scenario: hasOne -> singular DTO
        $this->assertStringContainsString("/** @var CatalogShipmentData|null */\n    public \$shipment;", $dto);
        // Scenario: morphMany -> collection of DTOs
        $this->assertStringContainsString("/** @var DataCollection<CatalogReviewData> */\n    public \$reviews;", $dto);

        // Scenario: belongsToMany — a COLLECTION in the shared cardinality rules (unchanged).
        $this->assertStringContainsString("/** @var DataCollection<CatalogSupplierData> */\n    public \$suppliers;", $dto);

        // Scenario: hasManyThrough — a COLLECTION at the Resource layer via the shared
        // SdkRelationshipCardinality. DB-level cardinality (belongsToMany / hasManyThrough)
        // collapses to "collection of X" on the Resource side.
        $this->assertStringContainsString("/** @var DataCollection<CatalogSupplierData> */\n    public \$brandSuppliers;", $dto);

        // Hydration mirrors the declared shapes — both collections array_map their items.
        $this->assertStringContainsString(
            "isset(\$data['suppliers']) ? new DataCollection(array_map(function (array \$item) { return CatalogSupplierData::fromArray(\$item); }, \$data['suppliers'])) : new DataCollection()",
            $dto,
        );
        $this->assertStringContainsString(
            "isset(\$data['brandSuppliers']) ? new DataCollection(array_map(function (array \$item) { return CatalogSupplierData::fromArray(\$item); }, \$data['brandSuppliers'])) : new DataCollection()",
            $dto,
        );
    }

    public function test_dependency_dto_morph_to_and_morph_fk_columns(): void
    {
        $dto = $this->file('src/Data/CatalogReviewData.php');

        // Post Resource-walked cutover: morphTo `reviewable` is typed against the contract Resource
        // (ReviewableContractResource — schema-less, declares the common-fields shape). Discriminated
        // unions are the principled fix that closes the contract-Resource trust gap (see the
        // discriminated-union-sdk-types task).
        $this->assertStringContainsString("/** @var ReviewableContractData|null */\n    public \$reviewable;", $dto);

        // The morph FK columns surface as plain scalar columns (string type + int id),
        // served verbatim in snake_case.
        $this->assertStringContainsString("/** @var string */\n    public \$reviewable_type;", $dto);
        $this->assertStringContainsString("/** @var int */\n    public \$reviewable_id;", $dto);
    }

    public function test_dependency_dto_timestamps_and_soft_deletes(): void
    {
        $dto = $this->file('src/Data/CatalogShipmentData.php');

        // Scenario: timestamps + soft-deletes declared on CatalogShipmentResource — declared columns ship verbatim; no auto-injection from schema traits.
        // All emitted as string|null with default null, served verbatim in snake_case.
        $this->assertStringContainsString("/** @var string|null */\n    public \$created_at;", $dto);
        $this->assertStringContainsString("/** @var string|null */\n    public \$updated_at;", $dto);
        $this->assertStringContainsString("/** @var string|null */\n    public \$deleted_at;", $dto);

        // Scenario: date/datetime column (?CarbonInterface shipped_at) -> string|null.
        $this->assertStringContainsString("/** @var string|null */\n    public \$shipped_at;", $dto);
    }

    public function test_action_result_dto_is_a_clean_scalar_dto(): void
    {
        // The ActionResult DTO is generated schema-driven off ActionResultSchema (success/message),
        // which is the minimum backing needed for delete/archive to return a structured result.
        $dto = $this->file('src/Data/ActionResultData.php');

        $this->assertStringContainsString('class ActionResultData', $dto);
        $this->assertStringContainsString("/** @var bool */\n    public \$success;", $dto);
        $this->assertStringContainsString("/** @var string */\n    public \$message;", $dto);
    }

    public function test_dependency_dto_belongs_to_is_fk_only_not_nested(): void
    {
        // CatalogSupplierSchema hasOne CatalogBrand -> a nested CatalogBrandData on the supplier DTO,
        // which is what pulls CatalogBrandData into the SDK at all (belongsTo/hasManyThrough never would).
        $supplier = $this->file('src/Data/CatalogSupplierData.php');
        $this->assertStringContainsString("/** @var CatalogBrandData|null */\n    public \$brand;", $supplier);

        // The pulled-in CatalogBrandData is a clean scalar DTO.
        $brand = $this->file('src/Data/CatalogBrandData.php');
        $this->assertStringContainsString('class CatalogBrandData', $brand);
        $this->assertStringContainsString("/** @var int */\n    public \$id;", $brand);
        $this->assertStringContainsString("/** @var string */\n    public \$name;", $brand);
    }
}
