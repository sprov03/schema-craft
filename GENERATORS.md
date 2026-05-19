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

## Template Engine

Templates are standard **Laravel Blade**. Files end in `.blade.php` and live under `resources/views/generators/`. Every Blade directive works: `@if`, `@foreach`, `@include`, `@php`, `{{ }}` (escaped), `{!! !!}` (raw).

### `@php` blocks are unrestricted

Inside a `@php` block you have full PHP. Use any class, define closures, build arrays, or pre-compute derived data the rest of the template needs.

```blade
@php
    use Illuminate\Support\Str;

    $imports = collect($schema->relationships)
        ->map(fn ($r) => "use {$r->relatedModelClass};")
        ->unique()
        ->sort()
        ->implode("\n");
@endphp
```

### `{!! $phpOpenTag !!}` for PHP file output

`$phpOpenTag` is automatically injected as the literal `<?php` opening tag so Blade doesn't try to parse it. Use it at the top of any template that renders a PHP file:

```blade
{!! $phpOpenTag !!}

namespace App\Foo;
```

### Partials via `@include`

Templates can include other Blade files; included partials inherit the parent scope, and you can pass extras as the second argument.

```blade
@foreach ($schema->columns as $column)
    @include('generators.my-generator.partials.field', ['column' => $column])
@endforeach
```

### Available variables

Every Blade template sees the union of:

1. All keys from `data()` — as their fully resolved typed objects, not raw input
2. Everything returned by `templateData()`
3. The per-iteration variable when rendered inside `Template::forEach` (under the `as` key)
4. Any `extraVariables` passed to `Template::file()`
5. `$phpOpenTag`

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

### `InlineTemplate::raw()`

For short literal snippets — a `use` statement, a single registration line — you can skip the Blade view entirely and pass the content directly:

```php
InlineTemplate::raw("use {$fqcn};\n")
    ->into('[service_path]')
    ->beforeRegex('/^class /m');
```

Duplicate detection still applies, so re-running the generator won't insert the same `use` twice.

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

## GeneratorRelationship

Each entry in `$schema->relationships` (and the per-item variable inside `Template::forEachRelationship`) is a `GeneratorRelationship`.

### Core Properties

```php
$relationship->name              // NameChain — e.g. ->title = "Comments", ->camel = "comments"
$relationship->relatedModel      // NameChain — e.g. ->title = "Comment"
$relationship->relatedModelClass // "App\Models\Comment"
```

### Boolean Helpers

```php
$relationship->isCollection() // hasMany, hasManyThrough, belongsToMany, morphMany, morphToMany
$relationship->isSingular()   // belongsTo, hasOne, morphOne, morphTo
```

### Convenience

```php
$relationship->relatedTitleColumn() // First #[Title] column on the related schema, or PK fallback
```

### Pass-through from `RelationshipDefinition`

Properties on the underlying `RelationshipDefinition` are exposed via magic `__get`. Useful ones:

```php
$relationship->type           // "hasMany", "belongsTo", "morphMany", etc.
$relationship->nullable       // bool
$relationship->foreignColumn  // FK column name, or null
$relationship->pivotTable     // pivot table name for many-to-many, or null
$relationship->morphName      // morph name for polymorphic, or null
$relationship->inverse        // bool
```

In path interpolation: `[relationship.name.title]` → `"Comments"`, `[relationship.name.singular.title]` → `"Comment"`.

---

## NestedFieldSelection

Resolved by `Input::nestedFieldSelector`. Available in templates as whatever key you used in `data()`.

### Properties

```php
$fields->columns                       // GeneratorColumn[] of top-level selected columns
$fields->relationships                 // NestedRelationshipSelection[]
$fields->isEmpty()                     // true if nothing was selected
$fields->hasCollectionRelationships()  // bool
$fields->hasSingularRelationships()    // bool
```

Each `NestedRelationshipSelection`:

```php
$relSel->relationship    // GeneratorRelationship (use ->name, ->relatedModel, etc.)
$relSel->selectedFields  // GeneratorColumn[] selected on the related schema
$relSel->isCollection()  // bool
$relSel->isSingular()    // bool
$relSel->type()          // "collection" or "singular"
```

Typical template usage:

```blade
@foreach ($fields->columns as $column)
    public {!! $column->phpTypeNullable() !!} ${{ $column->camelName() }};
@endforeach

@foreach ($fields->relationships as $relSel)
    public {!! $relSel->isCollection() ? 'array' : $relSel->relationship->relatedModel->title !!} ${{ $relSel->relationship->name->camel }};
@endforeach
```

---

## FilamentPlacement

Resolved by `Input::filamentPlacements` as `array[]`. Each placement is an associative array describing one wiring target:

