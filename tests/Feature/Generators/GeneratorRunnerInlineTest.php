<?php

namespace SchemaCraft\Tests\Feature\Generators;

use SchemaCraft\Generator\Api\InlineGeneratedFile;
use SchemaCraft\Generators\GeneratorRunner;
use SchemaCraft\Generators\InlineTemplate;
use SchemaCraft\Generators\SchemaCraftGenerator;
use SchemaCraft\Tests\TestCase;

class GeneratorRunnerInlineTest extends TestCase
{
    private GeneratorRunner $runner;

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $fixtureViewDir = dirname(__DIR__, 2).'/Fixtures/Generators';
        $this->app['view']->addLocation($fixtureViewDir);

        $this->runner = $this->app->make(GeneratorRunner::class);
        $this->tmpDir = base_path('storage/app/inline-test-'.uniqid());
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir.'/*'));
        rmdir($this->tmpDir);
        parent::tearDown();
    }

    private function writeTmpFile(string $name, string $content): string
    {
        $path = $this->tmpDir.'/'.$name;
        file_put_contents($path, $content);

        return $path;
    }

    private function relPath(string $absPath): string
    {
        return ltrim(str_replace(base_path(), '', $absPath), '/');
    }

    private function makeInlineGenerator(array $inlineTemplates): SchemaCraftGenerator
    {
        return new class($inlineTemplates) extends SchemaCraftGenerator
        {
            public function __construct(private readonly array $tpls) {}

            public function name(): string
            {
                return 'Inline Test Generator';
            }

            public function templates(): array
            {
                return [];
            }

            public function inlineTemplates(array $data): array
            {
                return $this->tpls;
            }
        };
    }

    // ─── file_not_found ───────────────────────────────────────────────────────

    public function test_skips_when_target_file_does_not_exist(): void
    {
        $generator = $this->makeInlineGenerator([
            InlineTemplate::make('inline-snippet')
                ->into('nonexistent/path/file.php')
                ->after('class Foo {'),
        ]);

        $results = $this->runner->run($generator, ['method_name' => 'boot']);

        $this->assertCount(1, $results);
        $this->assertInstanceOf(InlineGeneratedFile::class, $results[0]);
        $this->assertTrue($results[0]->skipped);
        $this->assertSame('file_not_found', $results[0]->skipReason);
    }

    // ─── already_present ─────────────────────────────────────────────────────

    public function test_skips_when_snippet_already_present(): void
    {
        $absPath = $this->writeTmpFile('AlreadyPresent.php', "<?php\nclass Foo {\n    public function boot(): void {}\n}\n");
        $relPath = $this->relPath($absPath);

        $generator = $this->makeInlineGenerator([
            InlineTemplate::make('inline-snippet')
                ->into($relPath)
                ->after('class Foo {'),
        ]);

        $results = $this->runner->run($generator, ['method_name' => 'boot']);

        $this->assertTrue($results[0]->skipped);
        $this->assertSame('already_present', $results[0]->skipReason);
    }

    // ─── after insertion (literal) ────────────────────────────────────────────

    public function test_inserts_after_literal_pattern(): void
    {
        $absPath = $this->writeTmpFile('Target.php', "<?php\nclass Foo {\n}\n");
        $relPath = $this->relPath($absPath);

        $generator = $this->makeInlineGenerator([
            InlineTemplate::make('inline-snippet')
                ->into($relPath)
                ->after("class Foo {\n"),
        ]);

        $results = $this->runner->run($generator, ['method_name' => 'boot']);

        $this->assertFalse($results[0]->skipped);
        $this->assertStringContainsString('public function boot(): void {}', $results[0]->content);
    }

    // ─── before insertion (literal) ───────────────────────────────────────────

    public function test_inserts_before_literal_pattern(): void
    {
        $absPath = $this->writeTmpFile('Target.php', "<?php\nclass Foo {\n}\n");
        $relPath = $this->relPath($absPath);

        $generator = $this->makeInlineGenerator([
            InlineTemplate::make('inline-snippet')
                ->into($relPath)
                ->before("}\n"),
        ]);

        $results = $this->runner->run($generator, ['method_name' => 'register']);

        $this->assertFalse($results[0]->skipped);
        $modified = $results[0]->content;
        $this->assertStringContainsString('public function register(): void {}', $modified);
        // Snippet must appear before closing brace
        $this->assertLessThan(strrpos($modified, '}'), strpos($modified, 'register'));
    }

    // ─── pattern_not_found ────────────────────────────────────────────────────

    public function test_skips_when_pattern_not_found(): void
    {
        $absPath = $this->writeTmpFile('Target.php', "<?php\nclass Foo {}\n");
        $relPath = $this->relPath($absPath);

        $generator = $this->makeInlineGenerator([
            InlineTemplate::make('inline-snippet')
                ->into($relPath)
                ->after('this pattern does not exist'),
        ]);

        $results = $this->runner->run($generator, ['method_name' => 'boot']);

        $this->assertTrue($results[0]->skipped);
        $this->assertSame('pattern_not_found', $results[0]->skipReason);
    }

    // ─── anchor + pattern disambiguation ─────────────────────────────────────

    public function test_anchor_disambiguates_repeated_pattern(): void
    {
        $content = "<?php\nclass Foo {\n    public function boot() {\n        return [\n        ];\n    }\n    public function register() {\n        return [\n        ];\n    }\n}\n";
        $absPath = $this->writeTmpFile('Target.php', $content);
        $relPath = $this->relPath($absPath);

        $generator = $this->makeInlineGenerator([
            InlineTemplate::make('inline-snippet')
                ->into($relPath)
                ->anchor('public function register()')
                ->after("return [\n"),
        ]);

        $results = $this->runner->run($generator, ['method_name' => 'myEntry']);

        $this->assertFalse($results[0]->skipped);
        $modified = $results[0]->content;
        // Insertion should be after second 'return [', inside register()
        $registerPos = strpos($modified, 'public function register()');
        $insertionPos = strpos($modified, 'public function myEntry(): void {}');
        $this->assertGreaterThan($registerPos, $insertionPos);
    }

    // ─── anchor_not_found ────────────────────────────────────────────────────

    public function test_skips_when_anchor_not_found(): void
    {
        $absPath = $this->writeTmpFile('Target.php', "<?php\nclass Foo {\n}\n");
        $relPath = $this->relPath($absPath);

        $generator = $this->makeInlineGenerator([
            InlineTemplate::make('inline-snippet')
                ->into($relPath)
                ->anchor('this anchor does not exist')
                ->after('class Foo {'),
        ]);

        $results = $this->runner->run($generator, ['method_name' => 'boot']);

        $this->assertTrue($results[0]->skipped);
        $this->assertSame('anchor_not_found', $results[0]->skipReason);
    }

    // ─── append mode ─────────────────────────────────────────────────────────

    public function test_append_adds_snippet_to_end_of_file(): void
    {
        $absPath = $this->writeTmpFile('Target.php', "<?php\nclass Foo {}\n");
        $relPath = $this->relPath($absPath);

        $generator = $this->makeInlineGenerator([
            InlineTemplate::make('inline-snippet')
                ->into($relPath)
                ->append(),
        ]);

        $results = $this->runner->run($generator, ['method_name' => 'boot']);

        $this->assertFalse($results[0]->skipped);
        $this->assertStringContainsString('public function boot(): void {}', $results[0]->content);
        // Snippet must be after the original file content
        $this->assertGreaterThan(strpos($results[0]->content, 'class Foo'), strrpos($results[0]->content, 'boot'));
    }

    // ─── prepend mode ────────────────────────────────────────────────────────

    public function test_prepend_adds_snippet_to_start_of_file(): void
    {
        $absPath = $this->writeTmpFile('Target.php', "<?php\nclass Foo {}\n");
        $relPath = $this->relPath($absPath);

        $generator = $this->makeInlineGenerator([
            InlineTemplate::make('inline-snippet')
                ->into($relPath)
                ->prepend(),
        ]);

        $results = $this->runner->run($generator, ['method_name' => 'boot']);

        $this->assertFalse($results[0]->skipped);
        $this->assertStringContainsString('public function boot(): void {}', $results[0]->content);
        // Snippet must be before the original file content
        $this->assertLessThan(strpos($results[0]->content, 'class Foo'), strpos($results[0]->content, 'boot'));
    }

    // ─── regex modes ─────────────────────────────────────────────────────────

    public function test_after_regex_inserts_after_first_match(): void
    {
        $absPath = $this->writeTmpFile('Target.php', "<?php\nclass Foo {\n}\n");
        $relPath = $this->relPath($absPath);

        $generator = $this->makeInlineGenerator([
            InlineTemplate::make('inline-snippet')
                ->into($relPath)
                ->afterRegex('/class\s+\w+\s*\{/'),
        ]);

        $results = $this->runner->run($generator, ['method_name' => 'boot']);

        $this->assertFalse($results[0]->skipped);
        $modified = $results[0]->content;
        $this->assertStringContainsString('public function boot(): void {}', $modified);
        // Snippet must appear inside the class body
        $classOpenPos = strpos($modified, 'class Foo {');
        $insertionPos = strpos($modified, 'public function boot(): void {}');
        $this->assertGreaterThan($classOpenPos, $insertionPos);
    }

    public function test_before_regex_inserts_before_first_match(): void
    {
        $absPath = $this->writeTmpFile('Target.php', "<?php\nclass Foo {\n}\n");
        $relPath = $this->relPath($absPath);

        $generator = $this->makeInlineGenerator([
            InlineTemplate::make('inline-snippet')
                ->into($relPath)
                ->beforeRegex('/^}/m'),
        ]);

        $results = $this->runner->run($generator, ['method_name' => 'boot']);

        $this->assertFalse($results[0]->skipped);
        $modified = $results[0]->content;
        $insertionPos = strpos($modified, 'public function boot(): void {}');
        $closingBracePos = strrpos($modified, '}');
        $this->assertLessThan($closingBracePos, $insertionPos);
    }

    // ─── bracket resolution in patterns ──────────────────────────────────────

    public function test_bracket_placeholders_resolved_in_pattern(): void
    {
        $absPath = $this->writeTmpFile('Target.php', "<?php\nclass Foo {\n    public function boot() {}\n}\n");
        $relPath = $this->relPath($absPath);

        $generator = $this->makeInlineGenerator([
            InlineTemplate::make('inline-snippet')
                ->into($relPath)
                ->after('public function [existing_method]() {}'),
        ]);

        $results = $this->runner->run($generator, [
            'method_name' => 'register',
            'existing_method' => 'boot',
        ]);

        $this->assertFalse($results[0]->skipped);
        $this->assertStringContainsString('public function register(): void {}', $results[0]->content);
    }

    public function test_bracket_placeholders_resolved_in_anchor(): void
    {
        $content = "<?php\nclass Foo {\n    public function boot() {\n        return [];\n    }\n}\n";
        $absPath = $this->writeTmpFile('Target.php', $content);
        $relPath = $this->relPath($absPath);

        $generator = $this->makeInlineGenerator([
            InlineTemplate::make('inline-snippet')
                ->into($relPath)
                ->anchor('public function [anchor_method]()')
                ->after('return ['),
        ]);

        $results = $this->runner->run($generator, [
            'method_name' => 'myEntry',
            'anchor_method' => 'boot',
        ]);

        $this->assertFalse($results[0]->skipped);
        $this->assertStringContainsString('public function myEntry(): void {}', $results[0]->content);
    }

    // ─── Same-file multiple insertions (compound) ─────────────────────────────

    public function test_multiple_insertions_into_same_file_compound_correctly_in_preview(): void
    {
        $absPath = $this->writeTmpFile('Target.php', "<?php\nclass Foo {\n}\n");
        $relPath = $this->relPath($absPath);

        $generator = $this->makeInlineGenerator([
            InlineTemplate::make('inline-snippet')
                ->into($relPath)
                ->append()
                ->with(['method_name' => 'first']),
            InlineTemplate::make('inline-snippet')
                ->into($relPath)
                ->append()
                ->with(['method_name' => 'second']),
        ]);

        $results = $this->runner->run($generator, ['method_name' => 'ignored']);

        $this->assertCount(2, $results);
        $this->assertFalse($results[0]->skipped);
        $this->assertFalse($results[1]->skipped);

        // Second result's content should contain both snippets
        $this->assertStringContainsString('public function first(): void {}', $results[1]->content);
        $this->assertStringContainsString('public function second(): void {}', $results[1]->content);

        // Disk file should NOT have changed (preview mode)
        $diskContent = file_get_contents($absPath);
        $this->assertStringNotContainsString('public function first(): void {}', $diskContent);
    }

    public function test_multiple_insertions_into_same_file_are_written_to_disk_in_run_mode(): void
    {
        $absPath = $this->writeTmpFile('Target.php', "<?php\nclass Foo {\n}\n");
        $relPath = $this->relPath($absPath);

        $generator = $this->makeInlineGenerator([
            InlineTemplate::make('inline-snippet')
                ->into($relPath)
                ->append()
                ->with(['method_name' => 'first']),
            InlineTemplate::make('inline-snippet')
                ->into($relPath)
                ->append()
                ->with(['method_name' => 'second']),
        ]);

        $this->runner->run($generator, ['method_name' => 'ignored'], writeInlineResults: true);

        $diskContent = file_get_contents($absPath);
        $this->assertStringContainsString('public function first(): void {}', $diskContent);
        $this->assertStringContainsString('public function second(): void {}', $diskContent);
    }

    // ─── with() extra variables ───────────────────────────────────────────────

    public function test_with_extra_variables_override_for_this_insertion(): void
    {
        $absPath = $this->writeTmpFile('Target.php', "<?php\nclass Foo {\n}\n");
        $relPath = $this->relPath($absPath);

        $generator = $this->makeInlineGenerator([
            InlineTemplate::make('inline-snippet')
                ->into($relPath)
                ->append()
                ->with(['method_name' => 'overridden']),
        ]);

        $results = $this->runner->run($generator, ['method_name' => 'original']);

        $this->assertStringContainsString('public function overridden(): void {}', $results[0]->content);
        $this->assertStringNotContainsString('public function original(): void {}', $results[0]->content);
    }
}
