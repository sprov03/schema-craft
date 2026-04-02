<?php

namespace SchemaCraft\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use SchemaCraft\Action;
use SchemaCraft\Tests\Fixtures\Actions\Post\CreatePostAction;
use SchemaCraft\Tests\Fixtures\Actions\Post\UpdatePostAction;
use SchemaCraft\Tests\Fixtures\Models\Post;
use SchemaCraft\Tests\Fixtures\Models\User;
use SchemaCraft\Tests\TestCase;

class FilamentActionFillDataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Action::clearScanCache();
    }

    /** Call toFillArray() on an action via reflection. */
    private function getFillArray(object $action): array
    {
        return (new \ReflectionMethod($action, 'toFillArray'))->invoke($action);
    }

    /** Call fromRecord() on an action via reflection. */
    private function applyFromRecord(object $action, Model $record): void
    {
        (new \ReflectionMethod($action, 'fromRecord'))->invoke($action, $record);
    }

    /** Build a saved Post with the given attributes. */
    private function makePost(array $attributes = []): Post
    {
        $post = new Post;
        $post->forceFill($attributes);
        $post->exists = true;

        return $post;
    }

    // ─── PHP property defaults ────────────────────────────────────────────

    public function test_php_property_defaults_used_when_no_record(): void
    {
        $action = new CreatePostAction;
        $fill = $this->getFillArray($action);

        // CreatePostAction declares: public bool $isFeature = false;
        $this->assertSame(false, $fill['is_feature']);
    }

    public function test_uninitialized_props_return_null_with_no_record(): void
    {
        $action = new CreatePostAction;
        $fill = $this->getFillArray($action);

        // No defaults on these → null
        $this->assertNull($fill['title']);
        $this->assertNull($fill['body']);
    }

    // ─── fromRecord — scalar columns ─────────────────────────────────────

    public function test_from_record_maps_scalar_columns(): void
    {
        $post = $this->makePost(['title' => 'My Post', 'slug' => 'my-post', 'body' => 'Hello']);
        $action = new UpdatePostAction;
        $this->applyFromRecord($action, $post);
        $fill = $this->getFillArray($action);

        $this->assertSame('My Post', $fill['title']);
        $this->assertSame('my-post', $fill['slug']);
        $this->assertSame('Hello', $fill['body']);
    }

    public function test_from_record_maps_fk_column(): void
    {
        $post = $this->makePost(['author_id' => 7, 'category_id' => 3]);
        $action = new UpdatePostAction;
        $this->applyFromRecord($action, $post);
        $fill = $this->getFillArray($action);

        $this->assertSame(7, $fill['author_id']);
        $this->assertSame(3, $fill['category_id']);
    }

    public function test_from_record_skips_column_not_in_attributes(): void
    {
        $post = $this->makePost(['title' => 'Only Title']);
        $action = new UpdatePostAction;
        $this->applyFromRecord($action, $post);
        $fill = $this->getFillArray($action);

        $this->assertSame('Only Title', $fill['title']);
        $this->assertNull($fill['body']);
    }

    // ─── fromRecord override ──────────────────────────────────────────────

    public function test_from_record_override_maps_non_matching_columns(): void
    {
        $post = $this->makePost(['slug' => 'custom-source']);

        $action = new class extends UpdatePostAction
        {
            protected function fromRecord(Model $record): void
            {
                $this->title = $record->slug.'-mapped';
            }
        };

        $this->applyFromRecord($action, $post);
        $fill = $this->getFillArray($action);

        $this->assertSame('custom-source-mapped', $fill['title']);
    }

    // ─── withDefaults — highest priority ─────────────────────────────────

    public function test_with_defaults_overwrites_php_default(): void
    {
        $action = new CreatePostAction;
        $action->withDefaults(function (CreatePostAction $a): void {
            $a->isFeature = true;
        });

        // Simulate what filamentAction() does: apply the closure
        $fn = (new \ReflectionProperty(Action::class, 'defaultsFn'))->getValue($action);
        $fn($action);

        $fill = $this->getFillArray($action);

        $this->assertSame(true, $fill['is_feature']);
    }

    public function test_with_defaults_overwrites_record_value(): void
    {
        $post = $this->makePost(['title' => 'From Record', 'slug' => 'from-record']);
        $action = new UpdatePostAction;
        $this->applyFromRecord($action, $post);

        $action->withDefaults(function (UpdatePostAction $a): void {
            $a->title = 'Override Title';
        });
        $fn = (new \ReflectionProperty(Action::class, 'defaultsFn'))->getValue($action);
        $fn($action);

        $fill = $this->getFillArray($action);

        $this->assertSame('Override Title', $fill['title']);
        $this->assertSame('from-record', $fill['slug']); // not overridden
    }

    public function test_with_defaults_model_instance_extracted_to_key(): void
    {
        $user = new User;
        $user->forceFill(['id' => 42, 'name' => 'Test User']);
        $user->exists = true;

        $action = new UpdatePostAction;
        $action->withDefaults(function (UpdatePostAction $a) use ($user): void {
            $a->author = $user;
        });
        $fn = (new \ReflectionProperty(Action::class, 'defaultsFn'))->getValue($action);
        $fn($action);

        $fill = $this->getFillArray($action);

        $this->assertSame(42, $fill['author_id']);
    }

    // ─── unsaved model → fromRecord not called ────────────────────────────

    public function test_unsaved_model_skips_from_record(): void
    {
        $post = new Post;
        $post->forceFill(['title' => 'Should Not Appear']);
        // $post->exists is false by default

        $action = new UpdatePostAction;

        // Simulate what filamentAction() does: guard on $record->exists
        if ($post->exists) {
            $this->applyFromRecord($action, $post);
        }

        $fill = $this->getFillArray($action);

        $this->assertNull($fill['title']); // fromRecord was not called
    }

    // ─── withDefaults fluent returns the same instance ───────────────────

    public function test_with_defaults_returns_same_instance(): void
    {
        $action = new CreatePostAction;
        $result = $action->withDefaults(function (): void {});

        $this->assertSame($action, $result);
    }

    // ─── full chain: defaults → record → withDefaults ────────────────────

    public function test_full_precedence_chain(): void
    {
        // PHP default: isFeature = false
        // Record: title = 'From Record', isFeature not in attributes
        // withDefaults: title = 'Final Title', isFeature = true

        $post = $this->makePost(['title' => 'From Record', 'slug' => 'the-slug']);
        $action = new CreatePostAction;

        $this->applyFromRecord($action, $post);

        $action->withDefaults(function (CreatePostAction $a): void {
            $a->title = 'Final Title';
            $a->isFeature = true;
        });
        $fn = (new \ReflectionProperty(Action::class, 'defaultsFn'))->getValue($action);
        $fn($action);

        $fill = $this->getFillArray($action);

        $this->assertSame('Final Title', $fill['title']);      // withDefaults wins over record
        $this->assertSame('the-slug', $fill['slug']);          // record value preserved
        $this->assertSame(true, $fill['is_feature']);          // withDefaults wins over PHP default
    }

    // ─── resolveFillData — deferred lifecycle ────────────────────────────

    /** Call resolveFillData() on an action via reflection. */
    private function getResolveFillData(object $action, ?Model $record): array
    {
        return (new \ReflectionMethod($action, 'resolveFillData'))->invoke($action, $record);
    }

    public function test_resolve_fill_data_with_no_record(): void
    {
        $action = new CreatePostAction;
        $fill = $this->getResolveFillData($action, null);

        $this->assertSame(false, $fill['is_feature']);
        $this->assertNull($fill['title']);
    }

    public function test_resolve_fill_data_with_record(): void
    {
        $post = $this->makePost(['title' => 'Hello', 'slug' => 'hello']);
        $action = new UpdatePostAction;
        $fill = $this->getResolveFillData($action, $post);

        $this->assertSame('Hello', $fill['title']);
        $this->assertSame('hello', $fill['slug']);
    }

    public function test_resolve_fill_data_applies_with_defaults(): void
    {
        $action = new CreatePostAction;
        $action->withDefaults(function (CreatePostAction $a): void {
            $a->isFeature = true;
            $a->title = 'Default Title';
        });

        $fill = $this->getResolveFillData($action, null);

        $this->assertSame(true, $fill['is_feature']);
        $this->assertSame('Default Title', $fill['title']);
    }

    public function test_resolve_fill_data_full_precedence(): void
    {
        $post = $this->makePost(['title' => 'From Record', 'slug' => 'the-slug']);
        $action = new CreatePostAction;
        $action->withDefaults(function (CreatePostAction $a): void {
            $a->title = 'Final Title';
            $a->isFeature = true;
        });

        $fill = $this->getResolveFillData($action, $post);

        $this->assertSame('Final Title', $fill['title']);
        $this->assertSame('the-slug', $fill['slug']);
        $this->assertSame(true, $fill['is_feature']);
    }

    public function test_resolve_fill_data_does_not_mutate_original(): void
    {
        $post = $this->makePost(['title' => 'Record Title', 'slug' => 'slug']);
        $action = new CreatePostAction;
        $action->withDefaults(function (CreatePostAction $a): void {
            $a->title = 'Override';
        });

        // Call resolveFillData — should clone internally
        $this->getResolveFillData($action, $post);

        // Original action's properties should be unchanged
        $originalFill = $this->getFillArray($action);
        $this->assertNull($originalFill['title']); // not mutated
    }

    public function test_resolve_fill_data_clone_isolation_between_calls(): void
    {
        $postA = $this->makePost(['title' => 'Post A', 'slug' => 'a']);
        $postB = $this->makePost(['title' => 'Post B', 'slug' => 'b']);
        $action = new UpdatePostAction;

        $fillA = $this->getResolveFillData($action, $postA);
        $fillB = $this->getResolveFillData($action, $postB);

        $this->assertSame('Post A', $fillA['title']);
        $this->assertSame('Post B', $fillB['title']);
    }

    // ─── withDefaults two-param arity ────────────────────────────────────

    public function test_with_defaults_receives_record_as_second_param(): void
    {
        $post = $this->makePost(['title' => 'Original', 'slug' => 'slug']);
        $action = new CreatePostAction;
        $action->withDefaults(function (CreatePostAction $a, ?Model $record): void {
            $a->title = 'From record: '.($record?->title ?? 'none');
        });

        $fill = $this->getResolveFillData($action, $post);

        $this->assertSame('From record: Original', $fill['title']);
    }

    public function test_with_defaults_single_param_still_works(): void
    {
        $post = $this->makePost(['title' => 'Original', 'slug' => 'slug']);
        $action = new CreatePostAction;
        $action->withDefaults(function (CreatePostAction $a): void {
            $a->title = 'Static Override';
        });

        $fill = $this->getResolveFillData($action, $post);

        $this->assertSame('Static Override', $fill['title']);
    }

    public function test_with_defaults_two_param_receives_null_when_no_record(): void
    {
        $action = new CreatePostAction;
        $action->withDefaults(function (CreatePostAction $a, ?Model $record): void {
            $a->title = $record !== null ? 'Has Record' : 'No Record';
        });

        $fill = $this->getResolveFillData($action, null);

        $this->assertSame('No Record', $fill['title']);
    }
}
