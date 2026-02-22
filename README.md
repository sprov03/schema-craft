# SchemaCraft Guide

Define your database schema, Eloquent behavior, and migrations in one place — with typed PHP properties.

---

## Quick Reference

```
php artisan schema-craft:install                     # publish BaseModel
php artisan make:schema Post                         # schema + model
php artisan make:schema Owner Dog Walk               # multiple at once
php artisan make:schema Post --uuid --soft-deletes   # options apply to all
php artisan schema:status                            # diff schemas vs database
php artisan schema:migrate --run                     # generate + run migrations

# API Code Generation
php artisan schema:generate PostSchema               # full API stack
php artisan schema:generate PostSchema --action=cancel  # add custom action
php artisan schema:generate PostSchema --force       # overwrite existing

# SDK Client Package Generation
php artisan schema:generate-sdk                         # generate SDK from all API schemas
php artisan schema:generate-sdk --path=packages/my-sdk  # custom output path
php artisan schema:generate-sdk --name=acme/my-sdk      # custom package name
php artisan schema:generate-sdk --force                 # overwrite existing

# Relationships (dev-only)
php artisan schema-craft:relationship "User->belongsTo(Account)"
php artisan schema-craft:relationship "User->belongsTo(Account)->hasMany(User)"
php artisan schema-craft:relationship "User->$owner:BelongsTo(Account)->$users:HasMany(User)"

# Schema Visualizer (dev-only, local env)
# Visit /_schema-craft in your browser
```

```php
// Primary key — real PHP types with attributes
#[Primary] #[AutoIncrement] public int $id;   // auto-increment BIGINT
#[Primary] #[ColumnType('uuid')] public string $id;  // UUID PK
#[Primary] #[ColumnType('ulid')] public string $id;  // ULID PK

// Schema property → column
public string $name;                          // varchar(255), NOT NULL
public ?string $bio;                          // varchar(255), nullable
public int $views = 0;                        // integer, default 0
public PostStatus $status = PostStatus::Draft;// varchar, cast to enum

// Type overrides
#[Text] public string $body;                  // TEXT
#[Decimal(10,2)] #[Unsigned] public float $p; // unsigned decimal(10,2)
#[BigInt] public int $total;                  // bigInteger
#[Length(100)] public string $subtitle;       // varchar(100)
#[Date] public Carbon $birthday;             // DATE

// Column modifiers
#[Unique] public string $email;
#[Index] public string $slug;
#[Fillable] public string $title;
#[Hidden] public string $password;

// Expression defaults
#[DefaultExpression('CURRENT_TIMESTAMP')]
public ?Carbon $verified_at;                  // SQL expression default

// Relationships — attribute declares the type, property type is for IDE accuracy
#[BelongsTo(User::class)] public User $author;               // creates author_id FK
#[BelongsTo(User::class)] public ?User $editor;              // nullable FK
#[HasMany(Comment::class)] public Collection $comments;       // no column on this table
#[BelongsToMany(Tag::class)] public Collection $tags;         // creates pivot table
#[MorphTo('commentable')] public Model $commentable;          // type + id columns

// Non-standard FK column type
#[BelongsTo(User::class)]
#[ColumnType('unsignedInteger')] public User $legacyUser;    // int instead of bigint FK

// Rename without data loss
#[RenamedFrom('old_title')] public string $title;

// Traits
use TimestampsSchema;                         // created_at, updated_at
use SoftDeletesSchema;                        // deleted_at

// Validation Rules — auto-inferred from schema
#[Rules('min:3', 'regex:/^[a-z]/')]           // append custom rules
public string $slug;
PostSchema::createRules(['title', 'slug'])->toArray();  // for create
PostSchema::updateRules(['title', 'slug'])->toArray();  // for update
```

---

## Table of Contents

