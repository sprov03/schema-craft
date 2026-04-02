<?php

namespace SchemaCraft\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use SchemaCraft\Action;
use SchemaCraft\FieldProxy;
use SchemaCraft\Tests\Fixtures\Actions\Post\CreatePostAction;
use SchemaCraft\Tests\Fixtures\Actions\Post\UpdatePostAction;
use SchemaCraft\Tests\Fixtures\Models\Post;
use SchemaCraft\Tests\TestCase;

class FilamentActionFillDataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Action::clearScanCache();
    }

    /** Build a saved Post with the given attributes. */
    private function makePost(array $attributes = []): Post
    {
        $post = new Post;
        $post->forceFill($attributes);
        $post->exists = true;

        return $post;
    }

    /** Get the default state from a Filament component via reflection. */
    private function getComponentDefaultState(object $component): mixed
    {
        $prop = new \ReflectionProperty($component, 'defaultState');

        $value = $prop->getValue($component);

        // If it's a closure, evaluate it without a record (null)
        if ($value instanceof \Closure) {
            return $value(null);
        }

        return $value;
    }

    /** Get the default state from a Filament component with a record via reflection. */
    private function getComponentDefaultStateWithRecord(object $component, Model $record): mixed
    {
        $prop = new \ReflectionProperty($component, 'defaultState');

        $value = $prop->getValue($component);

        if ($value instanceof \Closure) {
            return $value($record);
        }

        return $value;
    }

    // ─── PHP property defaults via ->default() ───────────────────────────

    public function test_scalar_component_gets_php_default(): void
    {
        $action = new CreatePostAction;
        $filamentAction = $action->filamentAction();

        $definition = CreatePostAction::definition();
        $schema = (new \ReflectionMethod($action, 'buildFilamentSchema'))->invoke($action, $definition);

        // isFeature has PHP default of false → component should have that as default
        $isFeatureComponent = null;
        foreach ($schema as $component) {
            if ($component->getName() === 'is_feature') {
                $isFeatureComponent = $component;
                break;
            }
        }

        $this->assertNotNull($isFeatureComponent);
        $this->assertSame(false, $this->getComponentDefaultState($isFeatureComponent));
    }

    public function test_scalar_component_without_default_returns_null(): void
    {
        $action = new CreatePostAction;
        $definition = CreatePostAction::definition();
        $schema = (new \ReflectionMethod($action, 'buildFilamentSchema'))->invoke($action, $definition);

        $titleComponent = null;
        foreach ($schema as $component) {
            if ($component->getName() === 'title') {
                $titleComponent = $component;
                break;
            }
        }

        $this->assertNotNull($titleComponent);
        $this->assertNull($this->getComponentDefaultState($titleComponent));
    }

    // ─── Record data via ->default() closures ────────────────────────────

    public function test_scalar_default_closure_resolves_record_value(): void
    {
        $post = $this->makePost(['title' => 'My Post', 'slug' => 'my-post']);
        $action = new UpdatePostAction;
        $definition = UpdatePostAction::definition();
        $schema = (new \ReflectionMethod($action, 'buildFilamentSchema'))->invoke($action, $definition);

        $titleComponent = null;
        foreach ($schema as $component) {
            if ($component->getName() === 'title') {
                $titleComponent = $component;
                break;
            }
        }

        $this->assertNotNull($titleComponent);
        $this->assertSame('My Post', $this->getComponentDefaultStateWithRecord($titleComponent, $post));
    }

    public function test_fk_default_closure_resolves_record_fk_value(): void
    {
        $post = $this->makePost(['author_id' => 7]);
        $action = new UpdatePostAction;
        $definition = UpdatePostAction::definition();
        $schema = (new \ReflectionMethod($action, 'buildFilamentSchema'))->invoke($action, $definition);

        $authorComponent = null;
        foreach ($schema as $component) {
            if ($component->getName() === 'author_id') {
                $authorComponent = $component;
                break;
            }
        }

        $this->assertNotNull($authorComponent);
        $this->assertSame(7, $this->getComponentDefaultStateWithRecord($authorComponent, $post));
    }

    public function test_fk_default_closure_returns_null_without_record(): void
    {
        $action = new UpdatePostAction;
        $definition = UpdatePostAction::definition();
        $schema = (new \ReflectionMethod($action, 'buildFilamentSchema'))->invoke($action, $definition);

        $authorComponent = null;
        foreach ($schema as $component) {
            if ($component->getName() === 'author_id') {
                $authorComponent = $component;
                break;
            }
        }

        $this->assertNotNull($authorComponent);
        $this->assertNull($this->getComponentDefaultState($authorComponent));
    }

    // ─── configureFields ->default() overwrites ─────────────────────────

    public function test_configure_fields_default_overwrites_auto_generated(): void
    {
        $action = new CreatePostAction;
        $action->configureFields(function (FieldProxy $fields): void {
            $fields->isFeature->default(true);
        });

        $definition = CreatePostAction::definition();
        $schema = (new \ReflectionMethod($action, 'buildFilamentSchema'))->invoke($action, $definition);

        $isFeatureComponent = null;
        foreach ($schema as $component) {
            if ($component->getName() === 'is_feature') {
                $isFeatureComponent = $component;
                break;
            }
        }

        $this->assertNotNull($isFeatureComponent);

        // configureFields set ->default(true), overwriting the auto-generated false
        $prop = new \ReflectionProperty($isFeatureComponent, 'defaultState');
        $this->assertSame(true, $prop->getValue($isFeatureComponent));
    }

    public function test_configure_fields_default_closure_overwrites(): void
    {
        $action = new CreatePostAction;
        $action->configureFields(function (FieldProxy $fields): void {
            $fields->title->default(fn (?Model $record) => 'Custom: '.($record?->title ?? 'new'));
        });

        $definition = CreatePostAction::definition();
        $schema = (new \ReflectionMethod($action, 'buildFilamentSchema'))->invoke($action, $definition);

        $titleComponent = null;
        foreach ($schema as $component) {
            if ($component->getName() === 'title') {
                $titleComponent = $component;
                break;
            }
        }

        $this->assertNotNull($titleComponent);

        // Evaluate the closure without a record
        $prop = new \ReflectionProperty($titleComponent, 'defaultState');
        $closure = $prop->getValue($titleComponent);
        $this->assertInstanceOf(\Closure::class, $closure);
        $this->assertSame('Custom: new', $closure(null));

        // Evaluate the closure with a record
        $post = $this->makePost(['title' => 'Hello']);
        $this->assertSame('Custom: Hello', $closure($post));
    }

    // ─── filamentAction accepts configureFields parameter ────────────────

    public function test_filament_action_accepts_configure_fields_closure(): void
    {
        $action = new CreatePostAction;
        $filamentAction = $action->filamentAction(function (FieldProxy $fields): void {
            $fields->title->label('Custom Title Label');
        });

        $this->assertNotNull($filamentAction);
    }

    // ─── Unsaved model returns PHP defaults ──────────────────────────────

    public function test_unsaved_record_falls_back_to_php_default(): void
    {
        $unsavedPost = new Post;
        $unsavedPost->forceFill(['title' => 'Should Not Appear']);
        // $unsavedPost->exists is false

        $action = new UpdatePostAction;
        $definition = UpdatePostAction::definition();
        $schema = (new \ReflectionMethod($action, 'buildFilamentSchema'))->invoke($action, $definition);

        $titleComponent = null;
        foreach ($schema as $component) {
            if ($component->getName() === 'title') {
                $titleComponent = $component;
                break;
            }
        }

        $this->assertNotNull($titleComponent);
        // Unsaved record (exists = false) → falls back to PHP default (null)
        $this->assertNull($this->getComponentDefaultStateWithRecord($titleComponent, $unsavedPost));
    }

    // ─── configureFields fluent returns same instance ────────────────────

    public function test_configure_fields_returns_same_instance(): void
    {
        $action = new CreatePostAction;
        $result = $action->configureFields(function (): void {});

        $this->assertSame($action, $result);
    }
}
