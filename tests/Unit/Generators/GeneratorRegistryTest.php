<?php

namespace SchemaCraft\Tests\Unit\Generators;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SchemaCraft\Generators\GeneratorRegistry;
use SchemaCraft\Generators\SchemaCraftGenerator;
use SchemaCraft\Tests\Fixtures\Generators\SampleGenerator;

class GeneratorRegistryTest extends TestCase
{
    private GeneratorRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new GeneratorRegistry;
    }

    // ─── register ─────────────────────────────────────────────────

    public function test_register_adds_generator(): void
    {
        $this->registry->register(SampleGenerator::class);

        $all = $this->registry->all();

        $this->assertArrayHasKey(SampleGenerator::class, $all);
        $this->assertInstanceOf(SampleGenerator::class, $all[SampleGenerator::class]);
    }

    public function test_register_throws_for_non_generator_class(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->registry->register(\stdClass::class);
    }

    // ─── all ──────────────────────────────────────────────────────

    public function test_all_returns_empty_when_no_generators_registered(): void
    {
        $this->assertSame([], $this->registry->all());
    }

    public function test_all_returns_instances_keyed_by_fqcn(): void
    {
        $this->registry->register(SampleGenerator::class);

        $all = $this->registry->all();

        $this->assertCount(1, $all);
        $this->assertInstanceOf(SchemaCraftGenerator::class, $all[SampleGenerator::class]);
    }

    // ─── resolve ──────────────────────────────────────────────────

    public function test_resolve_returns_registered_instance(): void
    {
        $this->registry->register(SampleGenerator::class);

        $generator = $this->registry->resolve(SampleGenerator::class);

        $this->assertInstanceOf(SampleGenerator::class, $generator);
    }

    public function test_resolve_throws_for_unknown_class(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not registered/');

        $this->registry->resolve(SampleGenerator::class);
    }

    // ─── discover ─────────────────────────────────────────────────

    public function test_discover_finds_generator_in_directory(): void
    {
        $dir = dirname(__DIR__, 2).'/Fixtures/Generators';
        $this->registry->discover($dir);

        $this->assertArrayHasKey(SampleGenerator::class, $this->registry->all());
    }

    public function test_discover_skips_non_php_files(): void
    {
        // Create a temp dir with a non-PHP file
        $tmpDir = sys_get_temp_dir().'/sc_generator_test_'.uniqid();
        mkdir($tmpDir);
        file_put_contents($tmpDir.'/not_a_generator.txt', 'some text');

        $this->registry->discover($tmpDir);

        $this->assertSame([], $this->registry->all());

        unlink($tmpDir.'/not_a_generator.txt');
        rmdir($tmpDir);
    }

    public function test_discover_silently_ignores_missing_directory(): void
    {
        $this->registry->discover('/path/that/does/not/exist');

        $this->assertSame([], $this->registry->all());
    }

    public function test_discover_does_not_duplicate_existing_registration(): void
    {
        $dir = dirname(__DIR__, 2).'/Fixtures/Generators';

        $this->registry->register(SampleGenerator::class);
        $this->registry->discover($dir);

        $this->assertCount(1, $this->registry->all());
    }
}
