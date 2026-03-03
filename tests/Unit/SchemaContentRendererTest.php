<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Generator\EditorColumn;
use SchemaCraft\Generator\EditorRelationship;
use SchemaCraft\Generator\SchemaContentRenderer;
use SchemaCraft\Generator\SchemaEditorPayload;

class SchemaContentRendererTest extends TestCase
{
    private SchemaContentRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new SchemaContentRenderer;
    }

    private function makePayload(array $overrides = []): SchemaEditorPayload
    {
        return new SchemaEditorPayload(
            schemaName: $overrides['schemaName'] ?? 'PostSchema',
            schemaNamespace: $overrides['schemaNamespace'] ?? 'App\\Schemas',
            modelNamespace: $overrides['modelNamespace'] ?? 'App\\Models',
            tableName: $overrides['tableName'] ?? null,
            connection: $overrides['connection'] ?? null,
            hasTimestamps: $overrides['hasTimestamps'] ?? false,
            hasSoftDeletes: $overrides['hasSoftDeletes'] ?? false,
            columns: $overrides['columns'] ?? [],
            relationships: $overrides['relationships'] ?? [],
            compositeIndexes: $overrides['compositeIndexes'] ?? [],
        );
    }

    // ─── Minimal Schema ──────────────────────────────────

    public function test_renders_minimal_schema_with_id_only(): void
    {
        $payload = $this->makePayload([
            'columns' => [
                new EditorColumn(name: 'id', phpType: 'int', primary: true, autoIncrement: true),
            ],
        ]);

        $output = $this->renderer->render($payload);

        $this->assertStringContainsString('namespace App\\Schemas;', $output);
        $this->assertStringContainsString('class PostSchema extends Schema', $output);
        $this->assertStringContainsString('#[Primary]', $output);
        $this->assertStringContainsString('#[AutoIncrement]', $output);
        $this->assertStringContainsString('public int $id;', $output);
        $this->assertStringContainsString('use SchemaCraft\\Attributes\\Primary;', $output);
        $this->assertStringContainsString('use SchemaCraft\\Attributes\\AutoIncrement;', $output);
        $this->assertStringContainsString('use SchemaCraft\\Schema;', $output);
    }

    // ─── Primary Key Variants ────────────────────────────

    public function test_renders_uuid_primary_key(): void
    {
        $payload = $this->makePayload([
            'columns' => [
                new EditorColumn(name: 'id', phpType: 'string', primary: true, columnType: 'uuid'),
            ],
        ]);

        $output = $this->renderer->render($payload);

        $this->assertStringContainsString('#[Primary]', $output);
        $this->assertStringContainsString("#[ColumnType('uuid')]", $output);
        $this->assertStringContainsString('public string $id;', $output);
        $this->assertStringNotContainsString('#[AutoIncrement]', $output);
    }

    public function test_renders_ulid_primary_key(): void
    {
        $payload = $this->makePayload([
            'columns' => [
                new EditorColumn(name: 'id', phpType: 'string', primary: true, columnType: 'ulid'),
            ],
        ]);

        $output = $this->renderer->render($payload);

        $this->assertStringContainsString("#[ColumnType('ulid')]", $output);
        $this->assertStringContainsString('public string $id;', $output);
    }

    // ─── Column Type Overrides ───────────────────────────

    public function test_renders_text_type_override(): void
    {
        $payload = $this->makePayload([
            'columns' => [
                new EditorColumn(name: 'body', phpType: 'string', typeOverride: 'Text'),
            ],
        ]);

        $output = $this->renderer->render($payload);

        $this->assertStringContainsString('#[Text]', $output);
        $this->assertStringContainsString('public string $body;', $output);
        $this->assertStringContainsString('use SchemaCraft\\Attributes\\Text;', $output);
    }

    public function test_renders_medium_text_type_override(): void
    {
        $output = $this->renderer->render($this->makePayload([
            'columns' => [new EditorColumn(name: 'content', phpType: 'string', typeOverride: 'MediumText')],
        ]));

        $this->assertStringContainsString('#[MediumText]', $output);
    }

    public function test_renders_long_text_type_override(): void
    {
        $output = $this->renderer->render($this->makePayload([
            'columns' => [new EditorColumn(name: 'data', phpType: 'string', typeOverride: 'LongText')],
        ]));

        $this->assertStringContainsString('#[LongText]', $output);
    }

    public function test_renders_big_int_type_override(): void
    {
        $output = $this->renderer->render($this->makePayload([
            'columns' => [new EditorColumn(name: 'count', phpType: 'int', typeOverride: 'BigInt')],
        ]));

        $this->assertStringContainsString('#[BigInt]', $output);
        $this->assertStringContainsString('public int $count;', $output);
    }

    public function test_renders_small_int_type_override(): void
    {
        $output = $this->renderer->render($this->makePayload([
            'columns' => [new EditorColumn(name: 'rank', phpType: 'int', typeOverride: 'SmallInt')],
        ]));

        $this->assertStringContainsString('#[SmallInt]', $output);
    }

    public function test_renders_tiny_int_type_override(): void
    {
        $output = $this->renderer->render($this->makePayload([
            'columns' => [new EditorColumn(name: 'level', phpType: 'int', typeOverride: 'TinyInt')],
        ]));

        $this->assertStringContainsString('#[TinyInt]', $output);
    }

    public function test_renders_float_column(): void
    {
        $output = $this->renderer->render($this->makePayload([
            'columns' => [new EditorColumn(name: 'rating', phpType: 'float', typeOverride: 'FloatColumn')],
        ]));

        $this->assertStringContainsString('#[FloatColumn]', $output);
        $this->assertStringContainsString('public float $rating;', $output);
    }

    public function test_renders_decimal_with_precision_and_scale(): void
    {
        $output = $this->renderer->render($this->makePayload([
            'columns' => [new EditorColumn(name: 'price', phpType: 'float', typeOverride: 'Decimal', precision: 10, scale: 2)],
        ]));

        $this->assertStringContainsString('#[Decimal(10, 2)]', $output);
        $this->assertStringContainsString('public float $price;', $output);
        $this->assertStringContainsString('use SchemaCraft\\Attributes\\Decimal;', $output);
    }

    public function test_renders_date_time_year_columns(): void
    {
        $payload = $this->makePayload([
            'columns' => [
                new EditorColumn(name: 'born_on', phpType: 'CarbonInterface', typeOverride: 'Date', nullable: true),
                new EditorColumn(name: 'alarm', phpType: 'CarbonInterface', typeOverride: 'Time'),
                new EditorColumn(name: 'grad_year', phpType: 'int', typeOverride: 'Year'),
            ],
        ]);

        $output = $this->renderer->render($payload);

        $this->assertStringContainsString('#[Date]', $output);
        $this->assertStringContainsString('public ?CarbonInterface $born_on;', $output);
        $this->assertStringContainsString('#[Time]', $output);
        $this->assertStringContainsString('#[Year]', $output);
    }

    // ─── Column Constraints ──────────────────────────────

    public function test_renders_nullable_column(): void
    {
        $output = $this->renderer->render($this->makePayload([
            'columns' => [new EditorColumn(name: 'bio', phpType: 'string', nullable: true)],
        ]));

        $this->assertStringContainsString('public ?string $bio;', $output);
    }

    public function test_renders_column_with_default_value(): void
    {
        $output = $this->renderer->render($this->makePayload([
            'columns' => [new EditorColumn(name: 'status', phpType: 'string', default: 'active', hasDefault: true)],
        ]));

        $this->assertStringContainsString("public string \$status = 'active';", $output);
    }

    public function test_renders_column_with_integer_default(): void
    {
        $output = $this->renderer->render($this->makePayload([
            'columns' => [new EditorColumn(name: 'count', phpType: 'int', default: 0, hasDefault: true)],
        ]));

        $this->assertStringContainsString('public int $count = 0;', $output);
    }

    public function test_renders_column_with_boolean_default(): void
    {
        $output = $this->renderer->render($this->makePayload([
            'columns' => [new EditorColumn(name: 'is_active', phpType: 'bool', default: true, hasDefault: true)],
        ]));

        $this->assertStringContainsString('public bool $is_active = true;', $output);
    }

    public function test_renders_column_with_null_default(): void
    {
        $output = $this->renderer->render($this->makePayload([
            'columns' => [new EditorColumn(name: 'bio', phpType: 'string', nullable: true, default: null, hasDefault: true)],
        ]));

        $this->assertStringContainsString('public ?string $bio = null;', $output);
    }

    public function test_renders_column_with_expression_default(): void
    {
        $output = $this->renderer->render($this->makePayload([
            'columns' => [new EditorColumn(name: 'created_at', phpType: 'CarbonInterface', nullable: true, expressionDefault: 'CURRENT_TIMESTAMP')],
        ]));

        $this->assertStringContainsString("#[DefaultExpression('CURRENT_TIMESTAMP')]", $output);
        $this->assertStringContainsString('use SchemaCraft\\Attributes\\DefaultExpression;', $output);
    }

    public function test_renders_unsigned_column(): void
    {
        $output = $this->renderer->render($this->makePayload([
            'columns' => [new EditorColumn(name: 'age', phpType: 'int', unsigned: true)],
        ]));

        $this->assertStringContainsString('#[Unsigned]', $output);
        $this->assertStringContainsString('use SchemaCraft\\Attributes\\Unsigned;', $output);
    }

    public function test_renders_unique_and_index_columns(): void
    {
        $payload = $this->makePayload([
            'columns' => [
                new EditorColumn(name: 'email', phpType: 'string', unique: true),
                new EditorColumn(name: 'status', phpType: 'string', index: true),
            ],
        ]);

        $output = $this->renderer->render($payload);

        $this->assertStringContainsString('#[Unique]', $output);
        $this->assertStringContainsString('#[Index]', $output);
    }

    public function test_renders_string_column_with_length(): void
    {
        $output = $this->renderer->render($this->makePayload([
            'columns' => [new EditorColumn(name: 'slug', phpType: 'string', length: 100)],
        ]));

        $this->assertStringContainsString('#[Length(100)]', $output);
        $this->assertStringContainsString('use SchemaCraft\\Attributes\\Length;', $output);
    }

    public function test_does_not_render_length_255(): void
    {
        $output = $this->renderer->render($this->makePayload([
            'columns' => [new EditorColumn(name: 'name', phpType: 'string', length: 255)],
        ]));

        $this->assertStringNotContainsString('#[Length', $output);
    }

    // ─── Model Features ──────────────────────────────────

    public function test_renders_fillable_and_hidden_attributes(): void
    {
        $payload = $this->makePayload([
            'columns' => [
                new EditorColumn(name: 'name', phpType: 'string', fillable: true),
                new EditorColumn(name: 'secret', phpType: 'string', hidden: true),
            ],
        ]);

        $output = $this->renderer->render($payload);

        $this->assertStringContainsString('#[Fillable]', $output);
        $this->assertStringContainsString('#[Hidden]', $output);
        $this->assertStringContainsString('use SchemaCraft\\Attributes\\Fillable;', $output);
        $this->assertStringContainsString('use SchemaCraft\\Attributes\\Hidden;', $output);
    }

    public function test_renders_cast_attribute(): void
    {
        $output = $this->renderer->render($this->makePayload([
            'columns' => [new EditorColumn(name: 'status', phpType: 'string', castClass: 'App\\Casts\\StatusCast')],
        ]));

        $this->assertStringContainsString('#[Cast(StatusCast::class)]', $output);
        $this->assertStringContainsString('use SchemaCraft\\Attributes\\Cast;', $output);
        $this->assertStringContainsString('use App\\Casts\\StatusCast;', $output);
    }

    public function test_renders_rules_attribute(): void
    {
        $output = $this->renderer->render($this->makePayload([
            'columns' => [new EditorColumn(name: 'email', phpType: 'string', rules: ['required', 'email'])],
        ]));

        $this->assertStringContainsString("#[Rules('required', 'email')]", $output);
        $this->assertStringContainsString('use SchemaCraft\\Attributes\\Rules;', $output);
    }

    public function test_renders_renamed_from_attribute(): void
    {
        $output = $this->renderer->render($this->makePayload([
            'columns' => [new EditorColumn(name: 'full_name', phpType: 'string', renamedFrom: 'name')],
        ]));

        $this->assertStringContainsString("#[RenamedFrom('name')]", $output);
        $this->assertStringContainsString('use SchemaCraft\\Attributes\\RenamedFrom;', $output);
    }

    // ─── Relationships ───────────────────────────────────

    public function test_renders_belongs_to_relationship(): void
    {
        $output = $this->renderer->render($this->makePayload([
            'relationships' => [
                new EditorRelationship(name: 'author', type: 'belongsTo', relatedModel: 'App\\Models\\User'),
            ],
        ]));

        $this->assertStringContainsString('#[BelongsTo(User::class)]', $output);
        $this->assertStringContainsString('public User $author;', $output);
        $this->assertStringContainsString('use App\\Models\\User;', $output);
        $this->assertStringContainsString('use SchemaCraft\\Attributes\\Relations\\BelongsTo;', $output);
        $this->assertStringContainsString('@method Eloquent\\BelongsTo|User author()', $output);
    }

    public function test_renders_belongs_to_with_options(): void
    {
        $output = $this->renderer->render($this->makePayload([
            'relationships' => [
                new EditorRelationship(
                    name: 'owner',
                    type: 'belongsTo',
                    relatedModel: 'App\\Models\\User',
                    nullable: true,
                    onDelete: 'cascade',
                    index: true,
                    foreignColumn: 'owner_id',
                ),
            ],
        ]));

        $this->assertStringContainsString('#[BelongsTo(User::class)]', $output);
        $this->assertStringContainsString("#[OnDelete('cascade')]", $output);
        $this->assertStringContainsString("#[ForeignColumn('owner_id')]", $output);
        $this->assertStringContainsString('#[Index]', $output);
        $this->assertStringContainsString('public ?User $owner;', $output);
    }

    public function test_renders_has_one_relationship(): void
    {
        $output = $this->renderer->render($this->makePayload([
            'relationships' => [
                new EditorRelationship(name: 'profile', type: 'hasOne', relatedModel: 'App\\Models\\Profile'),
            ],
        ]));

        $this->assertStringContainsString('#[HasOne(Profile::class)]', $output);
        $this->assertStringContainsString('public Profile $profile;', $output);
        $this->assertStringContainsString('@method Eloquent\\HasOne|Profile profile()', $output);
    }

    public function test_renders_has_many_relationship(): void
    {
        $output = $this->renderer->render($this->makePayload([
            'relationships' => [
                new EditorRelationship(name: 'comments', type: 'hasMany', relatedModel: 'App\\Models\\Comment'),
            ],
        ]));

        $this->assertStringContainsString('#[HasMany(Comment::class)]', $output);
        $this->assertStringContainsString('public Collection $comments;', $output);
        $this->assertStringContainsString('/** @var Collection<int, Comment> */', $output);
        $this->assertStringContainsString('@method Eloquent\\HasMany|Comment comments()', $output);
        $this->assertStringContainsString('use Illuminate\\Database\\Eloquent\\Collection;', $output);
    }

    public function test_renders_belongs_to_many_with_pivot(): void
    {
        $output = $this->renderer->render($this->makePayload([
            'relationships' => [
                new EditorRelationship(
                    name: 'tags',
                    type: 'belongsToMany',
                    relatedModel: 'App\\Models\\Tag',
                    pivotTable: 'post_tag',
                    pivotColumns: ['order' => 'integer', 'notes' => 'text'],
                ),
            ],
        ]));

        $this->assertStringContainsString('#[BelongsToMany(Tag::class)]', $output);
        $this->assertStringContainsString("#[PivotTable('post_tag')]", $output);
        $this->assertStringContainsString("#[PivotColumns(['order' => 'integer', 'notes' => 'text'])]", $output);
        $this->assertStringContainsString('public Collection $tags;', $output);
        $this->assertStringContainsString('use SchemaCraft\\Attributes\\PivotTable;', $output);
        $this->assertStringContainsString('use SchemaCraft\\Attributes\\PivotColumns;', $output);
    }

    public function test_renders_belongs_to_many_with_pivot_model(): void
    {
        $output = $this->renderer->render($this->makePayload([
            'relationships' => [
                new EditorRelationship(
                    name: 'teams',
                    type: 'belongsToMany',
                    relatedModel: 'App\\Models\\Team',
                    pivotModel: 'App\\Models\\UserTeam',
                ),
            ],
        ]));

        $this->assertStringContainsString('#[UsingPivot(UserTeam::class)]', $output);
        $this->assertStringContainsString('use SchemaCraft\\Attributes\\UsingPivot;', $output);
        $this->assertStringContainsString('use App\\Models\\UserTeam;', $output);
    }

    public function test_renders_morph_to_relationship(): void
    {
        $output = $this->renderer->render($this->makePayload([
            'relationships' => [
                new EditorRelationship(name: 'commentable', type: 'morphTo', relatedModel: 'Model', morphName: 'commentable'),
            ],
        ]));

        $this->assertStringContainsString("#[MorphTo('commentable')]", $output);
        $this->assertStringContainsString('public Model $commentable;', $output);
        $this->assertStringContainsString('use Illuminate\\Database\\Eloquent\\Model;', $output);
        $this->assertStringContainsString('@method Eloquent\\MorphTo|Model commentable()', $output);
    }

    public function test_renders_morph_one_relationship(): void
    {
        $output = $this->renderer->render($this->makePayload([
            'relationships' => [
                new EditorRelationship(name: 'image', type: 'morphOne', relatedModel: 'App\\Models\\Image', morphName: 'imageable'),
            ],
        ]));

        $this->assertStringContainsString("#[MorphOne(Image::class, 'imageable')]", $output);
        $this->assertStringContainsString('public Image $image;', $output);
    }

    public function test_renders_morph_many_relationship(): void
    {
        $output = $this->renderer->render($this->makePayload([
            'relationships' => [
                new EditorRelationship(name: 'comments', type: 'morphMany', relatedModel: 'App\\Models\\Comment', morphName: 'commentable'),
            ],
        ]));

        $this->assertStringContainsString("#[MorphMany(Comment::class, 'commentable')]", $output);
        $this->assertStringContainsString('public Collection $comments;', $output);
        $this->assertStringContainsString('/** @var Collection<int, Comment> */', $output);
    }

    public function test_renders_morph_to_many_relationship(): void
    {
        $output = $this->renderer->render($this->makePayload([
            'relationships' => [
                new EditorRelationship(name: 'tags', type: 'morphToMany', relatedModel: 'App\\Models\\Tag', morphName: 'taggable'),
            ],
        ]));

        $this->assertStringContainsString("#[MorphToMany(Tag::class, 'taggable')]", $output);
        $this->assertStringContainsString('public Collection $tags;', $output);
    }

    public function test_renders_relationship_with_eager_load(): void
    {
        $output = $this->renderer->render($this->makePayload([
            'relationships' => [
                new EditorRelationship(name: 'posts', type: 'hasMany', relatedModel: 'App\\Models\\Post', with: true),
            ],
        ]));

        $this->assertStringContainsString('#[With]', $output);
        $this->assertStringContainsString('use SchemaCraft\\Attributes\\With;', $output);
    }

    // ─── Table-Level Settings ────────────────────────────

    public function test_renders_timestamps_trait(): void
    {
        $output = $this->renderer->render($this->makePayload(['hasTimestamps' => true]));

        $this->assertStringContainsString('use TimestampsSchema;', $output);
        $this->assertStringContainsString('use SchemaCraft\\Traits\\TimestampsSchema;', $output);
    }

    public function test_renders_soft_deletes_trait(): void
    {
        $output = $this->renderer->render($this->makePayload(['hasSoftDeletes' => true]));

        $this->assertStringContainsString('use SoftDeletesSchema;', $output);
        $this->assertStringContainsString('use SchemaCraft\\Traits\\SoftDeletesSchema;', $output);
    }

    public function test_renders_custom_table_name(): void
    {
        $output = $this->renderer->render($this->makePayload(['tableName' => 'blog_posts']));

        $this->assertStringContainsString("return 'blog_posts';", $output);
        $this->assertStringContainsString('public static function tableName(): ?string', $output);
    }

    public function test_renders_connection_property(): void
    {
        $output = $this->renderer->render($this->makePayload(['connection' => 'mysql_crm']));

        $this->assertStringContainsString("protected static ?string \$connection = 'mysql_crm';", $output);
    }

    public function test_renders_composite_indexes(): void
    {
        $output = $this->renderer->render($this->makePayload([
            'compositeIndexes' => [['user_id', 'created_at']],
        ]));

        $this->assertStringContainsString("#[Index(['user_id', 'created_at'])]", $output);
    }

    // ─── Import Sorting ──────────────────────────────────

    public function test_imports_are_sorted_correctly(): void
    {
        $payload = $this->makePayload([
            'columns' => [
                new EditorColumn(name: 'id', phpType: 'int', primary: true, autoIncrement: true),
                new EditorColumn(name: 'email', phpType: 'string', unique: true),
            ],
            'relationships' => [
                new EditorRelationship(name: 'user', type: 'belongsTo', relatedModel: 'App\\Models\\User'),
            ],
            'hasTimestamps' => true,
        ]);

        $output = $this->renderer->render($payload);

        // App\* should come before Illuminate\* which comes before SchemaCraft\*
        $appPos = strpos($output, 'use App\\Models\\User;');
        $illuminatePos = strpos($output, 'use Illuminate\\');
        $schemaPos = strpos($output, 'use SchemaCraft\\');

        $this->assertNotFalse($appPos);
        $this->assertNotFalse($illuminatePos);
        $this->assertNotFalse($schemaPos);
        $this->assertLessThan($illuminatePos, $appPos);
        $this->assertLessThan($schemaPos, $illuminatePos);
    }

    // ─── Model Rendering ─────────────────────────────────

    public function test_renders_model_file(): void
    {
        $output = $this->renderer->renderModel($this->makePayload());

        $this->assertStringContainsString('namespace App\\Models;', $output);
        $this->assertStringContainsString('use App\\Schemas\\PostSchema;', $output);
        $this->assertStringContainsString('@mixin PostSchema', $output);
        $this->assertStringContainsString('class Post extends BaseModel', $output);
        $this->assertStringContainsString('protected static string $schema = PostSchema::class;', $output);
    }

    public function test_renders_model_with_soft_deletes(): void
    {
        $output = $this->renderer->renderModel($this->makePayload(['hasSoftDeletes' => true]));

        $this->assertStringContainsString('use Illuminate\\Database\\Eloquent\\SoftDeletes;', $output);
        $this->assertStringContainsString('use SoftDeletes;', $output);
    }

    public function test_renders_model_with_connection(): void
    {
        $output = $this->renderer->renderModel($this->makePayload(['connection' => 'crm']));

        $this->assertStringContainsString("protected \$connection = 'crm';", $output);
    }

    // ─── Complex Schema ──────────────────────────────────

    public function test_renders_full_schema_with_all_features(): void
    {
        $payload = $this->makePayload([
            'hasTimestamps' => true,
            'hasSoftDeletes' => true,
            'columns' => [
                new EditorColumn(name: 'id', phpType: 'int', primary: true, autoIncrement: true),
                new EditorColumn(name: 'title', phpType: 'string', fillable: true),
                new EditorColumn(name: 'body', phpType: 'string', typeOverride: 'Text', fillable: true),
                new EditorColumn(name: 'views', phpType: 'int', default: 0, hasDefault: true),
                new EditorColumn(name: 'is_published', phpType: 'bool', default: false, hasDefault: true),
            ],
            'relationships' => [
                new EditorRelationship(name: 'author', type: 'belongsTo', relatedModel: 'App\\Models\\User', onDelete: 'cascade', index: true),
                new EditorRelationship(name: 'comments', type: 'hasMany', relatedModel: 'App\\Models\\Comment'),
                new EditorRelationship(name: 'tags', type: 'belongsToMany', relatedModel: 'App\\Models\\Tag'),
            ],
        ]);

        $output = $this->renderer->render($payload);

        // Class structure
        $this->assertStringContainsString('class PostSchema extends Schema', $output);
        $this->assertStringContainsString('use TimestampsSchema;', $output);
        $this->assertStringContainsString('use SoftDeletesSchema;', $output);

        // Columns
        $this->assertStringContainsString('public int $id;', $output);
        $this->assertStringContainsString('public string $title;', $output);
        $this->assertStringContainsString('public string $body;', $output);
        $this->assertStringContainsString('public int $views = 0;', $output);
        $this->assertStringContainsString('public bool $is_published = false;', $output);

        // Relationships
        $this->assertStringContainsString('#[BelongsTo(User::class)]', $output);
        $this->assertStringContainsString('#[HasMany(Comment::class)]', $output);
        $this->assertStringContainsString('#[BelongsToMany(Tag::class)]', $output);

        // Docblock
        $this->assertStringContainsString('@method Eloquent\\BelongsTo|User author()', $output);
        $this->assertStringContainsString('@method Eloquent\\HasMany|Comment comments()', $output);
        $this->assertStringContainsString('@method Eloquent\\BelongsToMany|Tag tags()', $output);
    }

    // ─── No Unsigned on AutoIncrement ────────────────────

    public function test_does_not_render_unsigned_on_auto_increment(): void
    {
        $output = $this->renderer->render($this->makePayload([
            'columns' => [
                new EditorColumn(name: 'id', phpType: 'int', primary: true, autoIncrement: true, unsigned: true),
            ],
        ]));

        // Should have Primary and AutoIncrement but NOT Unsigned (it's implied)
        $this->assertStringContainsString('#[Primary]', $output);
        $this->assertStringContainsString('#[AutoIncrement]', $output);
        $this->assertStringNotContainsString('#[Unsigned]', $output);
    }
}