```php
[
    'file'              => 'app/Filament/Admin/Pages/Settings.php',
    'anchor'            => 'getHeaderActions(): array',
    'searchPattern'     => 'return [',
    'isRelationManager' => false,
]
```

Drive `inlineTemplates()` from the list:

```php
public function inlineTemplates(array $data): array
{
    return array_map(function ($placement) {
        return Template::inline('generators.my-generator.page-action')
            ->into($placement['file'])
            ->anchor($placement['anchor'])
            ->after($placement['searchPattern']);
    }, $data['placements']);
}
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

### `afterRun(array $data): void`

Hook for side effects that aren't file writes. Runs **only during actual run, not during preview**. Receives the fully resolved `$data` array.

```php
public function afterRun(array $data): void
{
    Artisan::call('cache:clear');
}
```

Prefer `inlineTemplates()` over `afterRun()` for modifying existing files — inlines appear in the preview diff, `afterRun()` runs silently. Reserve `afterRun()` for things the preview shouldn't show (cache clears, shell commands, queued jobs).

---

## Execution Model

Each run executes in three passes against an in-memory file cache:

1. **Templates** — every `Template::file` / `forEach` is rendered through Blade. New files seed the cache with rendered content; existing files seed the cache with disk content.
2. **Inlines** — every `InlineTemplate` reads from the cache, applies its insertion, writes back. Multiple inlines targeting the same file compound correctly — each sees the previous insertion already applied.
3. **Results** — each touched path is compared with disk. New or changed files are written; unchanged files are skipped.

**Idempotency is free.** Inline insertions check `str_contains($current, trim($snippet))` before writing, so re-running a generator never inserts the same snippet twice.

**Preview and run share this code path.** Preview returns the diff as JSON without writing; run writes. Anything that should be visible in the preview must happen in `templates()` or `inlineTemplates()`, not `afterRun()`.

---

## Idioms

### Selector ordering

`schemaColumn`, `schemaColumns`, `schemaColumnCombobox`, `schemaFieldPicker`, and `nestedFieldSelector` read their options from a prior `schemaSelector` step (via `selectorKey`, default `'schema'`). **Put the schema step first.** If a dependent step appears before its selector, its option list is empty.

### Conditional input type inside a callback

A `data()` callback can return different input types based on prior context. The wizard re-evaluates each callback every time it advances, so the type can switch based on data the user has already entered.

```php
'title_attribute' => fn ($data) => ! empty($data['schema']->titleColumns)
    ? Input::computed($data['schema']->titleColumns[0])->label('Title Attribute')
    : Input::schemaColumnCombobox('Title Attribute'),
```

### Spread `Template::forEach` into `templates()`

`Template::forEach` and `forEachRelationship` return a `TemplateDefinition[]` array. Spread it into the parent return so per-item templates merge cleanly with single-file ones:

```php
return [
    Template::file('...', '...'),
    ...Template::forEachRelationship('schema', 'relationship', [
        Template::file('[relationship.name.title]Manager.php', '...'),
    ], 'collection'),
];
```

### Silent computeds for derived values

If a template needs a value that's purely derived from prior steps, return a scalar or object from the callback (not an `Input`). It becomes a template variable without showing in the wizard.

```php
'service_class' => fn ($data) => isset($data['schema'])
    ? $data['schema']->model->title.'Service'
    : null,
```

Always null-guard — callbacks are evaluated with partial context as the wizard reconstructs state across steps.

### Prefer inlines over `afterRun()` for editing existing files

Inline insertions appear in the preview diff, so the user can see what's about to change. `afterRun()` runs silently and only during the actual run. Use `afterRun()` only for non-file side effects (cache, queue, shell).

---

## Common Mistakes

| Mistake | Fix |
|---|---|
| Flipping `Template::file()` arguments | Order is `(outputPath, viewName)`. Mnemonic: "where it goes, then what renders." |
| Returning a scalar where an `Input` was meant | A scalar is treated as a silent computed and **never** prompts the user. Return `Input::*` if you wanted a prompt. |
| Forgetting `{!! $phpOpenTag !!}` | Blade will try to parse a literal `<?php` and break. Always use the injected variable. |
| Forgetting null-guards in `data()` callbacks | Callbacks run with partial `$resolved` during reconstruction. Always `isset()` or `?? null` before chaining. |
| Blade view name collisions | View names are a global namespace. Put generator templates under a subdirectory: `resources/views/generators/<generator-name>/...`. |
| Using `afterRun()` to modify existing files | The change won't appear in the preview. Use `inlineTemplates()` instead. |
| Dependent input before its selector | `schemaColumn` etc. need their `schemaSelector` step to appear earlier in `data()`. |
| Using `->value()` when you meant `->default()` | `->value()` bypasses the UI entirely (silent). `->default()` pre-fills a still-visible field. |

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
