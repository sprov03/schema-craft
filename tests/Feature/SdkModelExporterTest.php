<?php

namespace SchemaCraft\Tests\Feature;

use SchemaCraft\Generator\Sdk\SdkModelExporter;
use SchemaCraft\Tests\Fixtures\Schemas\CommentSchema;
use SchemaCraft\Tests\Fixtures\Schemas\PostSchema;
use SchemaCraft\Tests\TestCase;

/**
 * SdkModelExporter is the single orchestration both the CLI command and the visualizer SDK build
 * call: discover-scanned schema classes -> read-only model GeneratedFiles. Keeping it in one place
 * means the GUI export and the command can never drift.
 */
class SdkModelExporterTest extends TestCase
{
    public function test_exports_read_only_model_files_from_schema_classes(): void
    {
        $result = (new SdkModelExporter)->export(
            [PostSchema::class, CommentSchema::class],
            'Acme\\Sdk',
            'SchemaCraft\\Tests\\Fixtures\\Models',
        );

        $paths = array_map(fn ($f) => $f->path, $result['files']);

        $this->assertContains('src/Models/ReadOnlyModel.php', $paths);
        $this->assertContains('src/Models/Post.php', $paths);
        $this->assertContains('src/Models/Comment.php', $paths);
        $this->assertSame([], $result['warnings']);
    }

    public function test_warns_when_the_schemas_filter_is_active(): void
    {
        // Model export is designed to include every model on the connection. When an API pins a
        // `schemas` subset, a relation pointing at a model outside the list would be unresolved in the
        // package — so we surface a warning (not an error) when the filter is active. Not set by default.
        $withFilter = (new SdkModelExporter)->export(
            [PostSchema::class],
            'Acme\\Sdk',
            'SchemaCraft\\Tests\\Fixtures\\Models',
            schemasFilterActive: true,
        );

        $messages = array_column($withFilter['warnings'], 'message');
        $this->assertNotEmpty(array_filter($messages, fn ($m) => str_contains($m, 'schemas')));

        // Default (no filter) → no such warning.
        $withoutFilter = (new SdkModelExporter)->export(
            [PostSchema::class],
            'Acme\\Sdk',
            'SchemaCraft\\Tests\\Fixtures\\Models',
        );
        $this->assertSame([], $withoutFilter['warnings']);
    }

    public function test_skips_unscannable_schema_with_a_warning(): void
    {
        // A bad schema must not break the whole export — it's skipped with a warning so the rest
        // (and the SDK build it rides inside) still completes.
        $result = (new SdkModelExporter)->export(
            ['App\\Does\\Not\\Exist'],
            'Acme\\Sdk',
            'App\\Models',
        );

        $paths = array_map(fn ($f) => $f->path, $result['files']);

        $this->assertContains('src/Models/ReadOnlyModel.php', $paths);
        $this->assertNotEmpty($result['warnings']);
    }
}
