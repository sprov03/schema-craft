# Custom Generators

Custom generators let you build interactive code-generation wizards that run inside the SchemaCraft Visualizer. Each generator collects input from the user through a step-by-step wizard, then writes Blade-rendered files directly to your project.

---

## Creating a Generator

Extend `SchemaCraftGenerator` and implement `name()`, `data()`, and `templates()`. Register the class in your `schema-craft.php` config under `generators`.

```php
use SchemaCraft\Generators\Input;
use SchemaCraft\Generators\SchemaCraftGenerator;
use SchemaCraft\Generators\Template;

class CreateActionGenerator extends SchemaCraftGenerator
{
    public function name(): string
    {
        return 'Create Action';
    }

    public function data(): array
    {
        return [
            'schema'      => fn ($data) => Input::schemaSelector('Schema'),
            'action_name' => fn ($data) => Input::text('Action Name')
                ->default(class_basename($data['schema']->modelClass)),
            'target_path' => fn ($data) => Input::computed($data['schema']->actionsPath)
                ->label('Target Directory'),
        ];
    }

    public function templates(): array
    {
        return [
            Template::file(
                '[schema.actionsPath]/[action_name.title]Action.php',
                'generators.action',
            ),
        ];
    }
}
```

---

## The `data()` Method

`data()` returns an ordered associative array. Each key becomes a variable name available in Blade templates. Each value is a closure that receives the previously resolved data and returns either an `InputDefinition` or a plain scalar/array.

The wizard processes keys in order:

| Return type | Behaviour |
|---|---|
| `InputDefinition` (interactive) | Shows a UI step; waits for user input |
| `Input::computed($value)` | Displays a read-only info row; no user input needed |
| Scalar or array (bare) | Silent — stored but never shown in the UI |

```php
public function data(): array
{
    return [
        // Interactive — shows a wizard step
        'schema' => fn ($data) => Input::schemaSelector('Schema'),

        // Silent computed — derived from prior step, no UI
        'action_namespace' => fn ($data) => $data['schema']->actionsNamespace,

        // Interactive with a context-aware default
        'action_name' => fn ($data) => Input::text('Action Name')
            ->default(class_basename($data['schema']->modelClass)),

        // Displayed computed — shown as a read-only row in the wizard
        'target_path' => fn ($data) => Input::computed($data['schema']->actionsPath)
            ->label('Target Directory')
            ->description('Where the generated files will be written.'),

        // Force a value — bypasses the UI entirely
        'connection' => fn ($data) => Input::text('Connection')
            ->value($data['schema']->connection ?? 'default'),
    ];
}
```

---

## Input Types

All interactive inputs are created with the `Input` factory class.

### `Input::text`

Free-text field. Resolves to a plain string.

```php
Input::text('Class Name')
    ->default('MyClass')
    ->description('Used as the PHP class name.')
    ->help('Must be a valid PHP identifier.')
```

### `Input::select`

Dropdown with predefined options. Resolves to the selected key.

```php
Input::select('Type', options: [
    'create' => 'Create',
    'update' => 'Update',
    'delete' => 'Delete',
])
```

### `Input::boolean`

Checkbox toggle. Resolves to `true` or `false`.

```php
Input::boolean('Soft Delete', default: false)
```

### `Input::schemaSelector`

Searchable dropdown of all discovered schemas. Resolves to a `GeneratorSchemaContext`.

```php
Input::schemaSelector('Schema')
Input::schemaSelector('Schema', selectColumns: true)
Input::schemaSelector('Schema', selectColumns: true, selectRelationships: true)
```

### `Input::schemaColumn`

Single column picker sourced from a prior `schemaSelector` step. Resolves to a column name string.

```php
Input::schemaColumn('Primary Column', selectorKey: 'schema')
```

### `Input::schemaColumnCombobox`

Like `schemaColumn` but also allows typing a custom value not in the list. Resolves to a plain string.

```php
Input::schemaColumnCombobox('Sort Column', selectorKey: 'schema')
```

### `Input::schemaColumns`

Multi-column picker. Resolves to an array of column name strings.

```php
Input::schemaColumns('Include Columns', selectorKey: 'schema')
```

