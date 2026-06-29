<?php

namespace SchemaCraft\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use SchemaCraft\SchemaCraftServiceProvider;

/**
 * Live demo + always-green reference for a custom QUERY endpoint backed by a
 * schema-craft Request.
 *
 * Exercises the whole custom-route path against a real in-memory DB:
 *
 *     HTTP POST /persisted-items/search (JSON filters)
 *        → SearchPersistedItemsRequest::fromRequest()  (validate + hydrate, typed)
 *        → optional filters → conditional where() query
 *        → PersistedItemResource collection  (typed response envelope)
 *
 * This is the "Request as input contract" counterpart to the Resource "output
 * contract" — proving a non-CRUD endpoint gets typed, validated input for free.
 */
class RequestBackedQueryTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [SchemaCraftServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

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

    /**
     * A full, valid create payload — PersistedItem's JSON/bitmask/collection columns
     * are non-nullable, so seed through the real create endpoint rather than raw inserts.
     */
    private function payload(string $name, bool $isActive, float $price): array
    {
        return [
            'name' => $name,
            'is_active' => $isActive,
            'price' => $price,
            'status' => 'published',
            'attributes_json' => ['color' => 'red', 'material' => 'steel', 'weight_grams' => 100],
            'permissions' => 3,
            'price_history' => [['changed_at' => '2026-01-01', 'amount' => $price]],
        ];
    }

    private function seedItems(): void
    {
        $this->postJson('/api/persisted-items', $this->payload('Alpha Widget', true, 10.0))->assertStatus(200);
        $this->postJson('/api/persisted-items', $this->payload('Beta Widget', true, 50.0))->assertStatus(200);
        $this->postJson('/api/persisted-items', $this->payload('Gamma Gadget', false, 30.0))->assertStatus(200);
    }

    public function test_no_filters_returns_all(): void
    {
        $this->seedItems();

        $names = array_column($this->postJson('/api/persisted-items/search', [])->json('data'), 'name');

        $this->assertCount(3, $names);
    }

    public function test_name_filter_matches_partial(): void
    {
        $this->seedItems();

        $names = array_column(
            $this->postJson('/api/persisted-items/search', ['name' => 'Widget'])->json('data'),
            'name',
        );

        sort($names);
        $this->assertSame(['Alpha Widget', 'Beta Widget'], $names);
    }

    public function test_is_active_filter_exact_match(): void
    {
        $this->seedItems();

        $names = array_column(
            $this->postJson('/api/persisted-items/search', ['is_active' => false])->json('data'),
            'name',
        );

        $this->assertSame(['Gamma Gadget'], $names);
    }

    public function test_combined_filters_compose(): void
    {
        $this->seedItems();

        // is_active = true AND price <= 30  → only Alpha (10.0); Beta (50) excluded by price.
        $data = $this->postJson('/api/persisted-items/search', [
            'is_active' => true,
            'max_price' => 30,
        ])->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('Alpha Widget', $data[0]['name']);
    }

    public function test_invalid_filter_is_rejected_with_validation_error(): void
    {
        $this->seedItems();

        // max_price is typed ?float → 'numeric'; a non-numeric value fails validation.
        $this->postJson('/api/persisted-items/search', ['max_price' => 'not-a-number'])
            ->assertStatus(422);
    }
}
