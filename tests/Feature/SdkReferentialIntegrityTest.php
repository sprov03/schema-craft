<?php

namespace SchemaCraft\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use SchemaCraft\Config\ConfigResolver;
use SchemaCraft\Exceptions\SdkGenerationException;
use SchemaCraft\Generator\Sdk\SdkContextBuilder;
use SchemaCraft\Migration\SchemaDiscovery;
use SchemaCraft\SchemaCraftServiceProvider;
use SchemaCraft\Tests\Fixtures\BrokenApi\DanglingController;

/**
 * Referential integrity: a response shape that references a Resource which never got emitted
 * (here, because it fails to scan) is a dangling pointer — the generated SDK would carry a typed
 * property pointing at a DTO that doesn't exist. That must HARD-FAIL by default and only warn
 * under generate-anyway (failOnMissingRoutes=false). Run against a slim, isolated BrokenApi
 * fixture set so the dangling reference is the only thing in the build.
 */
class SdkReferentialIntegrityTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [SchemaCraftServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('schema-craft.apis.default.namespaces.controller', 'SchemaCraft\\Tests\\Fixtures\\BrokenApi');
        $app['config']->set('schema-craft.apis.default.namespaces.schema', 'SchemaCraft\\Tests\\Fixtures\\BrokenApi');
    }

    protected function defineRoutes($router): void
    {
        $router->prefix('api')->middleware('api')->group(function () {
            Route::get('dangling', [DanglingController::class, 'show']);
        });
    }

    private function build(bool $failLoud): \SchemaCraft\Generator\Sdk\SdkBuildResult
    {
        $apiConfig = ConfigResolver::resolve('default');
        $schemaClasses = (new SchemaDiscovery)->discover([dirname(__DIR__).'/Fixtures/BrokenApi']);

        return (new SdkContextBuilder)->build($apiConfig, $schemaClasses, failOnMissingRoutes: $failLoud);
    }

    public function test_dangling_nested_shape_hard_fails_by_default(): void
    {
        $this->expectException(SdkGenerationException::class);
        $this->expectExceptionMessageMatches('/UnscannableChild/');

        $this->build(true);
    }

    public function test_dangling_nested_shape_warns_under_generate_anyway(): void
    {
        $result = $this->build(false);

        $messages = implode("\n", array_map(fn ($w) => $w['message'], $result->warnings));
        $this->assertStringContainsString(
            'UnscannableChild',
            $messages,
            'expected a dangling-reference warning under generate-anyway',
        );
    }
}