### `Input::schemaFieldPicker`

Recursive dot-path field picker. Lets users select flat columns and nested relationship fields (e.g. `comments.*.body`, `author.name`). Resolves to `string[]` of dot-path strings.

```php
Input::schemaFieldPicker('Fields', selectorKey: 'schema')
```

### `Input::nestedFieldSelector`

Two-section tree picker. Shows flat top-level columns (with Select All/Deselect All) plus expandable relationship rows, each loading sub-fields lazily from the related schema. Resolves to a `NestedFieldSelection` value object containing `GeneratorColumn[]` for top-level fields and `NestedRelationshipSelection[]` for relationship sub-fields.

```php
Input::nestedFieldSelector('Fields', selectorKey: 'schema')
```

Use this instead of `schemaFieldPicker` when you need the consumer to distinguish between top-level columns and relationship sub-fields as separate groups in the generated output.

### `Input::selectResourceDirectory`

Filament resource directory picker, populated from panel discovery and config. Resolves to a `ResourceDirectoryValue`.

```php
Input::selectResourceDirectory('Resource Directory')
    ->default('app/Filament/Admin/Resources')
```

### `Input::filamentPlacements`

Filament placement picker — lets the user choose one or more page/slot targets to wire up snippets. Resolves to an array of placement descriptors.

```php
Input::filamentPlacements('Wire Up To')
```

### `Input::computed`

Displayed as a read-only info row in the wizard. No user input. The value must be JSON-serialisable.

```php
Input::computed($data['schema']->actionsPath)
    ->label('Target Directory')
    ->description('Where the file will be written.')
```

### `Input::custom`

Calls a custom input type registered via `InputTypeRegistry`.

```php
Input::custom('myType', 'Label', extra: ['option' => 'value'])
```

---

## InputDefinition Fluent Methods

Every `InputDefinition` supports the following fluent setters:

| Method | Effect |
|---|---|
| `->value(mixed $raw)` | Force a raw value; skip the UI step entirely. The value is still passed through the input type's `resolve()` method. |
| `->default(mixed $value)` | Pre-fill the UI field with this value. |
| `->label(string $text)` | Override the label (useful on computed inputs). |
| `->description(string $text)` | Short hint shown below the field label. |
| `->help(string $text)` | Longer text shown in an info tooltip next to the label. |

The `->value()` method works on **any** input type. The resolved result is stored exactly as if the user had provided it, and the wizard step is skipped silently.

```php
// Pre-select a schema without showing the picker
'schema' => fn ($data) => Input::schemaSelector('Schema')
    ->value(App\Schemas\PostSchema::class),

// Resolve a resource directory without showing the picker
'resource_directory' => fn ($data) => Input::selectResourceDirectory('Resource Directory')
    ->value('app/Filament/Admin/Resources'),
```

---

## Template System

### `Template::file`

Render a Blade stub to a single output path.

```php
Template::file(
    outputPath: 'app/Actions/[schema.model.title]/[action_name.title]Action.php',
    viewName:   'generators.my-generator.action',
)

// With extra variables
Template::file(
    'app/Http/Resources/[schema.model.title]Resource.php',
    'generators.resource',
    ['className' => '[schema.model.title]Resource'],
)
```

### `Template::forEach`

Render templates once per item in a dot-path iterable.

```php
Template::forEach(
    iterableKey: 'schema.relationships',
    as: 'relationship',
    templates: [
        Template::file(
            'app/Livewire/[relationship.name.title]Table.php',
            'generators.livewire.table',
        ),
    ],
)
```

Optional `$filter` values: `'collection'` (only hasMany/morphMany) or `'singular'` (only belongsTo/hasOne/morphOne).

### `Template::forEachRelationship`

Sugar for `Template::forEach` scoped to a schema's relationships.

```php
...Template::forEachRelationship('schema', 'relationship', [
    Template::file(
        'app/RelationManagers/[relationship.name.title]RelationManager.php',
        'generators.relation-manager',
    ),
], 'collection')
```

### `Template::inline`

Inserts rendered content into an **existing** file. Returns an `InlineTemplate` builder — chain fluent methods and pass the result to `inlineTemplates()`.

