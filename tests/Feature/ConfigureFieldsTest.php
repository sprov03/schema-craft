<?php

namespace SchemaCraft\Tests\Feature;

use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use SchemaCraft\Action;
use SchemaCraft\FieldProxy;
use SchemaCraft\Tests\Fixtures\Actions\Post\CreatePostAction;
use SchemaCraft\Tests\TestCase;

class ConfigureFieldsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Action::clearScanCache();
    }

    /** Extract the schema components from a Filament action via reflection. */
    private function getSchemaComponents(object $action): array
    {
        $definition = $action::definition();

        return (new \ReflectionMethod($action, 'buildFilamentSchema'))
            ->invoke($action, $definition);
    }

    /** Find a component by Filament name from a list of components. */
    private function findComponent(array $components, string $name): mixed
    {
        foreach ($components as $component) {
            if ($component->getName() === $name) {
                return $component;
            }
        }

        return null;
    }

    // ─── Fluent chaining ─────────────────────────────────────────────

    public function test_configure_fields_returns_same_instance(): void
    {
        $action = new CreatePostAction;

        $result = $action->configureFields(function (FieldProxy $fields) {
            // noop
        });

        $this->assertSame($action, $result);
    }

    // ─── Modify mode ─────────────────────────────────────────────────

    public function test_configure_fields_modifies_belongs_to_select(): void
    {
        $action = new CreatePostAction;
        $action->configureFields(function (FieldProxy $fields) {
            $fields->author->preload();
        });

        $components = $this->getSchemaComponents($action);

        $authorSelect = $this->findComponent($components, 'author_id');
        $this->assertInstanceOf(Select::class, $authorSelect);
        $this->assertTrue($authorSelect->isPreloaded());
    }

    public function test_configure_fields_modifies_nullable_belongs_to(): void
    {
        $action = new CreatePostAction;
        $action->configureFields(function (FieldProxy $fields) {
            $fields->category->preload();
        });

        $components = $this->getSchemaComponents($action);

        $categorySelect = $this->findComponent($components, 'category_id');
        $this->assertInstanceOf(Select::class, $categorySelect);
        $this->assertTrue($categorySelect->isPreloaded());
    }

    // ─── Replace mode ────────────────────────────────────────────────

    public function test_configure_fields_replaces_component_via_assignment(): void
    {
        $action = new CreatePostAction;
        $action->configureFields(function (FieldProxy $fields) {
            $fields->title = Select::make('title')
                ->options(['a' => 'Option A', 'b' => 'Option B']);
        });

        $components = $this->getSchemaComponents($action);

        $titleComponent = $this->findComponent($components, 'title');
        $this->assertInstanceOf(Select::class, $titleComponent);
    }

    // ─── Untouched fields ────────────────────────────────────────────

    public function test_untouched_fields_keep_auto_generated_defaults(): void
    {
        $action = new CreatePostAction;
        $action->configureFields(function (FieldProxy $fields) {
            $fields->author->preload();
        });

        $components = $this->getSchemaComponents($action);

        // title should still be a TextInput
        $titleComponent = $this->findComponent($components, 'title');
        $this->assertInstanceOf(TextInput::class, $titleComponent);

        // isFeature should still be a Checkbox
        $featureComponent = $this->findComponent($components, 'is_feature');
        $this->assertInstanceOf(Checkbox::class, $featureComponent);

        // slug should still be a TextInput
        $slugComponent = $this->findComponent($components, 'slug');
        $this->assertInstanceOf(TextInput::class, $slugComponent);
    }

    // ─── Defaulted scalar access ─────────────────────────────────────

    public function test_configure_fields_modifies_defaulted_scalar(): void
    {
        $action = new CreatePostAction;
        $action->configureFields(function (FieldProxy $fields) {
            $fields->isFeature->label('Featured Post');
        });

        $components = $this->getSchemaComponents($action);

        $featureComponent = $this->findComponent($components, 'is_feature');
        $this->assertInstanceOf(Checkbox::class, $featureComponent);
        $this->assertSame('Featured Post', $featureComponent->getLabel());
    }

    // ─── Property values unaffected ──────────────────────────────────

    public function test_action_property_values_unaffected_by_configure_fields(): void
    {
        $action = new CreatePostAction;
        $action->isFeature = true;
        $action->title = 'My Title';

        $action->configureFields(function (FieldProxy $fields) {
            $fields->author->preload();
        });

        // Build schema triggers applyFieldConfiguration
        $this->getSchemaComponents($action);

        // Properties should be unchanged — proxy is separate from the action
        $this->assertTrue($action->isFeature);
        $this->assertSame('My Title', $action->title);
    }

    // ─── configureFields ->default() overwrites auto-generated ─────────

    public function test_configure_fields_default_overwrites_auto_generated(): void
    {
        $action = new CreatePostAction;

        $action->configureFields(function (FieldProxy $fields) {
            $fields->isFeature->default(true);
        });

        $components = $this->getSchemaComponents($action);

        // Find the is_feature component
        $isFeatureComponent = null;
        foreach ($components as $component) {
            if ($component->getName() === 'is_feature') {
                $isFeatureComponent = $component;
                break;
            }
        }

        $this->assertNotNull($isFeatureComponent);

        // configureFields ->default(true) should have overwritten the auto-generated false
        $prop = new \ReflectionProperty($isFeatureComponent, 'defaultState');
        $this->assertSame(true, $prop->getValue($isFeatureComponent));
    }

    // ─── Exception cleanup ───────────────────────────────────────────

    public function test_closure_exception_does_not_corrupt_action(): void
    {
        $action = new CreatePostAction;
        $action->isFeature = true;

        $action->configureFields(function (FieldProxy $fields) {
            throw new \RuntimeException('Test error');
        });

        try {
            $this->getSchemaComponents($action);
        } catch (\RuntimeException) {
            // Expected
        }

        // Properties unchanged — proxy is separate
        $this->assertTrue($action->isFeature);
    }

    // ─── Multiple fields modified ────────────────────────────────────

    public function test_configure_fields_modifies_multiple_fields(): void
    {
        $action = new CreatePostAction;
        $action->configureFields(function (FieldProxy $fields) {
            $fields->author->preload();
            $fields->title = Select::make('title')->options(['a' => 'A']);
            $fields->isFeature->label('Is Featured');
        });

        $components = $this->getSchemaComponents($action);

        $authorSelect = $this->findComponent($components, 'author_id');
        $this->assertInstanceOf(Select::class, $authorSelect);
        $this->assertTrue($authorSelect->isPreloaded());

        $titleComponent = $this->findComponent($components, 'title');
        $this->assertInstanceOf(Select::class, $titleComponent);

        $featureComponent = $this->findComponent($components, 'is_feature');
        $this->assertSame('Is Featured', $featureComponent->getLabel());
    }

    // ─── No configureFields — default behavior unchanged ─────────────

    public function test_no_configure_fields_produces_default_schema(): void
    {
        $action = new CreatePostAction;

        $components = $this->getSchemaComponents($action);

        // Should have components for: title, slug, body, isFeature, author, category
        $this->assertCount(6, $components);

        $this->assertInstanceOf(TextInput::class, $this->findComponent($components, 'title'));
        $this->assertInstanceOf(TextInput::class, $this->findComponent($components, 'slug'));
        $this->assertInstanceOf(Select::class, $this->findComponent($components, 'author_id'));
        $this->assertInstanceOf(Select::class, $this->findComponent($components, 'category_id'));
    }

    // ─── Text column types use Textarea ────────────────────────────────

    public function test_text_attribute_generates_textarea_component(): void
    {
        $action = new CreatePostAction;

        $components = $this->getSchemaComponents($action);

        // body has #[Text] — should be a Textarea, not TextInput
        $bodyComponent = $this->findComponent($components, 'body');
        $this->assertInstanceOf(Textarea::class, $bodyComponent);
    }

    // ─── Proxy isset ─────────────────────────────────────────────────

    public function test_proxy_isset_returns_true_for_known_fields(): void
    {
        $action = new CreatePostAction;
        $action->configureFields(function (FieldProxy $fields) {
            $this->assertTrue(isset($fields->author));
            $this->assertTrue(isset($fields->title));
            $this->assertFalse(isset($fields->nonexistent));
        });

        $this->getSchemaComponents($action);
    }
}
