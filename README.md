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
php artisan schema:generate-sdk --sdk-version=1.0.0     # set SDK version
php artisan schema:generate-sdk --force                 # overwrite existing

# Multi-API Management
php artisan vendor:publish --tag=schema-craft-config    # publish config
php artisan schema:api:create partner --setup-sanctum   # scaffold new API
php artisan schema:generate PostSchema --api=partner    # generate into partner API
php artisan schema:generate-sdk --api=partner           # SDK for one API
php artisan schema:generate-sdk --all                   # SDKs for all APIs

# Installing & Using a Generated SDK
# 1. Add path repository in consumer's composer.json:
#    "repositories": [{"type": "path", "url": "../packages/partner-sdk"}]
# 2. Require the package:
#    composer require my-app/partner-sdk
# 3. Use in your code:
#    $client = new PartnerClient(baseUrl: '...', token: '...');
#    $posts = $client->posts()->list();

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
#[HasManyThrough(Post::class, through: User::class)] public Collection $posts;

// Inline key overrides (match Laravel's method signatures)
#[BelongsTo(User::class, foreignKey: 'created_by')] public User $creator;
#[HasMany(Post::class, foreignKey: 'author_id')] public Collection $posts;
#[BelongsToMany(Tag::class, table: 'taggables')] public Collection $tags;

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

// Custom Generators — place in app/Generators/
Input::schemaSelector('schema', 'Schema')              // schema dropdown
Input::text('class_name', 'Class Name')                // text input
Input::schemaColumn('group_by', 'Group By', 'schema')  // column picker

Template::file('[schema.model.title].php', 'generators.my-template')
...Template::forEachRelationship('schema', 'rel', [...], 'collection')