```php
public function inlineTemplates(array $data): array
{
    return array_map(function ($placement) {
        return Template::inline('generators.my-generator.wire-action')
            ->into($placement['file'])
            ->anchor($placement['anchor'])
            ->after($placement['searchPattern']);
    }, $data['placements']);
}
```

---

## InlineTemplate Builder

| Method | Description |
|---|---|
| `->into(string $path)` | Target file, relative to `base_path()`. Supports `[bracket]` placeholders. |
| `->anchor(string $text)` | Find this string first, then search for the pattern after it. Disambiguates when the pattern appears multiple times. |
| `->after(string $pattern)` | Insert rendered content after this literal string. |
| `->before(string $pattern)` | Insert rendered content before this literal string. |
| `->afterRegex(string $regex)` | Insert after the first regex match. |
| `->beforeRegex(string $regex)` | Insert before the first regex match. |
| `->append()` | Append to the end of the file. |
| `->prepend()` | Prepend to the beginning of the file. |
| `->with(array $vars)` | Extra variables merged into the Blade template for this insertion only. |

---

## Template Path Interpolation

Output paths (and string extra-variable values) support `[bracket.dot.path]` placeholders. The path resolves against the fully typed data. Strings are passed through `NameChain` case helpers.

```
[schema.model.title]          → "UserProfile"
[schema.model.camel]          → "userProfile"
[schema.model.snake]          → "user_profile"
[schema.model.kebab]          → "user-profile"
[schema.model.plural.title]   → "UserProfiles"
[schema.model.plural.snake]   → "user_profiles"
[schema.actionsPath]          → "app/Models/Actions"
[resource_directory]          → "app/Filament/Admin/Resources"
[resource_directory.path]     → "app/Filament/Admin/Resources"
[action_name.title]           → "Create"  (Input::text value run through studly)
[relationship.name.title]     → "Comments" (per-iteration variable)
```

---

## GeneratorSchemaContext

The object resolved by `Input::schemaSelector`. Available in templates as `$schema`.

### Model Name (NameChain)

```php
$schema->model->title         // "UserProfile"
$schema->model->camel         // "userProfile"
$schema->model->snake         // "user_profile"
$schema->model->kebab         // "user-profile"
$schema->model->plural->title // "UserProfiles"
$schema->model->plural->snake // "user_profiles"
```

### Schema Metadata

```php
$schema->schemaClass      // "App\Schemas\PostSchema"
$schema->modelClass       // "App\Models\Post"
$schema->tableName        // "posts"
$schema->connection       // DB connection name, or null
$schema->fillable         // string[] of fillable attribute names
$schema->hidden           // string[] of hidden attribute names
$schema->defaultWith      // string[] of default eager-loaded relationships
$schema->titleColumns     // string[] of #[Title] column names
$schema->hasTimestamps    // bool
$schema->hasSoftDeletes   // bool
$schema->actionsNamespace // "App\Models\Actions"
$schema->actionsPath      // "app/Models/Actions"
```

### Column Collections

```php
$schema->columns           // User-selected (or all) GeneratorColumn[]
$schema->allColumns        // All GeneratorColumn[]
$schema->primaryKey        // First primary GeneratorColumn, or null
$schema->titleColumn       // First #[Title] column, falls back to primaryKey
$schema->column('email')   // Find column by name or null
$schema->hasColumn('email')// bool
```

### Filtered Column Helpers

```php
$schema->foreignKeyColumns()  // Columns ending in _id
$schema->searchableColumns()  // String columns, excluding FKs / PKs / timestamps
$schema->fillableColumns()    // Columns in $fillable
```

### Relationship Collections

```php
$schema->relationships          // User-selected (or all) GeneratorRelationship[]
$schema->allRelationships       // All GeneratorRelationship[]
$schema->relationship('author') // Find relationship by name or null
```

---

## GeneratorColumn

Every `GeneratorColumn` proxies all `ColumnDefinition` properties and adds code-generation helpers.

### Core Properties (via `ColumnDefinition`)

```php
$column->name         // "created_by_user_id"
$column->columnType   // "unsignedBigInteger"
$column->nullable     // bool
$column->primary      // bool
$column->castType     // e.g. "App\Casts\MoneyType" or null
$column->default      // default value or null
```

