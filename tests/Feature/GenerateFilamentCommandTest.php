<?php

namespace SchemaCraft\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use SchemaCraft\Tests\TestCase;

class GenerateFilamentCommandTest extends TestCase
{
    private Filesystem $files;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->tempDir = sys_get_temp_dir().'/gen-filament-test-'.uniqid();
        $this->files->makeDirectory($this->tempDir.'/app/Schemas', 0755, true);
        $this->files->makeDirectory($this->tempDir.'/app/Models', 0755, true);
        $this->files->makeDirectory($this->tempDir.'/app/Filament/Resources', 0755, true);
        $this->files->makeDirectory($this->tempDir.'/app/Policies', 0755, true);

        $this->app->useAppPath($this->tempDir.'/app');
        $this->app->setBasePath($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    private function createSchemaFile(string $name, string $body = ''): void
    {
        $content = <<<PHP
<?php

namespace App\Schemas;

use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\ColumnType;
use SchemaCraft\Attributes\Length;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Attributes\Unique;
use SchemaCraft\Attributes\Relations\BelongsTo;
use SchemaCraft\Attributes\Relations\HasMany;
use SchemaCraft\Schema;
use SchemaCraft\Traits\SoftDeletesSchema;
use SchemaCraft\Traits\TimestampsSchema;

class {$name}Schema extends Schema
{
    use TimestampsSchema;

    #[Primary]
    #[AutoIncrement]
    public int \$id;

{$body}}
PHP;

        $path = $this->tempDir."/app/Schemas/{$name}Schema.php";
        $this->files->put($path, $content);

        require_once $path;
    }

    // ─── Basic generation ──────────────────────

    public function test_generates_resource_file(): void
    {
        $this->createSchemaFile('Article', '    public string $title;
    public string $body;
');

        $this->artisan('schema:filament', ['schema' => 'ArticleSchema'])
            ->assertSuccessful();

        $resourcePath = $this->tempDir.'/app/Filament/Resources/ArticleResource.php';
        $this->assertFileExists($resourcePath);

        $content = $this->files->get($resourcePath);
        $this->assertStringContainsString('class ArticleResource extends Resource', $content);
        $this->assertStringContainsString('protected static ?string $model = Article::class', $content);
    }

    public function test_generates_page_files(): void
    {
        $this->createSchemaFile('Post', '    public string $title;
');

        $this->artisan('schema:filament', ['schema' => 'PostSchema'])
            ->assertSuccessful();

        $this->assertFileExists($this->tempDir.'/app/Filament/Resources/PostResource/Pages/ListPosts.php');
        $this->assertFileExists($this->tempDir.'/app/Filament/Resources/PostResource/Pages/CreatePost.php');
        $this->assertFileExists($this->tempDir.'/app/Filament/Resources/PostResource/Pages/EditPost.php');

        $listContent = $this->files->get(
            $this->tempDir.'/app/Filament/Resources/PostResource/Pages/ListPosts.php'
        );
        $this->assertStringContainsString('class ListPosts extends BaseListRecords', $listContent);
        $this->assertStringContainsString('PostResource::class', $listContent);
    }

    // ─── Form field type mapping ──────────────────────

    public function test_string_column_generates_text_input(): void
    {
        $this->createSchemaFile('Widget', '    public string $name;
');

        $this->artisan('schema:filament', ['schema' => 'WidgetSchema'])
            ->assertSuccessful();

        $content = $this->files->get($this->tempDir.'/app/Filament/Resources/WidgetResource.php');
        $this->assertStringContainsString("TextInput::make('name')", $content);
        $this->assertStringContainsString('->required()', $content);
        $this->assertStringContainsString('->maxLength(255)', $content);
    }

    public function test_boolean_column_generates_toggle(): void
    {
        $this->createSchemaFile('Setting', '    public bool $is_active;
');

        $this->artisan('schema:filament', ['schema' => 'SettingSchema'])
            ->assertSuccessful();

        $content = $this->files->get($this->tempDir.'/app/Filament/Resources/SettingResource.php');
        $this->assertStringContainsString("Toggle::make('is_active')", $content);
    }

    public function test_boolean_column_generates_icon_column_in_table(): void
    {
        $this->createSchemaFile('Flag', '    public bool $enabled;
');

        $this->artisan('schema:filament', ['schema' => 'FlagSchema'])
            ->assertSuccessful();

        $content = $this->files->get($this->tempDir.'/app/Filament/Resources/FlagResource.php');
        $this->assertStringContainsString("IconColumn::make('enabled')", $content);
        $this->assertStringContainsString('->boolean()', $content);
    }

    public function test_integer_column_generates_numeric_input(): void
    {
        $this->createSchemaFile('Counter', '    public int $quantity;
');

        $this->artisan('schema:filament', ['schema' => 'CounterSchema'])
            ->assertSuccessful();

        $content = $this->files->get($this->tempDir.'/app/Filament/Resources/CounterResource.php');
        $this->assertStringContainsString("TextInput::make('quantity')", $content);
        $this->assertStringContainsString('->numeric()', $content);
    }

    public function test_date_column_generates_date_picker(): void
    {
        $this->createSchemaFile('Event', '    #[ColumnType(\'date\')]
    public string $event_date;
');

        $this->artisan('schema:filament', ['schema' => 'EventSchema'])
            ->assertSuccessful();

        $content = $this->files->get($this->tempDir.'/app/Filament/Resources/EventResource.php');
        $this->assertStringContainsString("DatePicker::make('event_date')", $content);
    }

    public function test_nullable_field_does_not_have_required(): void
    {
        $this->createSchemaFile('Note', '    public ?string $content = null;
');

        $this->artisan('schema:filament', ['schema' => 'NoteSchema'])
            ->assertSuccessful();

        $content = $this->files->get($this->tempDir.'/app/Filament/Resources/NoteResource.php');
        $this->assertStringContainsString("TextInput::make('content')", $content);
        $this->assertStringNotContainsString("->required()\n", $content);
    }

    // ─── Table column mapping ──────────────────────

    public function test_timestamp_columns_in_table_are_toggleable(): void
    {
        $this->createSchemaFile('Log', '    public string $message;
');

        $this->artisan('schema:filament', ['schema' => 'LogSchema'])
            ->assertSuccessful();

        $content = $this->files->get($this->tempDir.'/app/Filament/Resources/LogResource.php');
        $this->assertStringContainsString("TextColumn::make('created_at')", $content);
        $this->assertStringContainsString('->toggleable(isToggledHiddenByDefault: true)', $content);
    }

    public function test_timestamps_not_in_form(): void
    {
        $this->createSchemaFile('Entry', '    public string $title;
');

        $this->artisan('schema:filament', ['schema' => 'EntrySchema'])
            ->assertSuccessful();

        $content = $this->files->get($this->tempDir.'/app/Filament/Resources/EntryResource.php');

        // The form section should not contain created_at/updated_at
        preg_match('/->components\(\[(.*?)\]\)/s', $content, $formMatch);
        $this->assertNotEmpty($formMatch);
        $this->assertStringNotContainsString('created_at', $formMatch[1]);
        $this->assertStringNotContainsString('updated_at', $formMatch[1]);
    }

    public function test_string_column_generates_searchable_sortable_table_column(): void
    {
        $this->createSchemaFile('Product', '    public string $name;
');

        $this->artisan('schema:filament', ['schema' => 'ProductSchema'])
            ->assertSuccessful();

        $content = $this->files->get($this->tempDir.'/app/Filament/Resources/ProductResource.php');
        $this->assertStringContainsString("TextColumn::make('name')", $content);
        $this->assertStringContainsString('->searchable()', $content);
        $this->assertStringContainsString('->sortable()', $content);
    }

    // ─── Policy generation ──────────────────────

    public function test_policy_generated_with_flag(): void
    {
        $this->createSchemaFile('Task', '    public string $title;
');

        $this->artisan('schema:filament', ['schema' => 'TaskSchema', '--with-policy' => true])
            ->assertSuccessful();

        $policyPath = $this->tempDir.'/app/Policies/TaskPolicy.php';
        $this->assertFileExists($policyPath);

        $content = $this->files->get($policyPath);
        $this->assertStringContainsString('class TaskPolicy', $content);
        $this->assertStringContainsString('public function viewAny(User $user): bool', $content);
        $this->assertStringContainsString('public function create(User $user): bool', $content);
        $this->assertStringContainsString('public function update(User $user, Task $task): bool', $content);
        $this->assertStringContainsString('public function delete(User $user, Task $task): bool', $content);
        $this->assertStringContainsString('public function restore(User $user, Task $task): bool', $content);
        $this->assertStringContainsString('public function forceDelete(User $user, Task $task): bool', $content);
        $this->assertStringContainsString('return true;', $content);
    }

    public function test_policy_not_generated_without_flag(): void
    {
        $this->createSchemaFile('Item', '    public string $name;
');

        $this->artisan('schema:filament', ['schema' => 'ItemSchema'])
            ->assertSuccessful();

        $this->assertFileDoesNotExist($this->tempDir.'/app/Policies/ItemPolicy.php');
    }

    // ─── Force overwrite ──────────────────────

    public function test_skips_existing_files_without_force(): void
    {
        $this->createSchemaFile('Existing', '    public string $name;
');

        // Generate once
        $this->artisan('schema:filament', ['schema' => 'ExistingSchema'])
            ->assertSuccessful();

        $resourcePath = $this->tempDir.'/app/Filament/Resources/ExistingResource.php';
        $originalContent = $this->files->get($resourcePath);

        // Modify the file
        $this->files->put($resourcePath, '<?php // modified');

        // Generate again without force - should skip
        $this->artisan('schema:filament', ['schema' => 'ExistingSchema'])
            ->assertSuccessful();

        $this->assertEquals('<?php // modified', $this->files->get($resourcePath));
    }

    public function test_overwrites_with_force(): void
    {
        $this->createSchemaFile('Forced', '    public string $name;
');

        // Generate once
        $this->artisan('schema:filament', ['schema' => 'ForcedSchema'])
            ->assertSuccessful();

        $resourcePath = $this->tempDir.'/app/Filament/Resources/ForcedResource.php';

        // Modify the file
        $this->files->put($resourcePath, '<?php // modified');

        // Generate again with force
        $this->artisan('schema:filament', ['schema' => 'ForcedSchema', '--force' => true])
            ->assertSuccessful();

        $content = $this->files->get($resourcePath);
        $this->assertStringContainsString('class ForcedResource extends Resource', $content);
    }

    // ─── Error handling ──────────────────────

    public function test_fails_for_nonexistent_schema(): void
    {
        $this->artisan('schema:filament', ['schema' => 'NonExistentSchema'])
            ->assertFailed();
    }

    // ─── Soft deletes ──────────────────────

    public function test_soft_deletes_adds_trashed_filter(): void
    {
        $body = <<<'PHP'
    use SoftDeletesSchema;

    public string $name;

PHP;
        $content = <<<PHP
<?php

namespace App\Schemas;

use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Schema;
use SchemaCraft\Traits\SoftDeletesSchema;
use SchemaCraft\Traits\TimestampsSchema;

class SoftItemSchema extends Schema
{
    use SoftDeletesSchema;
    use TimestampsSchema;

    #[Primary]
    #[AutoIncrement]
    public int \$id;

    public string \$name;
}
PHP;

        $path = $this->tempDir.'/app/Schemas/SoftItemSchema.php';
        $this->files->put($path, $content);
        require_once $path;

        $this->artisan('schema:filament', ['schema' => 'SoftItemSchema'])
            ->assertSuccessful();

        $resourceContent = $this->files->get(
            $this->tempDir.'/app/Filament/Resources/SoftItemResource.php'
        );
        $this->assertStringContainsString('TrashedFilter::make()', $resourceContent);
    }

    // ─── Schema name resolution ──────────────────────

    public function test_resolves_short_name_without_suffix(): void
    {
        $this->createSchemaFile('Gadget', '    public string $name;
');

        $this->artisan('schema:filament', ['schema' => 'Gadget'])
            ->assertSuccessful();

        $this->assertFileExists($this->tempDir.'/app/Filament/Resources/GadgetResource.php');
    }

    // ─── Unique columns ──────────────────────

    public function test_unique_column_gets_unique_modifier(): void
    {
        $this->createSchemaFile('Slug', '    #[Unique]
    public string $slug;
');

        $this->artisan('schema:filament', ['schema' => 'SlugSchema'])
            ->assertSuccessful();

        $content = $this->files->get($this->tempDir.'/app/Filament/Resources/SlugResource.php');
        $this->assertStringContainsString('->unique(ignoreRecord: true)', $content);
    }

    // ─── Title column resolution ──────────────────────

    public function test_belongs_to_with_name_column_uses_name_as_title(): void
    {
        $this->createSchemaFile('Category', '    public string $name;
');

        $content = <<<'PHP'
<?php

namespace App\Schemas;

use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Attributes\Relations\BelongsTo;
use SchemaCraft\Schema;
use SchemaCraft\Traits\TimestampsSchema;

class ProductWithCategorySchema extends Schema
{
    use TimestampsSchema;

    #[Primary]
    #[AutoIncrement]
    public int $id;

    public string $sku;

    #[BelongsTo(\App\Models\Category::class)]
    public \App\Models\Category $category;
}
PHP;

        $path = $this->tempDir.'/app/Schemas/ProductWithCategorySchema.php';
        $this->files->put($path, $content);
        require_once $path;

        $this->artisan('schema:filament', ['schema' => 'ProductWithCategorySchema'])
            ->assertSuccessful();

        $resourceContent = $this->files->get(
            $this->tempDir.'/app/Filament/Resources/ProductWithCategoryResource.php'
        );

        $this->assertStringContainsString("->relationship('category', 'name')", $resourceContent);
        $this->assertStringContainsString("TextColumn::make('category.name')", $resourceContent);
    }

    public function test_belongs_to_resolves_label_when_no_name_column(): void
    {
        $this->createSchemaFile('Priority', '    public int $order;
    public string $label;
');

        $content = <<<'PHP'
<?php

namespace App\Schemas;

use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Attributes\Relations\BelongsTo;
use SchemaCraft\Schema;
use SchemaCraft\Traits\TimestampsSchema;

class TicketSchema extends Schema
{
    use TimestampsSchema;

    #[Primary]
    #[AutoIncrement]
    public int $id;

    public string $subject;

    #[BelongsTo(\App\Models\Priority::class)]
    public \App\Models\Priority $priority;
}
PHP;

        $path = $this->tempDir.'/app/Schemas/TicketSchema.php';
        $this->files->put($path, $content);
        require_once $path;

        $this->artisan('schema:filament', ['schema' => 'TicketSchema'])
            ->assertSuccessful();

        $resourceContent = $this->files->get(
            $this->tempDir.'/app/Filament/Resources/TicketResource.php'
        );

        $this->assertStringContainsString("->relationship('priority', 'label')", $resourceContent);
        $this->assertStringContainsString("TextColumn::make('priority.label')", $resourceContent);
    }

    public function test_belongs_to_falls_back_to_first_string_column(): void
    {
        $this->createSchemaFile('Warehouse', '    public string $location_code;
    public string $city;
');

        $content = <<<'PHP'
<?php

namespace App\Schemas;

use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Attributes\Relations\BelongsTo;
use SchemaCraft\Schema;
use SchemaCraft\Traits\TimestampsSchema;

class ShipmentSchema extends Schema
{
    use TimestampsSchema;

    #[Primary]
    #[AutoIncrement]
    public int $id;

    public string $tracking_number;

    #[BelongsTo(\App\Models\Warehouse::class)]
    public \App\Models\Warehouse $warehouse;
}
PHP;

        $path = $this->tempDir.'/app/Schemas/ShipmentSchema.php';
        $this->files->put($path, $content);
        require_once $path;

        $this->artisan('schema:filament', ['schema' => 'ShipmentSchema'])
            ->assertSuccessful();

        $resourceContent = $this->files->get(
            $this->tempDir.'/app/Filament/Resources/ShipmentResource.php'
        );

        $this->assertStringContainsString("->relationship('warehouse', 'location_code')", $resourceContent);
        $this->assertStringContainsString("TextColumn::make('warehouse.location_code')", $resourceContent);
    }

    public function test_belongs_to_falls_back_to_name_when_schema_not_found(): void
    {
        $content = <<<'PHP'
<?php

namespace App\Schemas;

use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Attributes\Relations\BelongsTo;
use SchemaCraft\Schema;
use SchemaCraft\Traits\TimestampsSchema;

class OrphanItemSchema extends Schema
{
    use TimestampsSchema;

    #[Primary]
    #[AutoIncrement]
    public int $id;

    public string $description;

    #[BelongsTo(\App\Models\UnknownModel::class)]
    public \App\Models\UnknownModel $unknownModel;
}
PHP;

        $path = $this->tempDir.'/app/Schemas/OrphanItemSchema.php';
        $this->files->put($path, $content);
        require_once $path;

        $this->artisan('schema:filament', ['schema' => 'OrphanItemSchema'])
            ->assertSuccessful();

        $resourceContent = $this->files->get(
            $this->tempDir.'/app/Filament/Resources/OrphanItemResource.php'
        );

        $this->assertStringContainsString("->relationship('unknownModel', 'name')", $resourceContent);
        $this->assertStringContainsString("TextColumn::make('unknownModel.name')", $resourceContent);
    }

    public function test_belongs_to_prefers_title_over_label(): void
    {
        $this->createSchemaFile('Section', '    public string $label;
    public string $title;
');

        $content = <<<'PHP'
<?php

namespace App\Schemas;

use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Attributes\Relations\BelongsTo;
use SchemaCraft\Schema;
use SchemaCraft\Traits\TimestampsSchema;

class ParagraphSchema extends Schema
{
    use TimestampsSchema;

    #[Primary]
    #[AutoIncrement]
    public int $id;

    public string $body;

    #[BelongsTo(\App\Models\Section::class)]
    public \App\Models\Section $section;
}
PHP;

        $path = $this->tempDir.'/app/Schemas/ParagraphSchema.php';
        $this->files->put($path, $content);
        require_once $path;

        $this->artisan('schema:filament', ['schema' => 'ParagraphSchema'])
            ->assertSuccessful();

        $resourceContent = $this->files->get(
            $this->tempDir.'/app/Filament/Resources/ParagraphResource.php'
        );

        $this->assertStringContainsString("->relationship('section', 'title')", $resourceContent);
        $this->assertStringContainsString("TextColumn::make('section.title')", $resourceContent);
    }

    // ─── Relation manager references ──────────────────────

    public function test_relation_managers_use_resource_class_prefix(): void
    {
        $this->createSchemaFile('Comment', '    public string $body;
');

        $content = <<<'PHP'
<?php

namespace App\Schemas;

use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Attributes\Relations\HasMany;
use SchemaCraft\Schema;
use SchemaCraft\Traits\TimestampsSchema;

class AuthorSchema extends Schema
{
    use TimestampsSchema;

    #[Primary]
    #[AutoIncrement]
    public int $id;

    public string $name;

    #[HasMany(\App\Models\Comment::class)]
    public array $comments;
}
PHP;

        $path = $this->tempDir.'/app/Schemas/AuthorSchema.php';
        $this->files->put($path, $content);
        require_once $path;

        $this->artisan('schema:filament', ['schema' => 'AuthorSchema'])
            ->assertSuccessful();

        $resourceContent = $this->files->get(
            $this->tempDir.'/app/Filament/Resources/AuthorResource.php'
        );

        // Should use ResourceClass\RelationManagers\... (not just RelationManagers\...)
        $this->assertStringContainsString('AuthorResource\RelationManagers\CommentsRelationManager::class', $resourceContent);
        // Ensure there's no standalone "RelationManagers\" without the resource class prefix
        $this->assertDoesNotMatchRegularExpression('/^\s+RelationManagers\\\\/m', $resourceContent);
    }
}
