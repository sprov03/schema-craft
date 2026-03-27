<?php

namespace SchemaCraft\Tests\Feature\Generators;

use SchemaCraft\Generator\Api\GeneratedFile;
use SchemaCraft\Generators\GeneratorRunner;
use SchemaCraft\Generators\GeneratorSchemaContext;
use SchemaCraft\Generators\SchemaCraftGenerator;
use SchemaCraft\Generators\Template;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Scanner\TableDefinition;
use SchemaCraft\Tests\TestCase;

class GeneratorRunnerTest extends TestCase
{
    private GeneratorRunner $runner;

    private string $fixtureViewDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureViewDir = dirname(__DIR__, 2).'/Fixtures/Generators';

        // Register the fixtures directory as a Blade view path
        $this->app['view']->addLocation($this->fixtureViewDir);

        $this->runner = $this->app->make(GeneratorRunner::class);
    }

    private function makeGenerator(array $templates): SchemaCraftGenerator
    {
        return new class($templates) extends SchemaCraftGenerator
        {
            public function __construct(private readonly array $tpls) {}

            public function name(): string
            {
                return 'Test Generator';
            }

            public function templates(): array
            {
                return $this->tpls;
            }
        };
    }

    private function makeSchemaContext(array $columnNames = ['name', 'email']): GeneratorSchemaContext
    {
        $columns = array_map(
            fn ($n) => new ColumnDefinition(name: $n, columnType: 'string'),
            $columnNames,
        );

        $table = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: $columns,
        );

        return new GeneratorSchemaContext($table);
    }

    // ─── Basic rendering ──────────────────────────────────────────

    public function test_run_renders_template_with_schema_context(): void
    {
        $generator = $this->makeGenerator([
            Template::file('sample-template', 'app/[class_name].php'),
        ]);

        $files = $this->runner->run(
            generator: $generator,
            schemaContexts: ['schema' => $this->makeSchemaContext()],
            inputValues: ['class_name' => 'MyClass'],
        );

        $this->assertCount(1, $files);
        $this->assertInstanceOf(GeneratedFile::class, $files[0]);
        $this->assertStringContainsString('class MyClass', $files[0]->content);
        $this->assertStringContainsString('public string $name', $files[0]->content);
        $this->assertStringContainsString('public string $email', $files[0]->content);
    }

    // ─── Output path resolution ───────────────────────────────────

    public function test_output_path_substitutes_string_input(): void
    {
        $generator = $this->makeGenerator([
            Template::file('sample-template', 'app/[class_name].php'),
        ]);

        $files = $this->runner->run(
            generator: $generator,
            schemaContexts: ['schema' => $this->makeSchemaContext()],
            inputValues: ['class_name' => 'FooService'],
        );

        $this->assertSame('app/FooService.php', $files[0]->path);
    }

    public function test_output_path_does_not_substitute_non_string_inputs(): void
    {
        $generator = $this->makeGenerator([
            Template::file('sample-template', 'app/output.php'),
        ]);

        $files = $this->runner->run(
            generator: $generator,
            schemaContexts: ['schema' => $this->makeSchemaContext()],
            inputValues: ['class_name' => 'Output', 'enabled' => true],
        );

        // 'enabled' is a boolean so it is not substituted; path stays as 'app/output.php'
        $this->assertSame('app/output.php', $files[0]->path);
    }

    // ─── Multiple templates ───────────────────────────────────────

    public function test_multiple_templates_produce_multiple_files(): void
    {
        $generator = $this->makeGenerator([
            Template::file('sample-template', 'app/[class_name].php'),
            Template::file('sample-template', 'tests/[class_name]Test.php'),
        ]);

        $files = $this->runner->run(
            generator: $generator,
            schemaContexts: ['schema' => $this->makeSchemaContext()],
            inputValues: ['class_name' => 'Foo'],
        );

        $this->assertCount(2, $files);
        $this->assertSame('app/Foo.php', $files[0]->path);
        $this->assertSame('tests/FooTest.php', $files[1]->path);
    }

    // ─── Schema context variables ─────────────────────────────────

    public function test_schema_context_name_helpers_are_accessible_in_template(): void
    {
        $generator = $this->makeGenerator([
            Template::file('sample-template', 'app/output.php'),
        ]);

        $files = $this->runner->run(
            generator: $generator,
            schemaContexts: ['schema' => $this->makeSchemaContext()],
            inputValues: ['class_name' => $this->makeSchemaContext()->ModelName],
        );

        $this->assertStringContainsString('class Post', $files[0]->content);
    }

    // ─── No schemas ───────────────────────────────────────────────

    public function test_run_works_without_schema_context(): void
    {
        $noSchemaGenerator = new class extends SchemaCraftGenerator
        {
            public function name(): string
            {
                return 'No Schema Generator';
            }

            public function schemas(): array
            {
                return [];
            }

            public function templates(): array
            {
                return [Template::file('no-schema-template', 'app/output.php')];
            }
        };

        $files = $this->runner->run(
            generator: $noSchemaGenerator,
            schemaContexts: [],
            inputValues: ['class_name' => 'Output'],
        );

        $this->assertCount(1, $files);
        $this->assertStringContainsString('class Output', $files[0]->content);
    }
}
