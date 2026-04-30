<?php

namespace SchemaCraft\Tests\Feature\Generators;

use SchemaCraft\Generator\Api\GeneratedFile;
use SchemaCraft\Generators\GeneratorRunner;
use SchemaCraft\Generators\GeneratorSchemaContext;
use SchemaCraft\Generators\SchemaCraftGenerator;
use SchemaCraft\Generators\Template;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Scanner\RelationshipDefinition;
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

    private function makeGenerator(array $templates, array $templateData = []): SchemaCraftGenerator
    {
        return new class($templates, $templateData) extends SchemaCraftGenerator
        {
            public function __construct(
                private readonly array $tpls,
                private readonly array $tplData = [],
            ) {}

            public function name(): string
            {
                return 'Test Generator';
            }

            public function templates(): array
            {
                return $this->tpls;
            }

            public function templateData(): array
            {
                return $this->tplData;
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

        return new GeneratorSchemaContext($table, null, null);
    }

    private function makeSchemaContextWithRelationships(): GeneratorSchemaContext
    {
        $table = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'title', columnType: 'string'),
            ],
            relationships: [
                new RelationshipDefinition(name: 'author', type: 'belongsTo', relatedModel: 'App\\Models\\User'),
                new RelationshipDefinition(name: 'comments', type: 'hasMany', relatedModel: 'App\\Models\\Comment'),
                new RelationshipDefinition(name: 'tags', type: 'belongsToMany', relatedModel: 'App\\Models\\Tag'),
            ],
        );

        return new GeneratorSchemaContext($table, null, null);
    }

    // ─── Basic rendering ──────────────────────────────────────────

    public function test_run_renders_template_with_schema_context(): void
    {
        $generator = $this->makeGenerator([
            Template::file('app/[class_name].php', 'sample-template'),
        ]);

        $files = $this->runner->run(
            generator: $generator,
            inputValues: [
                'schema' => $this->makeSchemaContext(),
                'class_name' => 'MyClass',
            ],
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
            Template::file('app/[class_name].php', 'sample-template'),
        ]);

        $files = $this->runner->run(
            generator: $generator,
            inputValues: [
                'schema' => $this->makeSchemaContext(),
                'class_name' => 'FooService',
            ],
        );

        $this->assertSame('app/FooService.php', $files[0]->path);
    }

    public function test_output_path_resolves_chained_dot_notation(): void
    {
        $generator = $this->makeGenerator([
            Template::file('app/[schema.model.plural.title]/[schema.model.title].php', 'sample-template'),
        ]);

        $schema = new GeneratorSchemaContext(new TableDefinition(
            tableName: 'user_profiles',
            schemaClass: 'App\\Schemas\\UserProfileSchema',
            columns: [new ColumnDefinition(name: 'name', columnType: 'string')],
        ), null, null);

        $files = $this->runner->run(
            generator: $generator,
            inputValues: [
                'schema' => $schema,
                'class_name' => 'UserProfile',
            ],
        );

        $this->assertSame('app/UserProfiles/UserProfile.php', $files[0]->path);
    }

    public function test_output_path_does_not_substitute_non_string_inputs(): void
    {
        $generator = $this->makeGenerator([
            Template::file('app/output.php', 'sample-template'),
        ]);

        $files = $this->runner->run(
            generator: $generator,
            inputValues: [
                'schema' => $this->makeSchemaContext(),
                'class_name' => 'Output',
                'enabled' => true,
            ],
        );

        // 'enabled' is a boolean so it stays as-is; path is not affected
        $this->assertSame('app/output.php', $files[0]->path);
    }

    // ─── Multiple templates ───────────────────────────────────────

    public function test_multiple_templates_produce_multiple_files(): void
    {
        $generator = $this->makeGenerator([
            Template::file('app/[class_name].php', 'sample-template'),
            Template::file('tests/[class_name]Test.php', 'sample-template'),
        ]);

        $files = $this->runner->run(
            generator: $generator,
            inputValues: [
                'schema' => $this->makeSchemaContext(),
                'class_name' => 'Foo',
            ],
        );

        $this->assertCount(2, $files);
        $this->assertSame('app/Foo.php', $files[0]->path);
        $this->assertSame('tests/FooTest.php', $files[1]->path);
    }

    // ─── Schema context variables ─────────────────────────────────

    public function test_schema_model_name_chain_accessible_in_path(): void
    {
        $generator = $this->makeGenerator([
            Template::file('app/[schema.model.title].php', 'sample-template'),
        ]);

        $files = $this->runner->run(
            generator: $generator,
            inputValues: [
                'schema' => $this->makeSchemaContext(),
                'class_name' => 'Post',
            ],
        );

        $this->assertSame('app/Post.php', $files[0]->path);
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

            public function templates(): array
            {
                return [Template::file('app/output.php', 'no-schema-template')];
            }
        };

        $files = $this->runner->run(
            generator: $noSchemaGenerator,
            inputValues: ['class_name' => 'Output'],
        );

        $this->assertCount(1, $files);
        $this->assertStringContainsString('class Output', $files[0]->content);
    }

    // ─── Extra variables ──────────────────────────────────────────

    public function test_extra_variables_are_resolved_and_injected(): void
    {
        $generator = $this->makeGenerator([
            Template::file('app/output.php', 'no-schema-template', [
                'class_name' => '[base_name]Service',
            ]),
        ]);

        $files = $this->runner->run(
            generator: $generator,
            inputValues: ['base_name' => 'User'],
        );

        $this->assertStringContainsString('class UserService', $files[0]->content);
    }

    // ─── templateData() ───────────────────────────────────────────

    public function test_template_data_is_available_in_templates(): void
    {
        $generator = $this->makeGenerator(
            [Template::file('app/output.php', 'no-schema-template')],
            ['class_name' => 'FromTemplateData'],
        );

        $files = $this->runner->run(
            generator: $generator,
            inputValues: [],
        );

        $this->assertStringContainsString('class FromTemplateData', $files[0]->content);
    }

    // ─── Iteration ────────────────────────────────────────────────

    public function test_for_each_iterates_over_relationships(): void
    {
        $generator = $this->makeGenerator([
            ...Template::forEachRelationship('schema', 'relationship', [
                Template::file(
                    'app/[relationship.name.title]RelationManager.php',
                    'no-schema-template',
                    ['class_name' => '[relationship.name.title]RelationManager'],
                ),
            ]),
        ]);

        $files = $this->runner->run(
            generator: $generator,
            inputValues: ['schema' => $this->makeSchemaContextWithRelationships()],
        );

        $this->assertCount(3, $files);
        $this->assertSame('app/AuthorRelationManager.php', $files[0]->path);
        $this->assertSame('app/CommentRelationManager.php', $files[1]->path);
        $this->assertSame('app/TagRelationManager.php', $files[2]->path);
        $this->assertStringContainsString('class AuthorRelationManager', $files[0]->content);
    }

    public function test_for_each_with_collection_filter(): void
    {
        $generator = $this->makeGenerator([
            ...Template::forEachRelationship('schema', 'relationship', [
                Template::file(
                    'app/[relationship.name.title]RelationManager.php',
                    'no-schema-template',
                    ['class_name' => '[relationship.name.title]RelationManager'],
                ),
            ], 'collection'),
        ]);

        $files = $this->runner->run(
            generator: $generator,
            inputValues: ['schema' => $this->makeSchemaContextWithRelationships()],
        );

        // Only hasMany (comments) and belongsToMany (tags) — not belongsTo (author)
        $this->assertCount(2, $files);
        $this->assertSame('app/CommentRelationManager.php', $files[0]->path);
        $this->assertSame('app/TagRelationManager.php', $files[1]->path);
    }

    public function test_for_each_with_singular_filter(): void
    {
        $generator = $this->makeGenerator([
            ...Template::forEachRelationship('schema', 'relationship', [
                Template::file(
                    'app/[relationship.name.title].php',
                    'no-schema-template',
                    ['class_name' => '[relationship.name.title]'],
                ),
            ], 'singular'),
        ]);

        $files = $this->runner->run(
            generator: $generator,
            inputValues: ['schema' => $this->makeSchemaContextWithRelationships()],
        );

        // Only belongsTo (author)
        $this->assertCount(1, $files);
        $this->assertSame('app/Author.php', $files[0]->path);
    }

    public function test_for_each_skips_when_iterable_not_found(): void
    {
        $generator = $this->makeGenerator([
            ...Template::forEach('nonexistent.path', 'item', [
                Template::file('app/[item].php', 'no-schema-template'),
            ]),
        ]);

        $files = $this->runner->run(
            generator: $generator,
            inputValues: [],
        );

        $this->assertCount(0, $files);
    }

    // ─── afterRun hook ────────────────────────────────────────────

    public function test_after_run_is_called_on_actual_run(): void
    {
        $called = false;

        $generator = new class($called) extends SchemaCraftGenerator
        {
            public function __construct(private bool &$calledRef) {}

            public function name(): string
            {
                return 'Test';
            }

            public function templates(): array
            {
                return [Template::file('app/test.php', 'no-schema-template')];
            }

            public function afterRun(array $data): void
            {
                $this->calledRef = true;
            }
        };

        $this->runner->run(generator: $generator, inputValues: ['class_name' => 'Test'], writeInlineResults: true);

        $this->assertTrue($called, 'afterRun() should be called on an actual run');
    }

    public function test_after_run_is_not_called_during_preview(): void
    {
        $called = false;

        $generator = new class($called) extends SchemaCraftGenerator
        {
            public function __construct(private bool &$calledRef) {}

            public function name(): string
            {
                return 'Test';
            }

            public function templates(): array
            {
                return [Template::file('app/test.php', 'no-schema-template')];
            }

            public function afterRun(array $data): void
            {
                $this->calledRef = true;
            }
        };

        $this->runner->run(generator: $generator, inputValues: ['class_name' => 'Test'], writeInlineResults: false);

        $this->assertFalse($called, 'afterRun() should NOT be called during preview');
    }

    // ─── String wrapping ──────────────────────────────────────────

    public function test_string_inputs_are_wrapped_as_name_chains(): void
    {
        $generator = $this->makeGenerator([
            Template::file('app/[model_name.plural.title].php', 'no-schema-template'),
        ]);

        $files = $this->runner->run(
            generator: $generator,
            inputValues: [
                'model_name' => 'UserProfile',
                'class_name' => 'Test',
            ],
        );

        $this->assertSame('app/UserProfiles.php', $files[0]->path);
    }
}
