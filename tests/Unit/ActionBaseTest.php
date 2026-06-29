<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Tests\Fixtures\Actions\Post\CreatePostAction;
use SchemaCraft\Tests\Fixtures\Actions\Post\DeletePostAction;
use SchemaCraft\Tests\Fixtures\Actions\Post\UpdatePostAction;
use SchemaCraft\Tests\Fixtures\Actions\Post\UpdatePostWithDataSchemaAction;
use SchemaCraft\Tests\Fixtures\Schemas\PostSchema;

class ActionBaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CreatePostAction::clearScanCache();
        UpdatePostAction::clearScanCache();
        DeletePostAction::clearScanCache();
        UpdatePostWithDataSchemaAction::clearScanCache();
    }

    public function test_schema_returns_configured_schema_class(): void
    {
        $this->assertSame(PostSchema::class, CreatePostAction::schema());
    }

    public function test_service_method_derived_from_class_name(): void
    {
        $this->assertSame('createPost', CreatePostAction::serviceMethod());
        $this->assertSame('updatePost', UpdatePostAction::serviceMethod());
        $this->assertSame('deletePost', DeletePostAction::serviceMethod());
    }

    public function test_meta_returns_action_meta_attribute(): void
    {
        $meta = CreatePostAction::meta();

        $this->assertNotNull($meta);
        $this->assertSame('post', $meta->method);
        $this->assertSame('Create Post', $meta->label);
    }

    public function test_meta_returns_null_when_no_attribute(): void
    {
        // Create a minimal action without ActionMeta for this test
        $action = new class extends \SchemaCraft\Action
        {
            protected static string $schema = PostSchema::class;

            public function run(mixed $record, array $mapped): mixed
            {
                return $record;
            }
        };

        $meta = $action::meta();
        $this->assertNull($meta);
    }

    public function test_definition_returns_action_definition(): void
    {
        $definition = CreatePostAction::definition();

        $this->assertSame(CreatePostAction::class, $definition->actionClass);
        $this->assertSame('createPost', $definition->name);
        $this->assertSame('post', $definition->httpMethod);
        $this->assertCount(6, $definition->parameters);
    }

    public function test_definition_is_cached(): void
    {
        $first = CreatePostAction::definition();
        $second = CreatePostAction::definition();

        $this->assertSame($first, $second);
    }

    public function test_clear_scan_cache_resets_definition(): void
    {
        $first = CreatePostAction::definition();
        CreatePostAction::clearScanCache();
        $second = CreatePostAction::definition();

        $this->assertNotSame($first, $second);
    }

    public function test_delete_action_has_no_parameters(): void
    {
        $definition = DeletePostAction::definition();

        $this->assertCount(0, $definition->parameters);
        $this->assertSame('delete', $definition->httpMethod);
    }

    // ─── Nested Relationship: definition() ────────────────────────

    public function test_nested_action_definition_has_nested_params(): void
    {
        $definition = UpdatePostWithDataSchemaAction::definition();

        $nested = $definition->nestedParameters();
        $this->assertCount(2, $nested);

        $names = array_map(fn ($p) => $p->name, $nested);
        $this->assertContains('comments', $names);
        $this->assertContains('tags', $names);
    }

    public function test_nested_action_definition_has_flat_params(): void
    {
        $definition = UpdatePostWithDataSchemaAction::definition();

        $flat = $definition->flatParameters();
        $this->assertCount(2, $flat);

        $names = array_map(fn ($p) => $p->name, $flat);
        $this->assertContains('title', $names);
        $this->assertContains('author', $names);
    }

    // ─── Nested Relationship: mapData() ───────────────────────────

    public function test_map_data_passes_nested_collection_through(): void
    {
        $action = new UpdatePostWithDataSchemaAction;
        $data = [
            'title' => 'Test Title',
            'author_id' => null,
            'comments' => [['body' => 'Hello']],
            'tags' => [['name' => 'php']],
        ];

        $mapped = $action->mapData($data);

        $this->assertSame([['body' => 'Hello']], $mapped['comments']);
        $this->assertSame([['name' => 'php']], $mapped['tags']);
    }

    public function test_map_data_defaults_collection_to_empty_array(): void
    {
        $action = new UpdatePostWithDataSchemaAction;
        $data = [
            'title' => 'Test',
            'author_id' => null,
        ];

        $mapped = $action->mapData($data);

        $this->assertSame([], $mapped['comments']);
        $this->assertSame([], $mapped['tags']);
    }

    // ─── Nested Relationship: rules() cascade via shared walker (Step 4) ──

    public function test_nested_relationship_rules_cascade_item_shape_via_shared_walker(): void
    {
        $rules = (new UpdatePostWithDataSchemaAction)->rules();

        // The container keeps its relationship-only `sometimes|array` rule.
        $this->assertSame('sometimes|array', $rules['comments']);

        // Item fields cascade under `.*` from the item DataSchema's own walker — the same
        // DataSchema::validationRules() path Request uses, not the hand-rolled per-field loop.
        $this->assertArrayHasKey('comments.*.body', $rules);
        $this->assertContains('required', $rules['comments.*.body']);
        $this->assertContains('string', $rules['comments.*.body']);
    }
}