- [Installation](#installation)
- [Getting Started](#getting-started)
  - [Creating a Schema](#creating-a-schema)
  - [Creating a Model](#creating-a-model)
  - [Generating Migrations](#generating-migrations)
- [Column Types](#column-types)
  - [Type Inference](#type-inference)
  - [Primary Keys](#primary-keys)
  - [Strings](#strings)
  - [Integers](#integers)
  - [Floats and Decimals](#floats-and-decimals)
  - [Booleans](#booleans)
  - [Dates and Times](#dates-and-times)
  - [JSON / Arrays](#json--arrays)
  - [Enums](#enums)
  - [Custom Casts](#custom-casts)
- [Nullability and Defaults](#nullability-and-defaults)
  - [Expression Defaults](#expression-defaults)
- [Column Modifiers](#column-modifiers)
  - [Unique](#unique)
  - [Index](#index)
  - [Composite Indexes](#composite-indexes)
  - [Unsigned](#unsigned)
  - [Custom Length](#custom-length)
  - [Custom Cast](#custom-cast)
  - [Column Type Override](#column-type-override)
- [Relationships](#relationships)
  - [BelongsTo](#belongsto)
  - [HasOne / HasMany](#hasone--hasmany)
  - [BelongsToMany](#belongstomany)
  - [Polymorphic: MorphTo](#polymorphic-morphto)
  - [Polymorphic: MorphOne / MorphMany](#polymorphic-morphone--morphmany)
  - [Polymorphic: MorphToMany](#polymorphic-morphtomany)
  - [Foreign Key Options](#foreign-key-options)
- [Timestamps and Soft Deletes](#timestamps-and-soft-deletes)
- [Model Behavior](#model-behavior)
  - [Fillable](#fillable)
  - [Hidden](#hidden)
  - [Eager Loading](#eager-loading)
  - [Cast Override](#cast-override)
  - [Custom Table Name](#custom-table-name)
- [Renaming Columns](#renaming-columns)
- [Validation Rules](#validation-rules)
  - [Auto-Inferred Rules](#auto-inferred-rules)
  - [Create vs Update Context](#create-vs-update-context)
  - [Schema-Level Rule Overrides](#schema-level-rule-overrides)
  - [RuleSet Merging in Requests](#ruleset-merging-in-requests)
  - [RuleSet Filtering](#ruleset-filtering)
- [API Code Generation](#api-code-generation)
  - [Generating a Full API Stack](#generating-a-full-api-stack)
  - [What Gets Generated](#what-gets-generated)
  - [Adding Custom Actions](#adding-custom-actions)
  - [Generated Controller](#generated-controller)
  - [Generated Service](#generated-service)
  - [Generated Requests](#generated-requests)
  - [Generated Resource](#generated-resource)
  - [Publishing Stubs](#publishing-stubs)
- [SDK Client Generation](#sdk-client-generation)
  - [Generating the SDK](#generating-the-sdk)
  - [SDK Package Structure](#sdk-package-structure)
  - [Generated Connector](#generated-connector)
  - [Generated Data DTOs](#generated-data-dtos)
  - [Generated Resources](#generated-resources)
  - [Generated Client](#generated-client)
  - [Custom Actions in the SDK](#custom-actions-in-the-sdk)
  - [Publishing SDK Stubs](#publishing-sdk-stubs)
- [Artisan Commands](#artisan-commands)
  - [schema-craft:install](#schemacraftinstall)
  - [make:schema](#makeschema)
  - [schema:status](#schemastatus)
  - [schema:migrate](#schemamigrate)
  - [schema:generate](#schemagenerate)
  - [schema:generate-sdk](#schemageneratesdk)
  - [schema-craft:relationship](#schemacraftrelationship)
- [Schema Visualizer](#schema-visualizer)
  - [Health Dashboard](#health-dashboard)
  - [Apply Fix from the UI](#apply-fix-from-the-ui)
  - [Explorer](#explorer)
- [Full Example](#full-example)

---

## Installation

Run the install command to publish the `BaseModel` class:

```bash
php artisan schema-craft:install
```

This creates `app/Models/BaseModel.php` — an abstract class that all your generated models will extend:

```php
namespace App\Models;

use SchemaCraft\SchemaModel;

abstract class BaseModel extends SchemaModel
{
    //
}
```

Add shared scopes, boot logic, or overrides here and every model in your project inherits them.

---

## Getting Started

### Creating a Schema

A schema is a PHP class that describes your database table using typed properties. Each public property becomes a column.

```php
namespace App\Schemas;

use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Schema;
use SchemaCraft\Traits\TimestampsSchema;

class TagSchema extends Schema
{
    use TimestampsSchema;

    #[Primary]
    #[AutoIncrement]
    public int $id;

    public string $name;

    public string $slug;
}
```

Or generate one (or several) with artisan:

```bash
php artisan make:schema Tag
php artisan make:schema Owner Dog Walk
```

### Creating a Model

Every schema needs a model. The model extends `BaseModel` and points to its schema class:

```php
namespace App\Models;

use App\Schemas\TagSchema;

/** @mixin TagSchema */
class Tag extends BaseModel
{
    protected static string $schema = TagSchema::class;
}
```

The `@mixin` annotation gives your IDE autocomplete for all schema properties.

`make:schema` generates both the schema and model for you — wired together out of the box.

From here, it's just normal Laravel:

```php
$tag = Tag::where('slug', 'laravel')->first();
$tag->name; // IDE knows this is a string
```

### Generating Migrations

Check what's out of sync:

```bash
php artisan schema:status
```

Generate migration files:

```bash
php artisan schema:migrate
```

Generate and run immediately:

```bash
php artisan schema:migrate --run
```

---

## Column Types

### Type Inference

SchemaCraft infers the database column type from the PHP type on the property. You only need attributes when you want something different from the default.

| PHP Type | Column Type | Eloquent Cast |
|---|---|---|
| `string` | `varchar(255)` | `string` |
| `int` | `integer` | `integer` |
| `float` | `double` | `double` |
| `bool` | `boolean` | `boolean` |
| `array` | `json` | `array` |
| `Carbon` | `timestamp` | `datetime` |
| Backed enum | `string` or `integer` | enum class |

### Primary Keys

Primary keys use real PHP types with `#[Primary]` and optionally `#[AutoIncrement]` or `#[ColumnType]`:

```php
use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\ColumnType;
use SchemaCraft\Attributes\Primary;

// Auto-incrementing BIGINT (default)
#[Primary]
#[AutoIncrement]
public int $id;

// UUID primary key
#[Primary]
#[ColumnType('uuid')]
public string $id;

// ULID primary key
#[Primary]
#[ColumnType('ulid')]
public string $id;
```

Use `make:schema --uuid` or `make:schema --ulid` to scaffold with UUID or ULID primary keys.

### Strings

```php
public string $name;                 // varchar(255)

#[Length(100)]
public string $subtitle;             // varchar(100)

#[Text]
public string $body;                 // TEXT

#[MediumText]
public string $content;              // MEDIUMTEXT

#[LongText]
public string $html;                 // LONGTEXT
```

**Imports:** `SchemaCraft\Attributes\Length`, `SchemaCraft\Attributes\Text`, `SchemaCraft\Attributes\MediumText`, `SchemaCraft\Attributes\LongText`

### Integers

```php
public int $count;                   // integer

#[Unsigned]
public int $views;                   // unsigned integer

#[BigInt]
public int $total;                   // bigInteger

#[SmallInt]
public int $rank;                    // smallInteger

#[TinyInt]
public int $level;                   // tinyInteger

#[BigInt]
#[Unsigned]
public int $total;                   // unsignedBigInteger
```

**Imports:** `SchemaCraft\Attributes\Unsigned`, `SchemaCraft\Attributes\BigInt`, `SchemaCraft\Attributes\SmallInt`, `SchemaCraft\Attributes\TinyInt`

### Floats and Decimals

```php
public float $rating;                // double

#[FloatColumn]
public float $score;                 // float (SQL FLOAT, not DOUBLE)

#[Decimal(10, 2)]
public float $price;                 // decimal(10,2)

#[Decimal(8, 4)]
#[Unsigned]
public float $latitude;              // unsigned decimal(8,4)
```

**Imports:** `SchemaCraft\Attributes\FloatColumn`, `SchemaCraft\Attributes\Decimal`

### Booleans

```php
public bool $is_active;             // boolean, no default
public bool $is_featured = false;   // boolean, default false
```

### Dates and Times

```php
use Illuminate\Support\Carbon;

public ?Carbon $published_at;        // nullable timestamp

#[Date]
public Carbon $birthday;             // DATE

#[Time]
public Carbon $starts_at;            // TIME

#[Year]
public int $graduation_year;         // YEAR
```

**Imports:** `SchemaCraft\Attributes\Date`, `SchemaCraft\Attributes\Time`, `SchemaCraft\Attributes\Year`

### JSON / Arrays

```php
public array $metadata = [];         // json column, cast to array
public ?array $settings;             // nullable json column
```

### Enums

Backed enums are supported directly. The column type is determined by the backing type:

```php
use App\Enums\PostStatus;

public PostStatus $status = PostStatus::Draft;
```

```php
// String-backed → varchar column
enum PostStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}

// Int-backed → integer column
enum Priority: int
{
    case Low = 1;
    case High = 3;
}
```

The cast is automatically registered to the enum class.

### Custom Casts

Classes implementing `Castable` or `CastsAttributes` are auto-detected:

```php
public ?AddressData $address;        // json column, cast to AddressData
```

---

## Nullability and Defaults

Use PHP's `?` nullable type to make a column nullable:

```php
public string $title;                // NOT NULL, required
public ?string $body;                // nullable
```

Use PHP default values to set column defaults:

```php
public int $views = 0;              // default 0
public bool $active = true;         // default true
public string $role = 'member';     // default 'member'
public PostStatus $status = PostStatus::Draft;  // default 'draft'
```

### Expression Defaults

For SQL expression defaults that can't be represented as PHP literal values, use `#[DefaultExpression]`:

```php
use SchemaCraft\Attributes\DefaultExpression;

#[DefaultExpression('CURRENT_TIMESTAMP')]
public ?Carbon $verified_at;
```

This generates `->default(DB::raw('CURRENT_TIMESTAMP'))` in the migration. Use this for any SQL expression your database supports (e.g., `CURRENT_TIMESTAMP`, `(UUID())`, etc.).

---

## Column Modifiers

### Unique

```php
use SchemaCraft\Attributes\Unique;

#[Unique]
public string $email;
```

### Index

```php
use SchemaCraft\Attributes\Index;

#[Index]
public string $slug;
```

### Composite Indexes

Apply `#[Index]` at the class level with an array of column names:

```php
use SchemaCraft\Attributes\Index;

#[Index(['status', 'published_at'])]
class PostSchema extends Schema
{
    // ...
}
```

### Unsigned

```php
use SchemaCraft\Attributes\Unsigned;

#[Unsigned]
public int $quantity;
```

### Custom Length

```php
use SchemaCraft\Attributes\Length;

#[Length(100)]
public string $subtitle;            // varchar(100) instead of 255
```

### Custom Cast

Override the auto-detected Eloquent cast:

```php
use SchemaCraft\Attributes\Cast;
use Illuminate\Database\Eloquent\Casts\AsCollection;

#[Cast(AsCollection::class)]
public array $tags;                  // json column, cast to Collection
```

### Column Type Override

Override the default column type for foreign key and polymorphic columns using `#[ColumnType]`. This is useful when the related table uses a non-standard integer size:

```php
use SchemaCraft\Attributes\ColumnType;
use SchemaCraft\Attributes\Relations\BelongsTo;
use SchemaCraft\Attributes\Relations\MorphTo;

// FK column as unsigned integer instead of default unsigned big integer
#[BelongsTo(User::class)]
#[ColumnType('unsignedInteger')]
public User $legacyUser;

// MorphTo columns as unsigned integer
#[MorphTo('taggable')]
#[ColumnType('unsignedInteger')]
public Model $taggable;
```

Without `#[ColumnType]`, BelongsTo FK columns and MorphTo `_id` columns default to `unsignedBigInteger`. Use this attribute when the related table's primary key uses a different integer type.

`#[ColumnType]` is also used on primary keys for UUID and ULID types — see [Primary Keys](#primary-keys).

---

## Relationships

Each relationship is declared with a specific attribute (`#[BelongsTo]`, `#[HasMany]`, etc.) that tells the scanner what kind of relationship it is. The property type is the real runtime type — what the IDE sees via `@mixin`.

### BelongsTo

Creates a foreign key column on this table. The property type is the related model class:

```php
use SchemaCraft\Attributes\Relations\BelongsTo;

#[BelongsTo(User::class)]
public User $author;                // creates author_id column (unsigned bigint, indexed)
```

Make it nullable with `?`:

```php
#[BelongsTo(Category::class)]
public ?Category $category;         // creates nullable category_id column
```

### HasOne / HasMany

No column is created on this table — the foreign key lives on the related table. Use the related model as the property type for `HasOne`, and `Collection` for `HasMany`:

```php
use Illuminate\Database\Eloquent\Collection;
use SchemaCraft\Attributes\Relations\HasOne;
use SchemaCraft\Attributes\Relations\HasMany;

#[HasOne(Profile::class)]
public Profile $profile;

/** @var Collection<int, Comment> */
#[HasMany(Comment::class)]
public Collection $comments;
```

If the foreign key on the related table doesn't follow convention, use `#[ForeignColumn]`:

```php
use SchemaCraft\Attributes\ForeignColumn;

/** @var Collection<int, Post> */
#[HasMany(Post::class)]
#[ForeignColumn('author_id')]       // Post.author_id instead of Post.user_id
public Collection $posts;
```

### BelongsToMany

Creates a pivot table automatically:

```php
use Illuminate\Database\Eloquent\Collection;
use SchemaCraft\Attributes\Relations\BelongsToMany;

/** @var Collection<int, Tag> */
#[BelongsToMany(Tag::class)]
public Collection $tags;             // creates post_tag pivot table
```

Customize the pivot table name and add extra columns:

```php
use SchemaCraft\Attributes\PivotTable;
use SchemaCraft\Attributes\PivotColumns;

/** @var Collection<int, Tag> */
#[BelongsToMany(Tag::class)]
#[PivotTable('taggables')]
#[PivotColumns(['order' => 'integer', 'added_by' => 'string'])]
public Collection $tags;
```

### Polymorphic: MorphTo

Creates `{name}_type` and `{name}_id` columns:

```php
use Illuminate\Database\Eloquent\Model;
use SchemaCraft\Attributes\Relations\MorphTo;

#[MorphTo('commentable')]
public Model $commentable;           // creates commentable_type + commentable_id
```

The argument to `#[MorphTo]` is the morph name.

### Polymorphic: MorphOne / MorphMany

No column created on this table:

```php
use Illuminate\Database\Eloquent\Collection;
use SchemaCraft\Attributes\Relations\MorphOne;
use SchemaCraft\Attributes\Relations\MorphMany;

#[MorphOne(Image::class, 'imageable')]
public Image $image;

/** @var Collection<int, Comment> */
#[MorphMany(Comment::class, 'commentable')]
public Collection $comments;
```

### Polymorphic: MorphToMany

Creates a polymorphic pivot table:

```php
use Illuminate\Database\Eloquent\Collection;
use SchemaCraft\Attributes\Relations\MorphToMany;

/** @var Collection<int, Tag> */
#[MorphToMany(Tag::class, 'taggable')]
public Collection $tags;
```

### Foreign Key Options

Configure foreign key behavior on `BelongsTo` relationships:

```php
use SchemaCraft\Attributes\OnDelete;
use SchemaCraft\Attributes\OnUpdate;
use SchemaCraft\Attributes\NoConstraint;
use SchemaCraft\Attributes\ForeignColumn;
use SchemaCraft\Attributes\Relations\BelongsTo;

#[BelongsTo(User::class)]
#[OnDelete('cascade')]               // ON DELETE CASCADE
public User $author;

#[BelongsTo(Team::class)]
#[OnDelete('set null')]              // ON DELETE SET NULL
#[OnUpdate('cascade')]               // ON UPDATE CASCADE
public ?Team $team;

#[BelongsTo(User::class)]
#[ForeignColumn('created_by')]       // Custom FK column name
public User $creator;

#[BelongsTo(User::class)]
#[NoConstraint]                      // Index only, no FK constraint
public User $reviewer;
```

---

## Timestamps and Soft Deletes

Use the built-in traits:

```php
use SchemaCraft\Traits\TimestampsSchema;
use SchemaCraft\Traits\SoftDeletesSchema;

class PostSchema extends Schema
{
    use TimestampsSchema;    // adds created_at, updated_at
    use SoftDeletesSchema;   // adds deleted_at
}
```

When using `SoftDeletesSchema`, your model must also use Laravel's `SoftDeletes` trait:

```php
use Illuminate\Database\Eloquent\SoftDeletes;

class Post extends BaseModel
{
    use SoftDeletes;

    protected static string $schema = PostSchema::class;
}
```

If a schema does **not** use `TimestampsSchema`, the model automatically sets `$timestamps = false`.

---

## Model Behavior

### Fillable

Mark properties as mass-assignable:

```php
use SchemaCraft\Attributes\Fillable;

#[Fillable]
public string $name;

#[Fillable]
#[BelongsTo(Category::class)]
public ?Category $category;          // makes category_id fillable
```

### Hidden

Hide properties from serialization (`toArray()`, `toJson()`):

```php
use SchemaCraft\Attributes\Hidden;

#[Hidden]
public string $password;

#[Hidden]
public array $metadata;
```

### Eager Loading

Always eager-load a relationship:

```php
use SchemaCraft\Attributes\With;

#[BelongsTo(User::class)]
#[With]
public User $author;                // loaded on every query
```

### Cast Override

Schema casts are applied automatically, but the model can always override them:

```php
class Post extends BaseModel
{
    protected static string $schema = PostSchema::class;

    protected function casts(): array
    {
        return [
            'metadata' => AsCollection::class,  // overrides schema's 'array' cast
        ];
    }
}
```

**Priority:** Model `casts()` method > Model `$casts` property > Schema auto-detected.

### Custom Table Name

By default, the table name is derived from the schema class name (`PostSchema` → `posts`, `UserProfileSchema` → `user_profiles`). Override it:

```php
class PostSchema extends Schema
{
    public static function tableName(): ?string
    {
        return 'blog_posts';
    }
}
```

---

## Renaming Columns

When you rename a property, the migration system sees it as a drop + add. To preserve data, use `#[RenamedFrom]`:

```php
use SchemaCraft\Attributes\RenamedFrom;

// Before: public string $old_title;

// After:
#[RenamedFrom('old_title')]
public string $title;
```

This generates `$table->renameColumn('old_title', 'title')` instead of dropping `old_title` and adding `title`.

You can rename and change the type at the same time:

```php
#[RenamedFrom('old_title')]
#[Text]
public ?string $title;               // rename + change type to text + make nullable
```

**After the migration runs**, the `#[RenamedFrom]` attribute becomes a no-op. You can leave it in place permanently or remove it — it won't cause any issues either way.

---

## Validation Rules

SchemaCraft can generate Laravel validation rules directly from your schema definitions. Rules are auto-inferred from column types, nullability, unique constraints, and foreign key relationships — covering ~95% of cases without any manual configuration.

### Auto-Inferred Rules

The `Schema::createRules()` and `Schema::updateRules()` methods generate validation rules by analyzing each column:

| Column Property | Inferred Rule |
|---|---|
| `NOT NULL` | `required` |
| `nullable` | `nullable` |
| `string` | `string`, `max:255` |
| `string` with `#[Length(100)]` | `string`, `max:100` |
| `text` / `mediumText` / `longText` | `string` |
| `integer` / `bigInteger` | `integer` |
| `unsignedBigInteger` | `integer`, `min:0` |
| `boolean` | `boolean` |
| `decimal` / `float` / `double` | `numeric` |
| `timestamp` / `dateTime` / `date` | `date` |
| `time` | `date_format:H:i:s` |
| `json` | `array` |
| `uuid` | `string`, `uuid` |
| `ulid` | `string`, `ulid` |
| `#[Unique]` (create) | `unique:table,column` |
| `#[Unique]` (update) | `unique:table,column,ignore:...` |
| Backed enum cast | `enum:EnumClass` |
| `#[BelongsTo(User::class)]` | `exists:users,id` |

Primary key and auto-increment columns are automatically excluded.

### Create vs Update Context

Both methods apply `required` for non-nullable columns and `nullable` for nullable columns. The key difference is how they handle unique constraints — update rules include an `ignore` clause to allow the current record to keep its own value:

```php
use App\Schemas\PostSchema;

// For creating
PostSchema::createRules(['title', 'slug', 'body', 'author_id'])->toArray();
// [
//     'title'     => ['required', 'string', 'max:255'],
//     'slug'      => ['required', 'string', 'max:255', 'unique:posts,slug'],
//     'body'      => ['nullable', 'string'],
//     'author_id' => ['required', 'integer', 'min:0', 'exists:users,id'],
// ]

// For updating — unique rules ignore the current record
PostSchema::updateRules(['title', 'slug', 'body', 'author_id'])->toArray();
// [
//     'title'     => ['required', 'string', 'max:255'],
//     'slug'      => ['required', 'string', 'max:255', 'unique:posts,slug,ignore:$this->route('post')'],
//     'body'      => ['nullable', 'string'],
//     'author_id' => ['required', 'integer', 'min:0', 'exists:users,id'],
// ]
```

### Schema-Level Rule Overrides

Use the `#[Rules]` attribute to append additional validation rules to a schema property. These are additive — they extend the auto-inferred rules:

```php
use SchemaCraft\Attributes\Rules;

#[Rules('min:3')]
public string $title;           // auto: required, string, max:255 → adds: min:3

#[Rules('min:3', 'regex:/^[a-z]/')]
public string $slug;            // adds both min:3 and regex rule

#[Rules('email')]
public string $email;           // adds email validation
```

### RuleSet Merging in Requests

Both `createRules()` and `updateRules()` return a `RuleSet` object. Use `merge()` in your FormRequest to add business logic rules on top of schema-level rules:

```php
use App\Schemas\PostSchema;
use Illuminate\Foundation\Http\FormRequest;

class CreatePostRequest extends FormRequest
{
    public function rules(): array
    {
        return PostSchema::createRules([
            'title',
            'slug',
            'body',
            'author_id',
        ])->merge([
            'title' => ['min:10'],           // appends min:10
            'body' => ['required', 'min:50'], // replaces nullable → required, adds min:50
        ])->toArray();
    }
}
```

The `merge()` method is smart about presence rules (`required`, `nullable`, `sometimes`):

- If the override contains `required`, it replaces `nullable` or `sometimes`
- If the override contains `nullable`, it replaces `required` or `sometimes`
- Other rules are appended without duplication

This is immutable — `merge()` returns a new `RuleSet` instance.

### RuleSet Filtering

Filter which fields to include or exclude:

```php
// Only specific fields
PostSchema::createRules([
    'title', 'slug', 'body', 'status',
])->only(['title', 'slug'])->toArray();

// Exclude specific fields
PostSchema::createRules([
    'title', 'slug', 'body', 'status',
])->except('status')->toArray();

// Chaining
PostSchema::createRules([
    'title', 'slug', 'body', 'status', 'author_id',
])->except('author_id')
  ->merge(['title' => ['min:5']])
  ->toArray();
```

---

## API Code Generation

SchemaCraft can generate a full API stack from a schema class: Controller, Service (Actions), FormRequests, and Eloquent Resource. The generator reads your schema to pre-populate validation rules, service parameters, resource fields, and route definitions.

### Generating a Full API Stack

```bash
php artisan schema:generate PostSchema
```

This accepts either a short name (`PostSchema`, `Post`) or a fully-qualified class name (`App\Schemas\PostSchema`).

### What Gets Generated

| File | Location |
|---|---|
| Controller | `app/Http/Controllers/Api/PostController.php` |
| Service | `app/Models/Services/PostService.php` |
| Create Request | `app/Http/Requests/CreatePostRequest.php` |
| Update Request | `app/Http/Requests/UpdatePostRequest.php` |
| Resource | `app/Resources/PostResource.php` |

Use `--force` to overwrite existing files:

```bash
php artisan schema:generate PostSchema --force
```

### Adding Custom Actions

To add a new action (e.g., `cancel`, `archive`, `publish`) to an existing API:

```bash
php artisan schema:generate PostSchema --action=cancel
```

This does three things:

1. **Creates** `app/Http/Requests/CancelPostRequest.php` — an empty FormRequest for you to fill in
2. **Updates** `PostController.php` — adds the route, import, and controller method
3. **Updates** `PostService.php` — adds a stub service method

### Generated Controller

The controller follows a convention with `static apiRoutes()`, CRUD methods, and delegates business logic to the Service:

```php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\Services\PostService;
use App\Resources\PostResource;
use App\Http\Requests\CreatePostRequest;
use App\Http\Requests\UpdatePostRequest;
use Illuminate\Support\Facades\Route;

class PostController extends Controller
{
    public static function apiRoutes(): void
    {
        Route::get('posts', [PostController::class, 'getCollection']);
        Route::get('posts/{post}', [PostController::class, 'get']);
        Route::post('posts', [PostController::class, 'create']);
        Route::put('posts/{post}', [PostController::class, 'update']);
        Route::delete('posts/{post}', [PostController::class, 'delete']);
    }

    public function getCollection()
    {
        return PostResource::collection(Post::query()->get());
    }

    public function get(Post $post)
    {
        return new PostResource($post);
    }

    public function create(CreatePostRequest $request)
    {
        $post = PostService::create(...$request->validated());
        return new PostResource($post);
    }

    public function update(UpdatePostRequest $request, Post $post)
    {
        $post->Service()->update(...$request->validated());
        return new PostResource($post->fresh());
    }

    public function delete(Post $post)
    {
        $post->Service()->delete();
        return response()->json(null, 204);
    }
}
```

Register routes by calling `PostController::apiRoutes()` in your routes file.

### Generated Service

The service uses a domain-driven pattern: static `create()` to build new instances, instance `update()` and `delete()` to operate on existing records. The model connects to the service via a `Service()` method:

```php
namespace App\Models\Services;

use App\Models\Post;

class PostService
{
    private Post $post;

    public function __construct(Post $post)
    {
        $this->post = $post;
    }

    public static function create(
        string $title,
        string $slug,
        ?string $body = null,
        // ... all editable columns with correct PHP types
    ): Post {
        $post = new Post();
        $post->title = $title;
        $post->slug = $slug;
        $post->body = $body;
        // ...
        $post->save();

        return $post;
    }

    public function update(
        string $title,
        string $slug,
        ?string $body = null,
        // ...
    ): Post {
        $this->post->title = $title;
        $this->post->slug = $slug;
        $this->post->body = $body;
        // ...
        $this->post->save();

        return $this->post;
    }

    public function delete(): void
    {
        $this->post->delete();
    }
}
```

Add the `Service()` method to your model to connect it:

```php
class Post extends BaseModel
{
    protected static string $schema = PostSchema::class;

    public function Service(): PostService
    {
        return new PostService($this);
    }
}
```

Parameters are type-hinted from the schema (`string`, `int`, `bool`, `float`, `array`) with nullable columns defaulting to `= null`. Primary keys, timestamps, and soft-delete columns are automatically excluded.

### Generated Requests

FormRequests use `Schema::createRules()` and `Schema::updateRules()` to derive validation rules from the schema:

```php
namespace App\Http\Requests;

use App\Schemas\PostSchema;
use Illuminate\Foundation\Http\FormRequest;

class CreatePostRequest extends FormRequest
{
    public function rules(): array
    {
        return PostSchema::createRules([
            'title',
            'slug',
            'body',
            'status',
            'price',
            'view_count',
            'is_featured',
            'published_at',
            'author_id',
            'category_id',
        ])->toArray();
    }
}
```

The field list includes all editable columns from the schema. You can use `merge()`, `only()`, and `except()` on the returned `RuleSet` to customize further.

### Generated Resource

The resource includes all visible columns and child relationships with `whenLoaded`:

```php
namespace App\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'body' => $this->body,
            'author_id' => $this->author_id,       // BelongsTo → FK ID only
            'category_id' => $this->category_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'comments' => CommentResource::collection($this->whenLoaded('comments')),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
        ];
    }
}
```

Key behaviors:

- **BelongsTo** relationships only include the foreign key ID (e.g., `author_id`), not the related resource
- **HasMany / BelongsToMany / MorphMany / MorphToMany** use `::collection($this->whenLoaded(...))`
- **HasOne / MorphOne** use `new XResource($this->whenLoaded(...))`
- **BelongsToMany with pivot columns** use a closure that maps each item with `$item->pivot->only([...])`
- **Hidden columns** (marked with `#[Hidden]`) are excluded
- **Timestamps** are included at the end when the schema uses `TimestampsSchema`

### Publishing Stubs

To customize the generated code, publish the stubs:

```bash
php artisan vendor:publish --tag=schema-craft-stubs
```

This copies the template files to `stubs/schema-craft/api/` in your project root. The generator checks for published stubs first and falls back to the package defaults.

Available stubs:
- `controller.stub` — Controller class
- `service.stub` — Service class
- `create-request.stub` — Create FormRequest
- `update-request.stub` — Update FormRequest
- `action-request.stub` — Custom action FormRequest

---

## SDK Client Generation

SchemaCraft can generate a standalone Composer package that acts as a typed PHP API client for your generated API. The SDK is built from the same schema metadata, so the API server and client stay perfectly in sync.

### Generating the SDK

First, generate your API stack with `schema:generate`, then generate the SDK:

```bash
php artisan schema:generate-sdk
```

The command discovers all schema classes that have generated API controllers and produces a complete Composer package.

Options:

```bash
php artisan schema:generate-sdk --path=packages/my-sdk      # custom output directory
php artisan schema:generate-sdk --name=acme/my-sdk           # custom package name
php artisan schema:generate-sdk --namespace=Acme\\Sdk        # custom PHP namespace
php artisan schema:generate-sdk --client=AcmeClient          # custom client class name
php artisan schema:generate-sdk --schema-path=app/Schemas    # custom schema directory
php artisan schema:generate-sdk --force                      # overwrite existing files
```

### SDK Package Structure

```
packages/sdk/
├── composer.json
└── src/
    ├── MyAppClient.php              # Main client entry point
    ├── SdkConnector.php             # HTTP transport layer (Guzzle)
    ├── Data/
    │   ├── PostData.php             # Response DTO for Post
    │   └── CommentData.php          # Response DTO for Comment
    └── Resources/
        ├── PostResource.php         # $client->posts() — CRUD methods
        └── CommentResource.php      # $client->comments()
```

### Generated Connector

The `SdkConnector` wraps Guzzle with bearer token authentication. All requests send `Authorization: Bearer {token}`, `Accept: application/json`, and `Content-Type: application/json` headers:

```php
$connector = new SdkConnector(
    baseUrl: 'https://api.myapp.com',
    token: 'your-sanctum-token',
);

// HTTP methods
$connector->get('posts');                    // GET /posts
$connector->post('posts', $data);           // POST /posts
$connector->put('posts/1', $data);          // PUT /posts/1
$connector->delete('posts/1');              // DELETE /posts/1
```

The constructor accepts an optional `?ClientInterface $httpClient` parameter for testing — pass a mock Guzzle client to test without real HTTP calls.

### Generated Data DTOs

Each schema produces a Data Transfer Object with `public readonly` typed properties and a `fromArray()` factory:

```php
class PostData
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $slug,
        public readonly ?string $body,
        public readonly float $price,
        public readonly int $viewCount,
        public readonly bool $isFeatured,
        public readonly ?string $publishedAt,
        public readonly int $authorId,
        public readonly ?int $categoryId,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
        /** @var CommentData[]|null */ public readonly ?array $comments,
        /** @var TagData[]|null */ public readonly ?array $tags,
    ) {}

    public static function fromArray(array $data): self { ... }
}
```

Key behaviors:

- **Property names** are camelCase from snake_case columns (`view_count` → `$viewCount`)
- **Type mapping** matches the schema: `integer` → `int`, `boolean` → `bool`, `decimal` → `float`, `json` → `array`, `timestamp` → `string`
- **Hidden columns** (marked with `#[Hidden]`) are excluded
- **Timestamps** are included as `?string` when the schema uses `TimestampsSchema`
- **Soft deletes** add `?string $deletedAt` when the schema uses `SoftDeletesSchema`
- **BelongsTo** relationships include only the FK column ID (e.g., `$authorId`), not the related DTO
- **HasMany / BelongsToMany** relationships are nullable arrays (`?array`) since they come from `whenLoaded`
- **HasOne / MorphOne** relationships are nullable singular DTOs (e.g., `?ProfileData`)
- **`fromArray()`** maps JSON keys (snake_case) to camelCase constructor parameters

### Generated Resources

Each schema produces a Resource class with typed CRUD methods:

```php
class PostResource
{
    public function __construct(private SdkConnector $connector) {}

    /** @return PostData[] */
    public function list(): array { ... }

    public function get(int|string $id): PostData { ... }

    public function create(
        string $title,
        string $slug,
        ?string $body = null,
        float $price,
        // ...
    ): PostData { ... }

    public function update(
        int|string $id,
        string $title,
        string $slug,
        ?string $body = null,
        float $price,
        // ...
    ): PostData { ... }

    public function delete(int|string $id): void { ... }
}
```

Key behaviors:

- **Method parameters** mirror the schema's editable columns — primary keys, timestamps, and soft-delete columns are excluded
- **Parameter types** match the column definition: non-nullable columns are required parameters, nullable columns are `?type $param = null`
- **Both `create()` and `update()`** use the same parameter signatures — the nullable flag always matches the column definition
- **Route prefix** is derived from the model name: `Post` → `posts`, `BlogPost` → `blog-posts`, `Category` → `categories`
- **Data array** uses original snake_case keys: `'first_name' => $firstName`
- **Multi-line formatting** kicks in when a method has more than 3 parameters

### Generated Client

The main client class is the entry point for SDK consumers. It creates a `SdkConnector` and exposes resource accessor methods:

```php
$client = new MyAppClient(
    baseUrl: 'https://api.myapp.com',
    token: 'your-sanctum-token',
);

// Resource accessors return typed resource objects
$posts = $client->posts()->list();           // PostData[]
$post = $client->posts()->create(
    title: 'Hello World',
    slug: 'hello-world',
    body: 'Content here',
    price: 29.99,
    viewCount: 0,
    isFeatured: false,
    authorId: 1,
);                                            // PostData
$post = $client->posts()->get(1);            // PostData
$client->posts()->update(1, title: 'Updated'); // PostData
$client->posts()->delete(1);                 // void

// Typed response properties
$post->title;       // string
$post->createdAt;   // ?string
$post->comments;    // CommentData[]|null (when loaded)
```

Resource method names are pluralized camelCase: `Post` → `posts()`, `BlogPost` → `blogPosts()`, `Category` → `categories()`.

### Custom Actions in the SDK

Custom actions added via `schema:generate PostSchema --action=cancel` are automatically detected and included as methods on the SDK resource:

```php
// Custom actions detected from the controller
$client->posts()->cancel(1);      // PUT /posts/1/cancel
$client->posts()->publish(1);     // PUT /posts/1/publish
$client->posts()->archive(1);     // PUT /posts/1/archive
```

Multi-word action names are converted to kebab-case for the URL path: `markAsRead` → `PUT /posts/{id}/mark-as-read`.

### Publishing SDK Stubs

The SDK stubs are included in the same `schema-craft-stubs` vendor publish tag:

```bash
php artisan vendor:publish --tag=schema-craft-stubs
```

This copies the SDK template files to `stubs/schema-craft/sdk/` in your project root. The generator checks for published stubs first and falls back to the package defaults.

Available SDK stubs:
- `composer.json.stub` — Composer package template with `{{ packageName }}`, `{{ namespace }}`, and `{{ clientName }}` placeholders

---

## Artisan Commands

### schema-craft:install

Publish the `BaseModel` class that all generated models extend:

```bash
php artisan schema-craft:install
```

Creates `app/Models/BaseModel.php`. Safe to run multiple times — skips if the file already exists.

Run this once before using `make:schema`. If you forget, `make:schema` will warn you and offer to run it automatically.

### make:schema

Create one or more schema classes (and their models):

```bash
php artisan make:schema Post                  # one schema + model
php artisan make:schema Owner Dog Walk        # three schemas + three models
php artisan make:schema Post --no-model       # schema only, skip model
php artisan make:schema Post --uuid           # UUID primary key
php artisan make:schema Post --ulid           # ULID primary key
php artisan make:schema Post --soft-deletes   # include SoftDeletesSchema trait
```

Options apply to all names when creating multiple schemas:

```bash
php artisan make:schema Owner Dog Walk --uuid --soft-deletes
```

**Output per name:**
- `app/Schemas/{Name}Schema.php`
- `app/Models/{Name}.php` (unless `--no-model`)

### schema:status

Show which tables are in sync with their schemas:

```bash
php artisan schema:status
```

```
  ✓ tags
  ✓ users
  ✗ posts — 3 changes detected
      + add column: subtitle (string)
      ~ modify column: body (text)
      → rename column: old_title → title
```

Options:

```bash
php artisan schema:status --connection=mysql
php artisan schema:status --path=app/Schemas --path=modules/Blog/Schemas
```

### schema:migrate

Generate Laravel migration files for all detected changes:

```bash
php artisan schema:migrate
```

```
  Created: Create tags → 2025_01_15_120000_create_tags_table.php
  Created: Update posts → 2025_01_15_120001_update_posts_table.php

2 migrations generated.
```

Options:

```bash
php artisan schema:migrate --run                           # generate + run immediately
php artisan schema:migrate --connection=mysql               # specific connection
php artisan schema:migrate --migration-path=database/custom # custom output directory
php artisan schema:migrate --path=app/Schemas               # custom schema directory
```

The generated migrations are standard Laravel migration files with `up()` and `down()` methods. You can review and edit them before running.

**Safety:** Column drops are always generated as commented-out code with a warning. You must uncomment them manually to confirm the drop is intentional.

### schema:generate

Generate a full API stack (controller, service, requests, resource) from a schema class:

```bash
php artisan schema:generate PostSchema                  # short name
php artisan schema:generate Post                        # auto-appends Schema
php artisan schema:generate App\Schemas\PostSchema      # FQCN
php artisan schema:generate PostSchema --force           # overwrite existing
```

Add a custom action to an existing API:

```bash
php artisan schema:generate PostSchema --action=cancel
php artisan schema:generate PostSchema --action=archive
php artisan schema:generate PostSchema --action=publish
```

**Output (initial generation):**
- `app/Http/Controllers/Api/{Name}Controller.php`
- `app/Models/Services/{Name}Service.php`
- `app/Http/Requests/Create{Name}Request.php`
- `app/Http/Requests/Update{Name}Request.php`
- `app/Resources/{Name}Resource.php`

**Output (--action):**
- Creates `app/Http/Requests/{Action}{Name}Request.php`
- Updates the controller with the new route, import, and method
- Updates the service with a stub method

See [API Code Generation](#api-code-generation) for details on what the generated code looks like.

### schema:generate-sdk

Generate a standalone Composer package that acts as a typed PHP API client for your generated APIs:

```bash
php artisan schema:generate-sdk                                # generate SDK from all API schemas
php artisan schema:generate-sdk --path=packages/my-sdk         # custom output directory
php artisan schema:generate-sdk --name=acme/my-sdk             # custom package name
php artisan schema:generate-sdk --namespace=Acme\\Sdk          # custom PHP namespace
php artisan schema:generate-sdk --client=AcmeClient            # custom client class name
php artisan schema:generate-sdk --schema-path=app/Schemas      # custom schema directory
php artisan schema:generate-sdk --force                        # overwrite existing files
```

The command automatically discovers schemas that have API controllers (generated via `schema:generate`) and includes any custom actions detected in the controllers.

**Output:**
- `{path}/composer.json` — package manifest with Guzzle dependency
- `{path}/src/SdkConnector.php` — HTTP transport with bearer token auth
- `{path}/src/{Client}.php` — main client entry point
- `{path}/src/Data/{Name}Data.php` — response DTO per schema
- `{path}/src/Resources/{Name}Resource.php` — CRUD resource per schema

See [SDK Client Generation](#sdk-client-generation) for details on what the generated code looks like.

### schema-craft:relationship

Add relationships to schema files from the command line. Dev-only — only runs in the `local` environment.

**Add a single relationship:**

```bash
php artisan schema-craft:relationship "User->belongsTo(Account)"
```

This adds `#[BelongsTo(Account::class)]` to `UserSchema`, including the `use` import, `@method` PHPDoc, and typed property.

**Add a relationship with its inverse:**

```bash
php artisan schema-craft:relationship "User->belongsTo(Account)->hasMany(User)"
```

This modifies both files:
- `UserSchema` gets `#[BelongsTo(Account::class)]`
- `AccountSchema` gets `#[HasMany(User::class)]`

**Override property names with `$name:` prefix:**

```bash
php artisan schema-craft:relationship "User->$owner:belongsTo(Account)->$users:hasMany(User)"
```

Without the prefix, property names are auto-derived (`account` for BelongsTo, `users` for HasMany).

**StudlyCase and camelCase both work:**

```bash
php artisan schema-craft:relationship "User->BelongsTo(Account)"
php artisan schema-craft:relationship "User->belongsTo(Account)"
```

**All relationship types are supported:**

```bash
php artisan schema-craft:relationship "Post->hasMany(Comment)"
php artisan schema-craft:relationship "User->hasOne(Profile)"
php artisan schema-craft:relationship "Post->belongsToMany(Tag)"
php artisan schema-craft:relationship "Comment->morphTo(Post,'commentable')"
php artisan schema-craft:relationship "Post->morphMany(Comment,'commentable')"
php artisan schema-craft:relationship "Post->morphOne(Image,'imageable')"
php artisan schema-craft:relationship "Post->morphToMany(Tag,'taggable')"
```

**What gets generated in the schema file:**

For `php artisan schema-craft:relationship "Owner->hasMany(Dog)"`, the command adds:

1. Missing `use` imports (`use App\Models\Dog;`, `use SchemaCraft\Attributes\Relations\HasMany;`, `use Illuminate\Database\Eloquent\Collection;`, `use Illuminate\Database\Eloquent\Relations as Eloquent;`)
2. `@method Eloquent\HasMany|Dog dogs()` to the class PHPDoc block
3. The property declaration:

```php
/** @var Collection<int, Dog> */
#[HasMany(Dog::class)]
public Collection $dogs;
```

The command is idempotent — running it twice with the same relationship will not create duplicates.

---

## Schema Visualizer

The Schema Visualizer is a browser-based dev tool that helps you understand your schema graph and catch relationship issues. It is only available in the `local` environment.

Visit `/_schema-craft` in your browser.

### Health Dashboard

The landing page shows a summary of your schemas and surfaces issues:

- **Missing inverse relationships** — e.g., `DogSchema` has `belongsTo(Owner)` but `OwnerSchema` has no `hasMany(Dog)` pointing back
- **Orphaned models** — schemas with zero relationships
- **FK columns without relationships** — columns ending in `_id` that aren't backed by a relationship attribute

Each issue shows:
- Severity indicator (warning/info)
- The affected schemas (clickable — jumps to Explorer view)
- Suggested fix code with a **Copy** button
- **Apply Fix** button to automatically write the relationship into the schema file

### Apply Fix from the UI

When the Health Dashboard detects a missing inverse relationship, each issue card has an **Apply Fix** button.

For unambiguous cases (e.g., `hasMany` needs a `belongsTo` inverse), a single button appears:

> **Apply Fix**

For ambiguous cases (e.g., `belongsTo` could need either `hasMany` or `hasOne` as its inverse), two buttons appear:

> **Apply as HasMany** | **Apply as HasOne**

Clicking the button sends a request to the server, which modifies the schema file — adding the import, `@method` PHPDoc, and property declaration. The dashboard then refreshes automatically to reflect the change.

### Explorer

The Explorer tab provides an interactive graph view of your schemas:

- **Left sidebar** — searchable list of all schemas. Click one to load it onto the canvas.
- **Schema cards** — show relationships (with type badges) and expandable column details.
- **Load related models** — click **[Load]** on any relationship to pull the related schema onto the canvas, or **[Load All]** to load all connected schemas at once.
- **Relationship lines** — SVG lines connect related schemas (solid for belongsTo/hasMany/hasOne, dashed for belongsToMany, dotted for morphic).
- **Draggable cards** — drag schema cards by their header to rearrange the layout.

---

## Full Example

**Schema:**

```php
namespace App\Schemas;

use App\Enums\PostStatus;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\Decimal;
use SchemaCraft\Attributes\Fillable;
use SchemaCraft\Attributes\Hidden;
use SchemaCraft\Attributes\Index;
use SchemaCraft\Attributes\OnDelete;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Attributes\Relations\BelongsTo;
use SchemaCraft\Attributes\Relations\BelongsToMany;
use SchemaCraft\Attributes\Relations\HasMany;
use SchemaCraft\Attributes\Text;
use SchemaCraft\Attributes\Unique;
use SchemaCraft\Attributes\Unsigned;
use SchemaCraft\Attributes\With;
use SchemaCraft\Schema;
use SchemaCraft\Traits\SoftDeletesSchema;
use SchemaCraft\Traits\TimestampsSchema;

#[Index(['status', 'published_at'])]
class PostSchema extends Schema
{
    use SoftDeletesSchema;
    use TimestampsSchema;

    #[Primary]
    #[AutoIncrement]
    public int $id;

    #[Fillable]
    public string $title;

    #[Fillable]
    #[Unique]
    public string $slug;

    #[Fillable]
    #[Text]
    public ?string $body;

    #[Fillable]
    public PostStatus $status = PostStatus::Draft;

    #[Fillable]
    #[Decimal(10, 2)]
    #[Unsigned]
    public float $price;

    #[Unsigned]
    public int $view_count = 0;

    public bool $is_featured = false;

    public ?Carbon $published_at;

    #[Hidden]
    public array $metadata = [];

    #[Fillable]
    #[BelongsTo(User::class)]
    #[OnDelete('cascade')]
    #[With]
    public User $author;

    #[Fillable]
    #[BelongsTo(Category::class)]
    public ?Category $category;

    /** @var Collection<int, Comment> */
    #[HasMany(Comment::class)]
    public Collection $comments;

    /** @var Collection<int, Tag> */
    #[BelongsToMany(Tag::class)]
    public Collection $tags;
}
```

**Model:**

```php
namespace App\Models;

use App\Schemas\PostSchema;
use Illuminate\Database\Eloquent\SoftDeletes;

/** @mixin PostSchema */
class Post extends BaseModel
{
    use SoftDeletes;

    protected static string $schema = PostSchema::class;

    public function scopePublished($query)
    {
        return $query->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }
}
```

**Usage — it's just Laravel:**

```php
$post = Post::create([
    'title' => 'Hello World',
    'slug' => 'hello-world',
    'body' => 'Content here...',
    'price' => 29.99,
    'author_id' => 1,
]);

$post->author;      // User model (eager-loaded via #[With])
$post->tags;         // Collection of Tag models
$post->status;       // PostStatus::Draft enum instance
$post->metadata;     // array (auto-cast from json)

Post::published()->with('comments')->paginate();
```