### Name Helpers

```php
$column->camelName()   // "createdByUserId"
$column->studlyName()  // "CreatedByUserId"
$column->humanName()   // "Created By User Id"
```

### Type Helpers

```php
$column->phpType()         // "int", "string", "bool", "float", "array", "CarbonInterface"
$column->phpTypeNullable() // "?int", "string", etc.
```

### Boolean Helpers

```php
$column->isFK()         // true if name ends with _id
$column->isPrimary()    // true if marked primary
$column->isTimestamp()  // true if created_at / updated_at
$column->isSoftDelete() // true if deleted_at
$column->isEnum()       // true if castType is a BackedEnum
$column->enumClass()    // BackedEnum FQCN or null
```

### Code Generation Helpers

```php
$column->asMethodParam()          // "string $name" or "?string $name = null"
$column->asAssignment('$model')   // "$model->name = $name;" or associate() chain
$column->relationshipName()       // "createdByUser" (strips _id, camelCase)
$column->fakerValue()             // '$faker->safeEmail()'
$column->asFilamentField()        // 'TextInput::make(\'name\')...'
$column->asFilamentColumn()       // 'TextColumn::make(\'name\')...'
$column->asFilamentEntry()        // 'TextEntry::make(\'name\')...'
```

---

## ResourceDirectoryValue

Resolved by `Input::selectResourceDirectory`. Wraps a relative path alongside its derived PSR-4 namespace.

```php
$resource_directory->path      // "app/Filament/Admin/Resources"
$resource_directory->namespace // "App\Filament\Admin\Resources"
(string) $resource_directory   // "app/Filament/Admin/Resources"
```

In Blade templates:

```php
namespace {!! $resource_directory->namespace !!}\{!! $schema->model->plural->title !!};

// Output path interpolation
'[resource_directory]/[schema.model.title]Resource.php'
// → "app/Filament/Admin/Resources/PostResource.php"
```

---

## Additional Generator Methods

### `inlineTemplates(array $data): array`

Return `InlineTemplate` builders for snippet insertion into existing files.

```php
public function inlineTemplates(array $data): array
{
    return [
        Template::inline('generators.my-generator.route-entry')
            ->into('routes/api.php')
            ->afterRegex('/Route::middleware\([^)]+\)->group\(/'),
    ];
}
```

### `templateData(): array`

Extra variables merged into every Blade template at render time.

```php
public function templateData(): array
{
    return [
        'author' => config('app.name'),
    ];
}
```

---

## Example: Full Generator

```php
use SchemaCraft\Generators\Input;
use SchemaCraft\Generators\SchemaCraftGenerator;
use SchemaCraft\Generators\Template;

class FilamentResourceGenerator extends SchemaCraftGenerator
{
    public function name(): string
    {
        return 'Filament Resource';
    }

    public function data(): array
    {
        return [
            'schema' => fn ($data) => Input::schemaSelector(
                'Schema',
                selectColumns: true,
                selectRelationships: true,
            ),
            'resource_directory' => fn ($data) => Input::selectResourceDirectory('Resource Directory')
                ->default('app/Filament/Admin/Resources'),
            'with_soft_deletes'  => fn ($data) => Input::boolean('Soft Deletes')
                ->default($data['schema']->hasSoftDeletes),
            'target_path'        => fn ($data) => Input::computed($data['resource_directory']->path)
                ->label('Output Directory'),
        ];
    }

    public function templates(): array
    {
        return [
            Template::file(
                '[resource_directory]/[schema.model.plural.title]Resource.php',
                'generators.filament.resource',
            ),
            Template::file(
                '[resource_directory]/[schema.model.plural.title]Resource/Pages/List[schema.model.plural.title].php',
                'generators.filament.pages.list',
            ),
            Template::file(
                '[resource_directory]/[schema.model.plural.title]Resource/Pages/Create[schema.model.title].php',
                'generators.filament.pages.create',
            ),
            Template::file(
                '[resource_directory]/[schema.model.plural.title]Resource/Pages/Edit[schema.model.title].php',
                'generators.filament.pages.edit',
            ),
        ];
    }
}
```