// NameChain — order-independent modifiers
$schema->model->title           // "UserProfile"
$schema->model->plural->title   // "UserProfiles"
$schema->model->plural->kebab   // "user-profiles"
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
  - [Polymorphic: MorphedByMany](#polymorphic-morphedbymany)
  - [HasOneThrough / HasManyThrough](#hasonethrough--hasmanythrough)
  - [Foreign Key Options](#foreign-key-options)
  - [Reusable Morph Patterns with Traits and Interfaces](#reusable-morph-patterns-with-traits-and-interfaces)
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
- [Multi-API Configuration](#multi-api-configuration)
  - [Config File](#config-file)
  - [Creating a New API](#creating-a-new-api)
  - [Generating Into a Specific API](#generating-into-a-specific-api)
  - [SDK Versioning](#sdk-versioning)
  - [Generating All SDKs](#generating-all-sdks)
  - [Schema Filtering](#schema-filtering)
  - [API Authentication](#api-authentication)
- [Data Scoping](#data-scoping)
  - [The ScopeContext Pattern](#the-scopecontext-pattern)
  - [Defining ScopeContext](#defining-scopecontext)
  - [Activating Scopes via Middleware](#activating-scopes-via-middleware)
  - [Applying Scopes in Models](#applying-scopes-in-models)
  - [Why SchemaCraft Uses query() Instead of Scoped Queries](#why-schemacraft-uses-query-instead-of-scoped-queries)
- [Using the Generated SDK](#using-the-generated-sdk)
  - [Installing the SDK Package](#installing-the-sdk-package)
  - [Importing and Using the Client](#importing-and-using-the-client)
- [Artisan Commands](#artisan-commands)
  - [schema-craft:install](#schemacraftinstall)
  - [make:schema](#makeschema)
  - [schema:status](#schemastatus)
  - [schema:migrate](#schemamigrate)
  - [schema:generate](#schemagenerate)
  - [schema:generate-sdk](#schemageneratesdk)
  - [schema:api:create](#schemaapicreate)
  - [schema-craft:relationship](#schemacraftrelationship)
- [Actions](#actions)
  - [Filament Integration](#filament-integration)
  - [Fill-Data Precedence](#fill-data-precedence)
  - [Customising Form Fields — configureFields()](#customising-form-fields--configurefields)
  - [API Endpoints](#api-endpoints)
- [Schema Visualizer](#schema-visualizer)
  - [Health Dashboard](#health-dashboard)
  - [Apply Fix from the UI](#apply-fix-from-the-ui)
  - [Explorer](#explorer)
  - [Docs Tab — Project Documentation](#docs-tab--project-documentation)
- [Custom Generators](#custom-generators)
  - [Creating a Generator](#creating-a-generator)
  - [Input Types](#input-types)
  - [NameChain — Model Name Helper](#namechain--model-name-helper)
  - [Template API](#template-api)
  - [Schema Context](#schema-context)
  - [GeneratorColumn Helpers](#generatorcolumn-helpers)
  - [GeneratorRelationship Helpers](#generatorrelationship-helpers)
  - [Template Data Hook](#template-data-hook)
  - [Writing Blade Templates](#writing-blade-templates)
  - [Customizing Filament Rendering per Column Type](#customizing-filament-rendering-per-column-type)
  - [Composable Templates with `@include`](#composable-templates-with-include)
  - [Complete Generator Example](#complete-generator-example)
- [Full Example](#full-example)
- [Full Multi-API Demo](#full-multi-api-demo)

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

To manage multiple APIs per project, publish the configuration file:

```bash
php artisan vendor:publish --tag=schema-craft-config
```

This creates `config/schema-craft.php` where you can define isolated API configurations, SDK metadata, and route settings. See [Multi-API Configuration](#multi-api-configuration) for details.

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

If the foreign key on the related table doesn't follow convention, pass `foreignKey:`:

```php
/** @var Collection<int, Post> */
#[HasMany(Post::class, foreignKey: 'author_id')]  // Post.author_id instead of Post.user_id
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
use SchemaCraft\Attributes\PivotColumns;

/** @var Collection<int, Tag> */
#[BelongsToMany(Tag::class, table: 'taggables')]
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

### Polymorphic: MorphedByMany

The inverse of `MorphToMany` — defined on the related model (e.g., `Tag`) to access all models that morphed to it:

```php
use Illuminate\Database\Eloquent\Collection;
use SchemaCraft\Attributes\Relations\MorphedByMany;

/** @var Collection<int, Post> */
#[MorphedByMany(Post::class, 'taggable')]
public Collection $posts;

/** @var Collection<int, Video> */
#[MorphedByMany(Video::class, 'taggable')]
public Collection $videos;
```

### HasOneThrough / HasManyThrough

Access distant relationships through an intermediate model. No columns are created on this table:

```php
use Illuminate\Database\Eloquent\Collection;
use SchemaCraft\Attributes\Relations\HasOneThrough;
use SchemaCraft\Attributes\Relations\HasManyThrough;

// A Country has one DeployEnvironment through a User
#[HasOneThrough(DeployEnvironment::class, through: User::class)]
public DeployEnvironment $deployEnvironment;

// A Country has many Posts through Users
/** @var Collection<int, Post> */
#[HasManyThrough(Post::class, through: User::class)]
public Collection $posts;
```

Optional key overrides match Laravel's method signature:

```php
#[HasManyThrough(
    Post::class,
    through: User::class,
    firstKey: 'country_id',
    secondKey: 'user_id',
    localKey: 'id',
    secondLocalKey: 'id',
)]
public Collection $posts;
```

### Foreign Key Options

Configure foreign key behavior on `BelongsTo` relationships:

```php
use SchemaCraft\Attributes\OnDelete;
use SchemaCraft\Attributes\OnUpdate;
use SchemaCraft\Attributes\NoConstraint;
use SchemaCraft\Attributes\Relations\BelongsTo;

#[BelongsTo(User::class)]
#[OnDelete('cascade')]               // ON DELETE CASCADE
public User $author;

#[BelongsTo(Team::class)]
#[OnDelete('set null')]              // ON DELETE SET NULL
#[OnUpdate('cascade')]               // ON UPDATE CASCADE
public ?Team $team;

#[BelongsTo(User::class, foreignKey: 'created_by')]  // Custom FK column name
public User $creator;

#[BelongsTo(User::class)]
#[NoConstraint]                      // Index only, no FK constraint
public User $reviewer;
```

### Reusable Morph Patterns with Traits and Interfaces

When multiple schemas share the same polymorphic relationship (e.g., `Comment` can belong to `Post`, `Video`, and `Photo`), you repeat `#[MorphMany(Comment::class, 'commentable')]` on every parent schema. Traits and interfaces eliminate this duplication while keeping type safety.

**The pattern:** trait on the schema carries the relationship property, interface on the model provides the `instanceof` type contract.

**Schema trait** — defines the relationship once:

```php
// app/Schemas/Concerns/HasCommentsSchema.php
namespace App\Schemas\Concerns;

use App\Models\Comment;
use Illuminate\Database\Eloquent\Collection;
use SchemaCraft\Attributes\Relations\MorphMany;

trait HasCommentsSchema
{
    /** @var Collection<int, Comment> */
    #[MorphMany(Comment::class, 'commentable')]
    public Collection $comments;
}
```

**Model interface** — an empty marker for type-hinting:

```php
// app/Models/Contracts/HasComments.php
namespace App\Models\Contracts;

interface HasComments
{
    // Marker interface — the trait on the schema carries the actual relationship.
}
```

**Usage** — the schema uses the trait, the model implements the interface:

```php
// app/Schemas/PostSchema.php
class PostSchema extends Schema
{
    use HasCommentsSchema;

    #[Primary] #[AutoIncrement]
    public int $id;
    public string $title;
}

// app/Models/Post.php
class Post extends BaseModel implements HasComments
{
    protected static string $schema = PostSchema::class;
}
```

Now you can write type-safe method signatures that accept any commentable model:

```php
use App\Models\Contracts\HasComments;

function addComment(HasComments $model, string $body): Comment
{
    return $model->comments()->create([
        'body' => $body,
        'author_id' => auth()->id(),
    ]);
}

// Works with any model that implements HasComments
addComment($post, 'Great article!');
addComment($video, 'Nice video!');
addComment($photo, 'Beautiful shot!');
```

**Why the split?** PHP interfaces cannot define properties, so they can't carry `#[MorphMany]` attributes. And SchemaCraft schemas don't pass their traits or interfaces to the model — the schema and model are separate classes. So each goes where it's useful: the trait on the schema (where SchemaCraft reads it), the interface on the model (where your application code type-hints it).

**Stacking multiple traits** works naturally:

```php
class PostSchema extends Schema
{
    use HasCommentsSchema;
    use HasTagsSchema;
    use HasMediaSchema;
    use TimestampsSchema;

    // ... post-specific properties
}

class Post extends BaseModel implements HasComments, HasTags, HasMedia
{
    protected static string $schema = PostSchema::class;
}
```

This follows the same pattern as SchemaCraft's built-in `TimestampsSchema` and `SoftDeletesSchema` traits — but for relationships instead of columns.

> **Note:** The `SchemaAnalyzer` validates that morph relationships have matching inverses. If `PostSchema` uses `HasCommentsSchema` (which has `#[MorphMany(Comment::class, 'commentable')]`), it expects `CommentSchema` to have a corresponding `#[MorphTo('commentable')]` property. The scanner reads trait properties via PHP reflection, so everything works transparently.

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

## Multi-API Configuration

SchemaCraft supports managing multiple independent APIs per project. Each API gets fully isolated directories for Controllers, Requests, and Resources, its own route file, and its own SDK configuration.

### Config File

Publish the configuration file:

```bash
php artisan vendor:publish --tag=schema-craft-config
```

This creates `config/schema-craft.php`:

```php
return [
    'default' => 'default',

    'explicit_foreign_keys' => false,

    'apis' => [
        'default' => [
            'namespaces' => [
                'controller' => 'App\\Http\\Controllers\\Api',
                'service'    => 'App\\Models\\Services',
                'request'    => 'App\\Http\\Requests',
                'resource'   => 'App\\Resources',
                'schema'     => 'App\\Schemas',
                'model'      => 'App\\Models',
            ],
            'routes' => [
                'file'       => 'routes/api.php',
                'prefix'     => 'api',
                'middleware'  => ['auth:sanctum'],
            ],
            'schemas' => null,  // null = all schemas with controllers
            'sdk' => [
                'path'      => 'packages/sdk',
                'name'      => 'my-app/sdk',
                'namespace' => 'MyApp\\Sdk',
                'client'    => 'MyAppClient',
                'version'   => '0.1.0',
            ],
        ],
    ],

    'db_connections' => [
        'default' => [
            'namespaces' => [
                'schema'  => 'App\\Schemas',
                'model'   => 'App\\Models',
                'service' => 'App\\Models\\Services',
            ],
            'connection' => 'default',
        ],
    ],
];
```

| Key | Purpose |
|---|---|
| `default` | Which API configuration to use when `--api` is not specified |
| `explicit_foreign_keys` | When `true`, `schema:from-database` generates FK columns as visible properties alongside BelongsTo (default `false`) |
| `apis.*.namespaces` | Where to place generated controllers, services, requests, resources |
| `apis.*.routes` | Route file path, URL prefix, and middleware |
| `apis.*.schemas` | Array of schema class names to include (`null` = all) |
| `apis.*.sdk` | SDK package output path, Composer name, namespace, client class, and version |
| `db_connections.*` | Database connection configs — schema directories are derived from each connection's `namespaces.schema` |

Without the config file, all commands use the same hardcoded defaults — zero behavior change for existing projects.

### Creating a New API

Scaffold a new API with a single command:

```bash
php artisan schema:api:create partner --setup-sanctum
```

This does five things:

1. **Creates route file** — `routes/partner-api.php` with API route boilerplate
2. **Creates isolated directories** — `app/Http/Controllers/PartnerApi/`, `app/Http/Requests/PartnerApi/`, `app/Resources/PartnerApi/`
3. **Adds config entry** — inserts `apis.partner` into `config/schema-craft.php` with all namespaces pointing to the isolated directories
4. **Registers route** — auto-edits `bootstrap/app.php` to register the route file in `withRouting()` via a `then:` closure
5. **Installs Sanctum** — runs `php artisan install:api` if `laravel/sanctum` is not already installed (only when `--setup-sanctum` is used)

Options:

```bash
php artisan schema:api:create partner                  # default prefix: partner-api
php artisan schema:api:create partner --prefix=v2      # custom URL prefix
php artisan schema:api:create partner --setup-sanctum  # install Sanctum if missing
```

After running, your config gains a new entry:

```php
'apis' => [
    'default' => [ ... ],
    'partner' => [
        'namespaces' => [
            'controller' => 'App\\Http\\Controllers\\PartnerApi',
            'service'    => 'App\\Models\\Services',
            'request'    => 'App\\Http\\Requests\\PartnerApi',
            'resource'   => 'App\\Resources\\PartnerApi',
            'schema'     => 'App\\Schemas',
            'model'      => 'App\\Models',
        ],
        'routes' => [
            'file'       => 'routes/partner-api.php',
            'prefix'     => 'partner-api',
            'middleware'  => ['auth:sanctum'],
        ],
        'schemas' => null,
        'sdk' => [
            'path'      => 'packages/partner-sdk',
            'name'      => 'my-app/partner-sdk',
            'namespace' => 'MyApp\\PartnerSdk',
            'client'    => 'PartnerClient',
            'version'   => '0.1.0',
        ],
    ],
],
```

### Generating Into a Specific API

Pass `--api` to target a specific API configuration:

```bash
php artisan schema:generate PostSchema --api=partner
```

This generates the controller, service, requests, and resource into the namespaces/directories defined in `apis.partner`. Without `--api`, the `default` API configuration is used.

Files are placed into the isolated directories:

| File | Location |
|---|---|
| Controller | `app/Http/Controllers/PartnerApi/PostController.php` |
| Service | `app/Models/Services/PostService.php` |
| Create Request | `app/Http/Requests/PartnerApi/CreatePostRequest.php` |
| Update Request | `app/Http/Requests/PartnerApi/UpdatePostRequest.php` |
| Resource | `app/Resources/PartnerApi/PostResource.php` |

Then register routes in your route file:

```php
// routes/partner-api.php
use App\Http\Controllers\PartnerApi\PostController;

PostController::apiRoutes();
```

### SDK Versioning

Each API's SDK has a version defined in the config:

```php
'sdk' => [
    'version' => '0.1.0',
],
```

The version appears in the generated SDK's `composer.json`. Bump it manually in the config, or override it at generation time:

```bash
php artisan schema:generate-sdk --api=partner --sdk-version=1.2.0
```

CLI options always take precedence over config values.

### Generating All SDKs

After updating schemas, regenerate all SDKs at once:

```bash
php artisan schema:generate-sdk --all
```

This iterates over every API in `config/schema-craft.php` and generates each one's SDK using its configured settings. Combine with `--force` to overwrite existing files:

```bash
php artisan schema:generate-sdk --all --force
```

### Schema Filtering

By default, an API includes all schemas that have generated controllers. To limit an API to specific schemas, set the `schemas` key:

```php
'partner' => [
    'schemas' => ['PostSchema', 'CommentSchema'],
    // ...
],
```

Only the listed schema classes will be included when generating the SDK for this API. Set to `null` to include all schemas with controllers.

### API Authentication

Each API's `routes.middleware` array controls how requests are authenticated. This is where you wire up Laravel Sanctum (or any auth middleware) per API.

#### Prerequisites

Install Sanctum and run the migration:

```bash
php artisan install:api
php artisan migrate
```

Add the `HasApiTokens` trait to your User model (or any model that issues tokens):

```php
use Laravel\Sanctum\HasApiTokens;

class User extends BaseModel implements Authenticatable
{
    use \Illuminate\Auth\Authenticatable;
    use HasApiTokens;
}
```

Register the ability middleware aliases in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->alias([
        'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
        'ability'   => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
    ]);
})
```

#### Middleware per API

The `routes.middleware` array on each API entry determines what auth is required. Here are the common patterns:

```php
'apis' => [

    // Standard API — any valid Sanctum token
    'default' => [
        'routes' => [
            'middleware' => ['auth:sanctum'],
        ],
    ],

    // Scoped API — token must have the 'partner' ability
    'partner' => [
        'routes' => [
            'middleware' => ['auth:sanctum', 'ability:partner'],
        ],
    ],

    // Admin API — token must have ALL listed abilities
    'admin' => [
        'routes' => [
            'middleware' => ['auth:sanctum', 'abilities:admin-read,admin-write'],
        ],
    ],

    // Multi-guard API — different auth guard (different User model)
    'tenant' => [
        'routes' => [
            'middleware' => ['auth:tenant-sanctum'],
        ],
    ],

    // Public API — no auth, rate-limited only
    'public' => [
        'routes' => [
            'middleware' => ['throttle:60,1'],
        ],
    ],
],
```

- **`auth:sanctum`** — authenticates the request (who are you?)
- **`ability:X`** — token must have at least one of the listed abilities (what can you do?)
- **`abilities:X,Y`** — token must have ALL listed abilities
- **`auth:custom-guard`** — uses a different guard/user model entirely

#### Multiple Auth Guards

When different APIs authenticate against different user models, define additional guards and providers in `config/auth.php`:

```php
'guards' => [
    'web' => [
        'driver' => 'session',
        'provider' => 'users',
    ],
    'tenant-sanctum' => [
        'driver' => 'sanctum',
        'provider' => 'tenants',
    ],
],

'providers' => [
    'users' => [
        'driver' => 'eloquent',
        'model' => App\Models\User::class,
    ],
    'tenants' => [
        'driver' => 'eloquent',
        'model' => App\Models\Tenant::class,
    ],
],
```

Then reference the guard name in the API's middleware: `'middleware' => ['auth:tenant-sanctum']`.

#### Issuing Tokens

Tokens are created on the authenticatable model. The abilities array controls which APIs the token can access:

```php
// Full access — works on any API using auth:sanctum
$user->createToken('app-name', ['*']);

// Scoped — only works on APIs requiring the 'partner' ability
$user->createToken('app-name', ['partner']);

// Multiple abilities — works on APIs requiring any/all of these
$user->createToken('app-name', ['admin-read', 'admin-write', 'partner']);

// Different model — for APIs using a different guard
$tenant->createToken('app-name', ['*']);
```

The first parameter is just a label for identifying the token (e.g., in a token management UI). Only the abilities array affects authorization.

---

## Data Scoping

SchemaCraft actions use `Model::query()` for all model resolution — BelongsTo lookups, MorphTo resolution, and endpoint record fetching. This is intentional: **the package does not enforce data scoping**. Instead, your application controls which records are visible by activating global scopes via middleware.

This separation means:
- Actions work identically across Filament panels, API endpoints, and any other context
- Each API or panel controls its own data isolation rules
- Models define their scoping logic once, middleware decides when it activates

### The ScopeContext Pattern

The recommended pattern uses a central constants class, a middleware that sets flags, and models that conditionally register global scopes based on those flags.

### Defining ScopeContext

Create a class with constants for each API or context that needs data scoping:

```php
// app/Support/ScopeContext.php
namespace App\Support;

class ScopeContext
{
    public const PanaceaCoreApi = 'panacea_core_api';
    public const PartnerApi = 'partner_api';
    public const AdminPanel = 'admin_panel';

    /** @var array<string, bool> */
    protected static array $active = [];

    public static function activate(string $context): void
    {
        static::$active[$context] = true;
    }

    public static function isActive(string $context): bool
    {
        return static::$active[$context] ?? false;
    }

    public static function reset(): void
    {
        static::$active = [];
    }
}
```

Using constants (instead of free-form strings) prevents typos and enables IDE autocompletion.

### Activating Scopes via Middleware

Create a middleware for each API that activates the appropriate scope context:

```php
// app/Http/Middleware/ActivatePanaceaCoreScope.php
namespace App\Http\Middleware;

use App\Support\ScopeContext;
use Closure;
use Illuminate\Http\Request;

class ActivatePanaceaCoreScope
{
    public function handle(Request $request, Closure $next): mixed
    {
        ScopeContext::activate(ScopeContext::PanaceaCoreApi);

        return $next($request);
    }
}
```

Then register it on the appropriate route group:

```php
// routes/api.php
Route::middleware(['auth:sanctum', ActivatePanaceaCoreScope::class])
    ->prefix('v1')
    ->group(function () {
        // These routes will have PanaceaCoreApi scoping active
        (new ArchivePostAction)->endpoint(PostResource::class);
    });
```

#### Filament / Livewire Panels

Filament panels use Livewire, which handles subsequent requests via `/livewire/update`. Filament's `isPersistent: true` on `authMiddleware()` does **not** register middleware with Livewire's persistent middleware system. You must register it directly with Livewire in a service provider:

```php
// app/Providers/AppServiceProvider.php
use Livewire\Livewire;
use App\Support\ActivatePanaceaCoreScope;

public function boot(): void
{
    Livewire::addPersistentMiddleware([
        ActivatePanaceaCoreScope::class,
    ]);
}
```

Then add it to your panel provider as usual:

```php
->authMiddleware([
    Authenticate::class,
    ActivatePanaceaCoreScope::class,
], isPersistent: true)
```

Without `Livewire::addPersistentMiddleware()`, the middleware only runs on the initial page load — not on Livewire AJAX requests where form fields and queries actually execute.

### Applying Scopes in Models

Models check `ScopeContext::isActive()` in their `booted()` method to conditionally add global scopes:

```php
// app/Models/Post.php
use App\Support\ScopeContext;
use Illuminate\Database\Eloquent\Builder;

class Post extends BaseModel
{
    protected static function booted(): void
    {
        // When the Panacea Core API is active, scope posts to the authenticated user
        if (ScopeContext::isActive(ScopeContext::PanaceaCoreApi)) {
            static::addGlobalScope('panacea-core', function (Builder $query) {
                $query->where('user_id', auth()->id());
            });
        }

        // When the Partner API is active, scope to the partner's tenant
        if (ScopeContext::isActive(ScopeContext::PartnerApi)) {
            static::addGlobalScope('partner', function (Builder $query) {
                $query->where('tenant_id', auth()->user()->tenant_id);
            });
        }
    }
}
```

Each model decides how it should be scoped for each context. Some models may only need scoping in one API, others in several, and some not at all.

### Why SchemaCraft Uses query() Instead of Scoped Queries

SchemaCraft deliberately uses `Model::query()` everywhere because:

1. **Actions are context-agnostic** — the same action class runs in Filament, API endpoints, CLI commands, and tests. Hardcoding auth scopes inside the package would break non-web contexts.

2. **Global scopes are automatic** — when a global scope is registered (via `booted()`), `Model::query()` already includes it. No explicit `forAuthUser()` call is needed.

3. **Multiple APIs, different rules** — a `Post` might be scoped by `user_id` in one API and `tenant_id` in another. The model + middleware handle this; the action doesn't need to know.

4. **Filament panels** — Filament has its own auth and tenancy middleware. Global scopes activated by Filament middleware apply to SchemaCraft actions rendered in Filament panels automatically.

If you previously used a `forAuthUser()` scope on your models, you can migrate to this pattern by moving that logic into `booted()` conditional scopes activated by your middleware.

---

## Using the Generated SDK

### Installing the SDK Package

The generated SDK is a standard Composer package. For local development, add it as a path repository in your consumer application's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../my-app/packages/partner-sdk"
        }
    ],
    "require": {
        "my-app/partner-sdk": "*"
    }
}
```

Then install:

```bash
composer require my-app/partner-sdk
```

For production distribution, publish the SDK to a private Packagist repository or a Git-based Composer repository.

### Importing and Using the Client

```php
use MyApp\PartnerSdk\PartnerClient;

$client = new PartnerClient(
    baseUrl: 'https://myapp.com/partner-api',
    token: 'your-sanctum-token',
);

// List all posts
$posts = $client->posts()->list();       // PostData[]

// Create a post
$post = $client->posts()->create(
    title: 'Hello World',
    slug: 'hello-world',
    body: 'Content here...',
    price: 29.99,
    viewCount: 0,
    isFeatured: false,
    authorId: 1,
);                                        // PostData

// Get a single post
$post = $client->posts()->get(1);        // PostData
$post->title;                            // string
$post->createdAt;                        // ?string

// Update
$client->posts()->update(1,
    title: 'Updated Title',
    slug: 'hello-world',
    body: 'Updated content',
    price: 29.99,
    viewCount: 0,
    isFeatured: true,
    authorId: 1,
);

// Delete
$client->posts()->delete(1);

// Custom actions
$client->posts()->cancel(1);
$client->posts()->publish(1);
```

All responses are typed DTOs — your IDE provides full autocomplete for every property.

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
php artisan schema:generate PostSchema --api=partner     # target a specific API
```

Add a custom action to an existing API:

```bash
php artisan schema:generate PostSchema --action=cancel
php artisan schema:generate PostSchema --action=archive
php artisan schema:generate PostSchema --action=publish
```

Options:

| Option | Description |
|---|---|
| `--force` | Overwrite existing files |
| `--action=` | Add a new action to an existing API stack |
| `--api=` | API configuration name from `config/schema-craft.php` (defaults to `default`) |

**Output (initial generation):**
- `app/Http/Controllers/Api/{Name}Controller.php`
- `app/Models/Services/{Name}Service.php`
- `app/Http/Requests/Create{Name}Request.php`
- `app/Http/Requests/Update{Name}Request.php`
- `app/Resources/{Name}Resource.php`

When using `--api=partner`, output paths use the configured namespaces (e.g., `app/Http/Controllers/PartnerApi/{Name}Controller.php`).

**Output (--action):**
- Creates `app/Http/Requests/{Action}{Name}Request.php`
- Updates the controller with the new route, import, and method
- Updates the service with a stub method

See [API Code Generation](#api-code-generation) for details on what the generated code looks like.

### schema:generate-sdk

Generate a standalone Composer package that acts as a typed PHP API client for your generated APIs:

```bash
php artisan schema:generate-sdk                                # generate SDK from all API schemas
php artisan schema:generate-sdk --api=partner                  # generate SDK for a specific API
php artisan schema:generate-sdk --all                          # generate SDKs for all configured APIs
php artisan schema:generate-sdk --sdk-version=1.0.0            # set SDK package version
php artisan schema:generate-sdk --path=packages/my-sdk         # custom output directory (overrides config)
php artisan schema:generate-sdk --name=acme/my-sdk             # custom package name (overrides config)
php artisan schema:generate-sdk --namespace=Acme\\Sdk          # custom PHP namespace (overrides config)
php artisan schema:generate-sdk --client=AcmeClient            # custom client class name (overrides config)
php artisan schema:generate-sdk --schema-path=app/Schemas      # custom schema directory
php artisan schema:generate-sdk --force                        # overwrite existing files
```

The command automatically discovers schemas that have API controllers (generated via `schema:generate`) and includes any custom actions detected in the controllers.

Options:

| Option | Description |
|---|---|
| `--api=` | API configuration name from `config/schema-craft.php` |
| `--all` | Generate SDKs for every configured API |
| `--sdk-version=` | SDK package version (overrides config's `sdk.version`) |
| `--path=` | Output directory (overrides config's `sdk.path`) |
| `--name=` | Composer package name (overrides config's `sdk.name`) |
| `--namespace=` | PHP namespace (overrides config's `sdk.namespace`) |
| `--client=` | Client class name (overrides config's `sdk.client`) |
| `--schema-path=` | Custom schema scan directories (repeatable) |
| `--force` | Overwrite existing files |

When a config file exists, the SDK's path, name, namespace, client, and version are read from the API's `sdk` config. CLI options always override config values.

**Output:**
- `{path}/composer.json` — package manifest with Guzzle dependency and version
- `{path}/src/SdkConnector.php` — HTTP transport with bearer token auth
- `{path}/src/{Client}.php` — main client entry point
- `{path}/src/Data/{Name}Data.php` — response DTO per schema
- `{path}/src/Resources/{Name}Resource.php` — CRUD resource per schema

See [SDK Client Generation](#sdk-client-generation) for details on what the generated code looks like.

### schema:api:create

Scaffold a new API configuration with isolated directories and route file:

```bash
php artisan schema:api:create partner                  # create partner API
php artisan schema:api:create partner --prefix=v2      # custom URL prefix
php artisan schema:api:create partner --setup-sanctum  # install Sanctum if missing
```

Options:

| Option | Description |
|---|---|
| `name` | The API name (e.g., `partner`, `internal`, `mobile`) |
| `--prefix=` | URL prefix for routes (defaults to `{name}-api`) |
| `--setup-sanctum` | Install Laravel Sanctum via `install:api` if not already present |

**What gets created:**
- `routes/{name}-api.php` — API route file
- `app/Http/Controllers/{StudlyName}Api/` — isolated controller directory
- `app/Http/Requests/{StudlyName}Api/` — isolated request directory
- `app/Resources/{StudlyName}Api/` — isolated resource directory
- `config/schema-craft.php` — updated with new `apis.{name}` entry
- `bootstrap/app.php` — updated with route registration in `withRouting()`

See [Multi-API Configuration](#multi-api-configuration) for details.

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

## Actions

Actions are typed PHP classes that describe **what** an operation needs — its parameters, types, validation, and relationships. SchemaCraft auto-generates Filament forms and API endpoints from these typed properties, so the same action works in both contexts without duplication.

### Filament Integration

Use `filamentAction()` to render an action as a Filament modal form. Each component's `->default()` closure resolves values from the record via Filament's DI at mount time. Works for both page actions and table row actions.

```php
use App\Models\Actions\Dog\CreateDogAction;

// ViewRecord or EditRecord — record resolved automatically via Filament DI
protected function getHeaderActions(): array
{
    return [
        CreateDogAction::create()->filamentAction(),
    ];
}

// ListRecords — no record, form starts from PHP property defaults
protected function getHeaderActions(): array
{
    return [
        CreateDogAction::create()->filamentAction(),
    ];
}
```

### Fill-Data Precedence

All form defaults use Filament's native `->default()` with closure DI. Values are resolved at mount time in this order (lowest to highest priority):

1. **PHP property defaults** — `public ?string $status = 'draft';`
2. **Record data** — auto-generated `->default(fn($record) => $record->column)` closures
3. **`configureFields()` `->default()`** — runs last, always wins

```php
use Illuminate\Database\Eloquent\Model;
use SchemaCraft\FieldProxy;

CreateDogAction::create()
    ->configureFields(function (FieldProxy $fields): void {
        $fields->owner
            ->default(fn (?Model $record) => $record?->owner_id)
            ->disabled();

        $fields->status->default('active');
    })
    ->filamentAction();
```

The `->default()` closure receives the record via Filament's dependency injection — the same pattern Filament's own actions use. This works for both page actions (record available immediately) and table row actions (record injected per-row at mount time).

When column names don't match property names, override the default in `configureFields()`:

```php
->configureFields(function (FieldProxy $fields): void {
    $fields->city->default(fn (?Model $record) => $record?->mailing_city);
    $fields->email->default(fn (?Model $record) => $record?->owner->email);
})
```

### Customising Form Fields — configureFields()

The Action describes *what* an operation needs. *How* to present those fields is a call-site concern — the same action might show different options on different pages. Use `configureFields()` to tweak or replace auto-generated Filament components at the call site.

The closure receives a `FieldProxy` where each property maps to the auto-generated Filament component by its camelCase property name.

#### Modify Mode — tweak the auto-generated component

```php
use App\Models\Actions\Dog\CreateDogAction;
use SchemaCraft\FieldProxy;

CreateDogAction::create()
    ->configureFields(function (FieldProxy $fields): void {
        // Chain any Filament method on the auto-generated component
        $fields->owner
            ->options(fn () => Owner::active()->pluck('name', 'id'))
            ->searchable()
            ->preload();

        $fields->status->label('Current Status');
    })
    ->filamentAction();
```

#### Replace Mode — swap the entire component

```php
use Filament\Forms\Components\Textarea;
use SchemaCraft\FieldProxy;

CreateDogAction::create()
    ->configureFields(function (FieldProxy $fields): void {
        // Assign a new component to completely replace the auto-generated one
        $fields->name = Textarea::make('name')
            ->rows(3)
            ->placeholder('Enter a description...');
    })
    ->filamentAction();
```

#### Setting Defaults and Presentation Together

Use `configureFields()` to both set default values and customise presentation in one place. Use Filament closure DI to access the record:

```php
use Illuminate\Database\Eloquent\Model;
use SchemaCraft\FieldProxy;

CreateDogAction::create()
    ->configureFields(function (FieldProxy $fields): void {
        // Pre-fill and lock the owner field from the record
        $fields->owner
            ->default(fn (?Model $record) => $record?->owner_id)
            ->disabled();

        // Dynamic options based on the record
        $fields->category
            ->options(fn (?Model $record) => Category::where('type', $record?->type)->pluck('name', 'id'));
    })
    ->filamentAction();
```

### API Endpoints

Actions also serve as API endpoints. Call `endpoint()` inside a route group to register the route automatically:

```php
use App\Models\Actions\Dog\CreateDogAction;
use App\Http\Resources\DogResource;

Route::prefix('v1/dogs/{dog_id}')
    ->middleware(['auth:sanctum'])
    ->group(function () {
        (new CreateDogAction)->endpoint(DogResource::class);
    });
```

The action's HTTP method, route segment, and model binding are derived from the action definition. `configureFields()` is a Filament-only concern — it has no effect on API endpoints.

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

### Docs Tab — Project Documentation

The Docs tab renders searchable, browsable documentation directly inside the visualizer. It automatically loads:

1. **Package documentation** — the SchemaCraft README (this file), always shown first
2. **Project-level documentation** — any `.md` files found in the configured docs directory

#### Adding Project Docs

Create markdown files in your project's `docs/` directory (or whichever path you configure):

```
docs/
  01-getting-started.md
  02-api-patterns.md
  03-deployment.md
```

Each file becomes its own collapsible group in the sidebar. The document name is derived from the first `# H1` heading in the file, falling back to a humanized version of the filename (e.g., `01-getting-started.md` becomes "Getting Started"). Within each document, `## H2` headings become clickable sidebar entries for quick navigation.

Numeric prefixes (e.g., `01-`, `02-`) control the sort order and are stripped from the display name.

#### Configuring the Docs Path

The docs directory defaults to `docs/` relative to your project root. To change it, publish the config and set `visualizer.docs_path`:

```php
// config/schema-craft.php

'visualizer' => [
    'docs_path' => 'docs',  // relative to base_path()
],
```

For example, setting `'docs_path' => 'documentation/schema-craft'` would scan `{project_root}/documentation/schema-craft/*.md`.

#### Search

The search input filters across **all** documents at once — both the package README and your project docs. Matching sections are highlighted in the content area and the sidebar updates to show only relevant sections.

---

## Custom Generators

The generator system lets you create reusable code generators that render Blade templates with schema-aware context. Generators appear in the **Generators** tab of the Schema Visualizer.

### Creating a Generator

Extend `SchemaCraftGenerator` and place it in `app/Generators/` (auto-discovered):

```php
<?php

namespace App\Generators;

use SchemaCraft\Generators\Input;
use SchemaCraft\Generators\SchemaCraftGenerator;
use SchemaCraft\Generators\Template;

class FilamentResourceGenerator extends SchemaCraftGenerator
{
    public function name(): string
    {
        return 'Filament Resource';
    }

    public function inputs(): array
    {
        return [
            Input::schemaSelector('schema', 'Schema', selectColumns: true, selectRelationships: true),
            Input::text('class_name', 'Class Name'),
            Input::select('chart_type', 'Chart Type', [
                'line' => 'Line',
                'bar' => 'Bar',
            ]),
        ];
    }

    public function templates(): array
    {
        return [
            Template::file(
                'app/Filament/Resources/[schema.model.plural.title]/[class_name].php',
                'generators.my-resource',
            ),
        ];
    }
}
```

### Input Types

Inputs define the form fields shown in the Visualizer when configuring a generator.

| Method | Description |
|--------|-------------|
| `Input::schemaSelector($key, $label)` | Schema dropdown with optional column/relationship checkboxes |
| `Input::text($key, $label)` | Free text field |
| `Input::select($key, $label, $options)` | Dropdown select |
| `Input::boolean($key, $label, $default)` | Checkbox toggle |
| `Input::schemaColumn($key, $label, $selectorKey)` | Single column picker (from a schemaSelector) |
| `Input::schemaColumns($key, $label, $selectorKey)` | Multi column picker (from a schemaSelector) |
| `Input::selectResourceDirectory($key, $label)` | Filament resource directory picker |

#### Schema Selector

`Input::schemaSelector()` replaces the old `schemas()` method. It creates a schema dropdown in the inputs section and optionally shows column and relationship checkboxes:

```php
Input::schemaSelector('schema', 'Schema', selectColumns: true, selectRelationships: true)
```

The selected schema is resolved to a `GeneratorSchemaContext` object available in your templates as `$schema`.

#### Schema Column / Schema Columns

These reference a `schemaSelector` input by key. The column picker populates from whichever schema the user selected:

```php
Input::schemaSelector('schema', 'Schema'),
Input::schemaColumn('group_by', 'Group By Column', 'schema'),
Input::schemaColumns('visible_cols', 'Visible Columns', 'schema'),
```

#### Resource Directory — ResourceDirectoryValue

`Input::selectResourceDirectory()` is populated from discovered Filament panels (via `FilamentPanelDiscovery`) plus any `schema-craft.resource_directories` config entries. The chosen value is resolved to a **`ResourceDirectoryValue`** object before being passed to templates, exposing both the relative filesystem path and the derived PSR-4 namespace:

```php
Input::selectResourceDirectory('resource_directory', 'Resource Directory'),
```

```blade
{!! $phpOpenTag !!}

namespace {!! $resource_directory->namespace !!}\{!! $schema->model->plural->title !!};
// → namespace App\Filament\Admin\Resources\Posts;
```

| Access | Value | Example |
|--------|-------|---------|
| `$resource_directory->path` | Relative filesystem path | `app/Filament/Admin/Resources` |
| `$resource_directory->namespace` | PSR-4 namespace derived from the path | `App\Filament\Admin\Resources` |
| `{{ $resource_directory }}` / `[resource_directory]` | Stringifies to the path (via `__toString`) | `app/Filament/Admin/Resources` |

The namespace derivation follows the Laravel convention: the first path segment is capitalized (`app` → `App`) and subsequent segments are joined with `\` as-is, since Filament directory names are already PascalCase (`Filament`, `Admin`, `Resources`). Output-path interpolation (`[resource_directory]/[schema.model.plural.title]/...`) keeps working unchanged because the object stringifies to the raw path.

### NameChain — Model Name Helper

When a schema is selected, the `$schema->model` property is a `NameChain` — an immutable, chainable name helper. Modifiers are **independent flags**, so order does not matter.

#### Modifiers

**Plurality:** `->singular` (default), `->plural`

**Casing:** `->snake` (default), `->title` (StudlyCase), `->camel`, `->kebab`

#### Examples

Given a schema for the `user_profiles` table:

| Expression | Result |
|-----------|--------|
| `$schema->model` | `user_profile` |
| `$schema->model->title` | `UserProfile` |
| `$schema->model->plural->title` | `UserProfiles` |
| `$schema->model->title->plural` | `UserProfiles` (same — order doesn't matter) |
| `$schema->model->camel` | `userProfile` |
| `$schema->model->plural->snake` | `user_profiles` |
| `$schema->model->kebab` | `user-profile` |
| `$schema->model->plural->kebab` | `user-profiles` |

#### Two Syntaxes, One Mechanism

- **Output paths** use bracket notation: `[schema.model.plural.title]`
- **Blade templates** use PHP property chaining: `{!! $schema->model->plural->title !!}`

Both traverse the same NameChain objects.

### Template API

Templates define which Blade views to render and where to write the output.

#### Basic Template

```php
Template::file($outputPath, $viewName, $extraVariables = [])
```

- **`$outputPath`** — File path with `[bracket]` placeholders, e.g. `'app/[schema.model.title].php'`
- **`$viewName`** — Blade view name, e.g. `'generators.my-template'`
- **`$extraVariables`** — Extra variables injected into the template; string values support `[bracket]` placeholders

#### Iterating Over Relationships

Use `Template::forEachRelationship()` to generate a file per relationship:

```php
public function templates(): array
{
    return [
        Template::file(
            'app/Resources/[schema.model.title]Resource.php',
            'generators.resource',
        ),

        ...Template::forEachRelationship('schema', 'relationship', [
            Template::file(
                'app/Resources/RelationManagers/[relationship.name.title]RelationManager.php',
                'generators.relation-manager',
                ['ClassName' => '[relationship.name.title]RelationManager'],
            ),
        ], 'collection'),
    ];
}
```

The `...` spread is required because `forEachRelationship()` returns an array.

#### Filters

The optional fourth parameter filters which items to iterate over:

| Filter | Description |
|--------|-------------|
| `'collection'` | Only hasMany, belongsToMany, morphMany, morphToMany, morphedByMany, hasManyThrough |
| `'singular'` | Only hasOne, belongsTo, morphTo, morphOne, hasOneThrough |
| `null` | All relationships (default) |

#### Generic Iteration

`Template::forEach()` iterates over any dot-path iterable. `forEachRelationship()` is sugar for it:

```php
// These are equivalent:
Template::forEachRelationship('schema', 'relationship', $templates, 'collection')
Template::forEach('schema.relationships', 'relationship', $templates, 'collection')
```

### Schema Context

When a `schemaSelector` input is resolved, templates receive a `GeneratorSchemaContext` with:

| Property | Type | Description |
|----------|------|-------------|
| `$schema->model` | `NameChain` | Model name with chaining (see above) |
| `$schema->tableName` | `string` | Raw table name, e.g. `'user_profiles'` |
| `$schema->columns` | `GeneratorColumn[]` | User-selected columns (or all if none selected) |
| `$schema->allColumns` | `GeneratorColumn[]` | All columns in the schema |
| `$schema->relationships` | `GeneratorRelationship[]` | User-selected relationships (or all) |
| `$schema->allRelationships` | `GeneratorRelationship[]` | All relationships in the schema |

#### Schema metadata

Pulled straight from the scanned `TableDefinition`:

| Property | Type | Description |
|----------|------|-------------|
| `$schema->schemaClass` | `string` | FQCN of the scanned schema class, e.g. `'App\Schemas\PostSchema'` |
| `$schema->modelClass` | `string` | FQCN of the Eloquent model, derived from the schema class. Reads `public static string $model` on the schema class via reflection if present, otherwise falls back to naming convention (`App\X\Schemas\FooSchema` → `App\X\Models\Foo`). Templates can emit `use {!! $schema->modelClass !!};` without stitching the path themselves. |
| `$schema->connection` | `?string` | DB connection name, or null for default |
| `$schema->fillable` | `string[]` | Fillable attribute names |
| `$schema->hidden` | `string[]` | Hidden attribute names |
| `$schema->defaultWith` | `string[]` | Default eager-loaded relationship names (schema `$with`) |
| `$schema->titleColumns` | `string[]` | Column names marked with `#[Title]` |
| `$schema->hasTimestamps` | `bool` | True if the schema uses `created_at` / `updated_at` |
| `$schema->hasSoftDeletes` | `bool` | True if the schema uses `SoftDeletesSchema` or has a `deleted_at` column |

#### Column lookups

| Property / Method | Returns | Description |
|---|---|---|
| `$schema->primaryKey` | `?GeneratorColumn` | First column marked `#[Primary]`, or null |
| `$schema->titleColumn` | `?GeneratorColumn` | First column in `titleColumns`, falls back to `primaryKey` |
| `$schema->column('email')` | `?GeneratorColumn` | Find a column by name, or null |
| `$schema->hasColumn('email')` | `bool` | Check if a column exists by name |
| `$schema->relationship('author')` | `?GeneratorRelationship` | Find a relationship by name, or null |

#### Filtered column sets

| Method | Returns | Description |
|---|---|---|
| `$schema->foreignKeyColumns()` | `GeneratorColumn[]` | Columns whose name ends in `_id` |
| `$schema->searchableColumns()` | `GeneratorColumn[]` | String/text columns, excluding FKs, PKs, timestamps, and soft-delete |
| `$schema->fillableColumns()` | `GeneratorColumn[]` | Columns whose name appears in `$schema->fillable` |

### GeneratorColumn Helpers

Each column in `$schema->columns` provides:

| Method | Example Output |
|--------|---------------|
| `$col->name` | `'first_name'` |
| `$col->phpType()` | `'string'`, `'int'`, `'bool'`, `'float'` |
| `$col->phpTypeNullable()` | `'?string'` |
| `$col->camelName()` | `'firstName'` |
| `$col->studlyName()` | `'FirstName'` |
| `$col->humanName()` | `'First Name'` |
| `$col->fakerValue()` | `'$faker->safeEmail()'` |
| `$col->asFilamentField()` | Filament form field component string (honors `FilamentRenderable` custom types) |
| `$col->asFilamentColumn()` | Filament table column component string (honors `FilamentRenderable` custom types) |
| `$col->asFilamentEntry()` | Filament infolist entry component string (honors `FilamentRenderable` custom types) |
| `$col->asMethodParam()` | `'string $firstName'` or `'?string $firstName = null'` |
| `$col->asAssignment('$model')` | `'$model->first_name = $firstName;'` |
| `$col->isFK()` | `true` if column ends with `_id` |
| `$col->isPrimary()` | `true` if column is marked `#[Primary]` |
| `$col->isTimestamp()` | `true` for `created_at` or `updated_at` |
| `$col->isSoftDelete()` | `true` for `deleted_at` |
| `$col->isEnum()` | `true` if the column's cast is a backed enum class |
| `$col->enumClass()` | Returns the enum FQCN when `isEnum()` is true, else `null` |
| `$col->relationshipName()` | `'owner'` (strips `_id`, camelCase) |

### GeneratorRelationship Helpers

Each relationship in `$schema->relationships` provides:

| Property / Method | Description |
|-------------------|-------------|
| `$rel->name` | NameChain — `$rel->name->title` → `'Author'` |
| `$rel->relatedModel` | NameChain — `$rel->relatedModel->plural->title` → `'Users'` |
| `$rel->relatedModelClass` | Full FQCN — `'App\Models\User'` |
| `$rel->type` | `'belongsTo'`, `'hasMany'`, etc. |
| `$rel->nullable` | Whether the relationship is nullable |
| `$rel->isCollection()` | `true` for hasMany, belongsToMany, morphMany, etc. |
| `$rel->isSingular()` | `true` for belongsTo, hasOne, morphTo, etc. |
| `$rel->pivotTable` | Pivot table name (belongsToMany) |
| `$rel->morphName` | Morph name (polymorphic relationships) |

All `RelationshipDefinition` properties are accessible via delegation.

### Template Data Hook

Override `templateData()` to inject custom variables into all templates:

```php
public function templateData(): array
{
    return [
        'app_name' => config('app.name'),
        'timestamp' => now()->toDateTimeString(),
    ];
}
```

### Writing Blade Templates

Place templates in `resources/views/generators/`. Use `{!! !!}` (unescaped) for code output:

```blade
{!! $phpOpenTag !!}

namespace App\Models;

use App\Models\{!! $schema->model->title !!};

class {!! $class_name !!}
{
@foreach ($schema->columns as $column)
    public {!! $column->phpType() !!} ${!! $column->name !!};
@endforeach
}
```

The `$phpOpenTag` variable (`<?php`) is always available — use it instead of a raw PHP tag which would confuse Blade.

### Customizing Filament Rendering per Column Type

`$col->asFilamentColumn()`, `$col->asFilamentEntry()`, and `$col->asFilamentField()` use a built-in mapper that handles strings, numerics, booleans, dates, JSON, UUID/ULID, and backed enums out of the box. **Backed enums automatically render as `->badge()`** — no interface is required on the enum class. If the enum also implements Filament's `HasLabel` / `HasColor` / `HasIcon` contracts, Filament will pick those up at runtime.

For domain-specific custom types (bitmasks, collection/JSON DTOs, rich value objects) the built-in mapper can't guess the right component. Implement `SchemaCraft\Contracts\FilamentRenderable` on your cast class and the generator will delegate to it:

```php
use SchemaCraft\Contracts\FilamentRenderable;
use SchemaCraft\Generators\GeneratorColumn;

class TinyBitmaskColumn extends AbstractBitmaskHandler implements FilamentRenderable
{
    public static function asFilamentColumn(GeneratorColumn $column): string
    {
        return "Tables\\Columns\\TextColumn::make('{$column->name}')->badge()->separator(',')";
    }

    public static function asFilamentEntry(GeneratorColumn $column): string
    {
        return "Infolists\\Components\\TextEntry::make('{$column->name}')->badge()";
    }

    public static function asFilamentField(GeneratorColumn $column): string
    {
        return "Forms\\Components\\CheckboxList::make('{$column->name}')->columns(2)";
    }
}
```

The hook fires when `$column->definition->castType` is a class that implements `FilamentRenderable`. Enums may also implement it to override the default `->badge()` rendering for specific domains. Return a single component expression without leading whitespace or a trailing comma — the caller handles indentation.

### Composable Templates with `@include`

Generator templates are rendered through Laravel's standard Blade view factory, so **all Blade directives work** — including `@include` for composable partials. This lets you split a large template into small, reusable pieces.

> **Note:** Use `@include(...)`, not `@view(...)`. `@view` is not a Blade directive.

**Layout convention:** put partials alongside your main templates under `resources/views/generators/`:

```
resources/views/generators/
└── filament-resource/
    ├── table.blade.php              ← main template
    └── partials/
        ├── column.blade.php         ← @include('generators.filament-resource.partials.column')
        └── relationship.blade.php   ← @include('generators.filament-resource.partials.relationship')
```

**Main template** loops and delegates to partials:

```blade
{!! $phpOpenTag !!}

namespace App\Filament\Resources\{!! $schema->model->plural->title !!}\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class {!! $schema->model->plural->title !!}Table
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
@foreach($schema->relationships as $relationship)
                @include('generators.filament-resource.partials.relationship', ['relationship' => $relationship])
@endforeach
@foreach($schema->columns as $column)
                @include('generators.filament-resource.partials.column', ['column' => $column])
@endforeach
            ])
            ->filters([
@if($schema->hasSoftDeletes)
                TrashedFilter::make(),
@endif
            ]);
    }
}
```

**Partial `partials/column.blade.php`:**

```blade
@if($column->isPrimary() || $column->isTimestamp() || $column->isSoftDelete())
                TextColumn::make('{!! $column->name !!}')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
@elseif($column->isFK())
                TextColumn::make('{!! $column->name !!}')
                    ->numeric()
                    ->sortable(),
@else
                TextColumn::make('{!! $column->name !!}')
                    ->searchable(),
@endif
```

**How the partial sees data:**

- Partials inherit the full parent scope, so `$schema`, `$phpOpenTag`, and any `templateData()` variables are all accessible — no need to pass them through.
- The `['column' => $column]` array on `@include` scopes the loop variable for that partial invocation.
- Dotted view names map to filesystem paths: `generators.foo.bar.baz` → `resources/views/generators/foo/bar/baz.blade.php`.

### Complete Generator Example

```php
<?php

namespace App\Generators;

use SchemaCraft\Generators\Input;
use SchemaCraft\Generators\SchemaCraftGenerator;
use SchemaCraft\Generators\Template;

class FilamentResourceGenerator extends SchemaCraftGenerator
{
    public function name(): string
    {
        return 'Filament Resource with Relation Managers';
    }

    public function inputs(): array
    {
        return [
            Input::schemaSelector('schema', 'Schema', selectColumns: true, selectRelationships: true),
            Input::selectResourceDirectory('resource_directory', 'Resource Directory'),
        ];
    }

    public function templates(): array
    {
        return [
            // Main resource file
            Template::file(
                '[resource_directory]/[schema.model.plural.title]/[schema.model.title]Resource.php',
                'generators.resources.resource',
            ),

            // One relation manager per collection relationship
            ...Template::forEachRelationship('schema', 'relationship', [
                Template::file(
                    '[resource_directory]/[schema.model.plural.title]/RelationManagers/[relationship.name.title]RelationManager.php',
                    'generators.resources.relation-manager',
                    ['ClassName' => '[relationship.name.title]RelationManager'],
                ),
            ], 'collection'),
        ];
    }
}
```

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

---

## Full Multi-API Demo

This walkthrough creates a partner API from scratch — from schemas to a consumer app using the generated SDK.

### Step 1: Setup

```bash
php artisan schema-craft:install
php artisan vendor:publish --tag=schema-craft-config
```

### Step 2: Define Schemas

```bash
php artisan make:schema Post Comment --soft-deletes
```

Edit `app/Schemas/PostSchema.php`:

```php
namespace App\Schemas;

use App\Models\Comment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\Fillable;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Attributes\Relations\BelongsTo;
use SchemaCraft\Attributes\Relations\HasMany;
use SchemaCraft\Attributes\Text;
use SchemaCraft\Attributes\Unique;
use SchemaCraft\Schema;
use SchemaCraft\Traits\SoftDeletesSchema;
use SchemaCraft\Traits\TimestampsSchema;

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
    #[BelongsTo(User::class)]
    public User $author;

    /** @var Collection<int, Comment> */
    #[HasMany(Comment::class)]
    public Collection $comments;
}
```

Edit `app/Schemas/CommentSchema.php`:

```php
namespace App\Schemas;

use App\Models\Post;
use App\Models\User;
use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\Fillable;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Attributes\Relations\BelongsTo;
use SchemaCraft\Attributes\Text;
use SchemaCraft\Schema;
use SchemaCraft\Traits\TimestampsSchema;

class CommentSchema extends Schema
{
    use TimestampsSchema;

    #[Primary]
    #[AutoIncrement]
    public int $id;

    #[Fillable]
    #[Text]
    public string $body;

    #[Fillable]
    #[BelongsTo(Post::class)]
    public Post $post;

    #[Fillable]
    #[BelongsTo(User::class)]
    public User $author;
}
```

### Step 3: Run Migrations

```bash
php artisan schema:migrate --run
```

### Step 4: Create the Partner API

```bash
php artisan schema:api:create partner --setup-sanctum
```

This scaffolds:
- `routes/partner-api.php`
- `app/Http/Controllers/PartnerApi/`
- `app/Http/Requests/PartnerApi/`
- `app/Resources/PartnerApi/`
- Config entry in `config/schema-craft.php`
- Route registration in `bootstrap/app.php`

### Step 5: Generate the API Stack

```bash
php artisan schema:generate PostSchema --api=partner
php artisan schema:generate CommentSchema --api=partner
```

This creates controllers, services, requests, and resources in the partner API's isolated directories.

### Step 6: Register Routes

Edit `routes/partner-api.php`:

```php
<?php

use App\Http\Controllers\PartnerApi\PostController;
use App\Http\Controllers\PartnerApi\CommentController;
use Illuminate\Support\Facades\Route;

PostController::apiRoutes();
CommentController::apiRoutes();
```

### Step 7: Generate the SDK

```bash
php artisan schema:generate-sdk --api=partner
```

The SDK is generated at `packages/partner-sdk/`:

```
packages/partner-sdk/
├── composer.json                    # "my-app/partner-sdk" v0.1.0
└── src/
    ├── PartnerClient.php            # $client->posts(), $client->comments()
    ├── SdkConnector.php             # Guzzle HTTP transport
    ├── Data/
    │   ├── PostData.php             # Typed response DTO
    │   └── CommentData.php
    └── Resources/
        ├── PostResource.php         # CRUD methods
        └── CommentResource.php
```

### Step 8: Use the SDK in a Consumer App

Add the SDK to a consumer application's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../my-app/packages/partner-sdk"
        }
    ],
    "require": {
        "my-app/partner-sdk": "*"
    }
}
```

```bash
composer require my-app/partner-sdk
```

Then use the typed client:

```php
use MyApp\PartnerSdk\PartnerClient;

$client = new PartnerClient(
    baseUrl: 'https://myapp.com/partner-api',
    token: $sanctumToken,
);

// Create a post
$post = $client->posts()->create(
    title: 'Getting Started with SchemaCraft',
    slug: 'getting-started',
    body: 'SchemaCraft makes API development fast...',
    authorId: 1,
);

echo $post->title;      // "Getting Started with SchemaCraft"
echo $post->slug;        // "getting-started"
echo $post->createdAt;   // "2026-02-23T12:00:00.000000Z"

// List all posts
$posts = $client->posts()->list();
foreach ($posts as $post) {
    echo "{$post->title} by author #{$post->authorId}\n";
}

// Add a comment
$comment = $client->comments()->create(
    body: 'Great article!',
    postId: $post->id,
    authorId: 2,
);

// Update a post
$client->posts()->update($post->id,
    title: 'Updated Title',
    slug: 'getting-started',
    body: 'Updated content...',
    authorId: 1,
);

// Delete
$client->posts()->delete($post->id);
```

### Step 9: Update After Schema Changes

When your schemas change, regenerate everything:

```bash
# Regenerate API stack
php artisan schema:generate PostSchema --api=partner --force
php artisan schema:generate CommentSchema --api=partner --force

# Bump version and regenerate SDK
php artisan schema:generate-sdk --api=partner --sdk-version=0.2.0 --force

# Or regenerate all SDKs at once
php artisan schema:generate-sdk --all --force
```

The consumer app runs `composer update` and gets the updated typed SDK automatically.
