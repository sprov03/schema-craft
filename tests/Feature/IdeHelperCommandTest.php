<?php

namespace SchemaCraft\Tests\Feature;

use SchemaCraft\Tests\Fixtures\Schemas\CommentSchema;
use SchemaCraft\Tests\TestCase;

class IdeHelperCommandTest extends TestCase
{
    private string $output;

    protected function setUp(): void
    {
        parent::setUp();
        $this->output = sys_get_temp_dir().'/ide_helper_test_'.uniqid().'.php';

        // UnionCareItemSchema is an in-file fixture of MorphToRuntimeTest (not PSR-4
        // autoloadable), so pull it in explicitly for the union test.
        require_once __DIR__.'/MorphToRuntimeTest.php';
    }

    protected function tearDown(): void
    {
        @unlink($this->output);
        parent::tearDown();
    }

    public function test_generates_stubs_for_relationship_methods_and_synthesized_columns(): void
    {
        $this->artisan('schema:ide-helper', [
            '--schemas' => CommentSchema::class,
            '--output' => $this->output,
        ])->assertExitCode(0);

        $content = file_get_contents($this->output);

        // Namespace block redeclares the schema class with a docblock-only body.
        $this->assertStringContainsString('namespace SchemaCraft\Tests\Fixtures\Schemas {', $content);
        $this->assertStringContainsString('class CommentSchema {}', $content);

        // Relationship methods: belongsTo user + morphTo commentable.
        $this->assertStringContainsString(
            '@method \Illuminate\Database\Eloquent\Relations\BelongsTo|\SchemaCraft\Tests\Fixtures\Models\User user()',
            $content,
        );
        $this->assertStringContainsString('@method \Illuminate\Database\Eloquent\Relations\MorphTo commentable()', $content);

        // Synthesized columns only — the real properties (body, user relation) are not re-emitted.
        $this->assertStringContainsString('@property int $user_id', $content);
        $this->assertStringContainsString('@property string $commentable_type', $content);
        $this->assertStringContainsString('@property int $commentable_id', $content);
        $this->assertStringNotContainsString('@property string $body', $content);
    }

    public function test_union_typed_morph_to_targets_appear_in_method_return(): void
    {
        $this->artisan('schema:ide-helper', [
            '--schemas' => UnionCareItemSchema::class,
            '--output' => $this->output,
        ])->assertExitCode(0);

        $content = file_get_contents($this->output);

        // The union members from the property type ride the @method return union.
        $this->assertStringContainsString(
            '@method \Illuminate\Database\Eloquent\Relations\MorphTo'
            .'|\SchemaCraft\Tests\Fixtures\Models\Post|\SchemaCraft\Tests\Fixtures\Models\User inTheCareOf()',
            $content,
        );

        // Nullable union -> nullable synthesized columns.
        $this->assertStringContainsString('@property string|null $in_the_care_of_type', $content);
        $this->assertStringContainsString('@property int|null $in_the_care_of_id', $content);
    }

    public function test_any_failing_schema_aborts_without_touching_the_existing_file(): void
    {
        file_put_contents($this->output, 'PREVIOUS CONTENT');

        // One good schema + one broken one: the run must fail atomically — the good schema's
        // stub must NOT be written either, so an on-save watcher can never half-update.
        $this->artisan('schema:ide-helper', [
            '--schemas' => CommentSchema::class.',App\\Schemas\\DoesNotExistSchema',
            '--output' => $this->output,
        ])->assertExitCode(1);

        $this->assertSame('PREVIOUS CONTENT', file_get_contents($this->output));
    }
}
