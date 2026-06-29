<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Generator\Sdk\SdkModelGenerator;
use SchemaCraft\Generator\Sdk\SdkSchemaContext;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Scanner\RelationshipDefinition;
use SchemaCraft\Scanner\TableDefinition;

/**
 * Model export: flat, self-contained, read-only Eloquent models emitted INTO the SDK package
 * so a consuming project (without schema-craft installed) can read + traverse data with full
 * type-completion. Writes go through the API/SDK, not these models — hence read-only.
 *
 * Mirrors SdkGenerator's contract: same SdkSchemaContext map in, GeneratedFile[] out.
 */
class SdkModelGeneratorTest extends TestCase
{
    private SdkModelGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new SdkModelGenerator;
    }

    public function test_generates_a_model_file_per_schema(): void
    {
        $files = $this->generator->generate($this->makeSchemas(), 'Acme\\Sdk');

        $this->assertArrayHasKey('model_Post', $files);
        $this->assertSame('src/Models/Post.php', $files['model_Post']->path);

        $content = $files['model_Post']->content;
        $this->assertStringStartsWith('<?php', $content);
        $this->assertStringContainsString('namespace Acme\\Sdk\\Models;', $content);
        $this->assertStringContainsString('class Post extends', $content);
        $this->assertStringContainsString("protected \$table = 'posts';", $content);
    }

    public function test_emits_connection_when_set(): void
    {
        // The exported model must pin its connection explicitly — the target project resolves
        // data through its own connection config, so we cannot rely on a default.
        $schemas = [
            'County' => new SdkSchemaContext(
                table: new TableDefinition(
                    tableName: 'counties',
                    schemaClass: 'App\\Schemas\\CountySchema',
                    connection: 'secondary',
                    columns: [
                        new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
                    ],
                ),
            ),
        ];

        $content = $this->generator->generate($schemas, 'Acme\\Sdk')['model_County']->content;

        $this->assertStringContainsString("protected \$connection = 'secondary';", $content);
    }

    public function test_omits_connection_when_null(): void
    {
        $content = $this->generator->generate($this->makeSchemas(), 'Acme\\Sdk')['model_Post']->content;

        $this->assertStringNotContainsString('protected $connection', $content);
    }

    public function test_emits_native_casts_and_drops_custom_casts(): void
    {
        // Native cast directives (no class reference) are safe to carry — the target has Laravel.
        // Custom casts (a CastsAttributes class, a native-enum class, a DataSchema object, a typed
        // Collection) point at classes the target project does NOT have, so they must be dropped —
        // otherwise the model falls apart on hydration. Detection is "is this a class reference /
        // rich shape", not a hardcoded allow-list of native types.
        $schemas = [
            'Post' => new SdkSchemaContext(
                table: new TableDefinition(
                    tableName: 'posts',
                    schemaClass: 'App\\Schemas\\PostSchema',
                    columns: [
                        new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
                        new ColumnDefinition(name: 'title', columnType: 'string'),
                        new ColumnDefinition(name: 'published_at', columnType: 'timestamp', nullable: true, castType: 'datetime'),
                        new ColumnDefinition(name: 'views', columnType: 'integer', castType: 'integer'),
                        new ColumnDefinition(name: 'is_active', columnType: 'boolean', castType: 'boolean'),
                        new ColumnDefinition(name: 'price', columnType: 'decimal', castType: 'decimal:2'),
                        new ColumnDefinition(name: 'metadata', columnType: 'json', castType: 'array'),
                        // custom: native-enum class reference
                        new ColumnDefinition(name: 'status', columnType: 'string', castType: 'App\\Enums\\StatusEnum'),
                        // custom: DataSchema object column
                        new ColumnDefinition(name: 'address', columnType: 'json', castType: 'array', dataSchemaClass: 'App\\Schemas\\AddressSchema'),
                        // custom: typed Collection column
                        new ColumnDefinition(name: 'tags', columnType: 'json', castType: 'array', collectionItemClass: 'App\\Schemas\\TagSchema'),
                    ],
                ),
            ),
        ];

        $content = $this->generator->generate($schemas, 'Acme\\Sdk')['model_Post']->content;

        $this->assertStringContainsString('protected $casts = [', $content);
        $this->assertStringContainsString("'published_at' => 'datetime',", $content);
        $this->assertStringContainsString("'views' => 'integer',", $content);
        $this->assertStringContainsString("'is_active' => 'boolean',", $content);
        $this->assertStringContainsString("'price' => 'decimal:2',", $content);
        $this->assertStringContainsString("'metadata' => 'array',", $content);

        // Dropped — these reference classes the target project does not have.
        $this->assertStringNotContainsString('StatusEnum', $content);
        $this->assertStringNotContainsString('AddressSchema', $content);
        $this->assertStringNotContainsString('TagSchema', $content);
        $this->assertStringNotContainsString("'status' =>", $content);
        $this->assertStringNotContainsString("'address' =>", $content);
        $this->assertStringNotContainsString("'tags' =>", $content);
    }

    public function test_ships_read_only_base_class(): void
    {
        // The package ships one shared base class so writes through exported models are blocked —
        // a consuming project mutates data via the API/SDK, never these models.
        $files = $this->generator->generate($this->makeSchemas(), 'Acme\\Sdk');

        $this->assertArrayHasKey('model_base', $files);
        $this->assertSame('src/Models/ReadOnlyModel.php', $files['model_base']->path);

        $content = $files['model_base']->content;
        $this->assertStringContainsString('namespace Acme\\Sdk\\Models;', $content);
        $this->assertStringContainsString('abstract class ReadOnlyModel extends Model', $content);
        // Write entry points must throw.
        $this->assertStringContainsString('public function save(', $content);
        $this->assertStringContainsString('public function delete(', $content);
        $this->assertStringContainsString('throw new \\RuntimeException', $content);
    }

    public function test_models_extend_read_only_base(): void
    {
        $content = $this->generator->generate($this->makeSchemas(), 'Acme\\Sdk')['model_Post']->content;

        $this->assertStringContainsString('class Post extends ReadOnlyModel', $content);
        // The base lives in the same namespace, so no Eloquent Model import is needed in the model.
        $this->assertStringNotContainsString('use Illuminate\\Database\\Eloquent\\Model;', $content);
    }

    public function test_emits_relations_with_explicit_keys(): void
    {
        // Relations reference the SIBLING exported model class (e.g. Comment::class) — safe because
        // all models on the connection ship together in one package. Columns are pinned explicitly
        // so the relation is unambiguous in the target project.
        $schemas = [
            'Post' => new SdkSchemaContext(
                table: new TableDefinition(
                    tableName: 'posts',
                    schemaClass: 'App\\Schemas\\PostSchema',
                    columns: [
                        new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
                    ],
                    relationships: [
                        new RelationshipDefinition(name: 'author', type: 'belongsTo', relatedModel: 'App\\Models\\User', foreignColumn: 'author_id', ownerKey: 'id'),
                        new RelationshipDefinition(name: 'comments', type: 'hasMany', relatedModel: 'App\\Models\\Comment', foreignColumn: 'post_id', localKey: 'id'),
                        new RelationshipDefinition(name: 'tags', type: 'belongsToMany', relatedModel: 'App\\Models\\Tag', pivotTable: 'post_tag', foreignPivotKey: 'post_id', relatedPivotKey: 'tag_id'),
                    ],
                ),
            ),
        ];

        $content = $this->generator->generate($schemas, 'Acme\\Sdk')['model_Post']->content;

        $this->assertStringContainsString('public function author()', $content);
        $this->assertStringContainsString("return \$this->belongsTo(User::class, 'author_id', 'id');", $content);

        $this->assertStringContainsString('public function comments()', $content);
        $this->assertStringContainsString("return \$this->hasMany(Comment::class, 'post_id', 'id');", $content);

        $this->assertStringContainsString('public function tags()', $content);
        $this->assertStringContainsString("return \$this->belongsToMany(Tag::class, 'post_tag', 'post_id', 'tag_id', 'id', 'id');", $content);
    }

    public function test_resolves_relation_key_conventions(): void
    {
        // Null keys must be resolved to the explicit Laravel convention — the exported model cannot
        // depend on runtime convention inference being identical in the target project.
        $schemas = [
            'Post' => new SdkSchemaContext(
                table: new TableDefinition(
                    tableName: 'posts',
                    schemaClass: 'App\\Schemas\\PostSchema',
                    columns: [
                        new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
                    ],
                    relationships: [
                        // belongsTo: fk = snake(relationName)_id
                        new RelationshipDefinition(name: 'category', type: 'belongsTo', relatedModel: 'App\\Models\\Category'),
                        // hasMany: fk = snake(parentModel)_id
                        new RelationshipDefinition(name: 'comments', type: 'hasMany', relatedModel: 'App\\Models\\Comment'),
                    ],
                ),
            ),
        ];

        $content = $this->generator->generate($schemas, 'Acme\\Sdk')['model_Post']->content;

        $this->assertStringContainsString("return \$this->belongsTo(Category::class, 'category_id', 'id');", $content);
        $this->assertStringContainsString("return \$this->hasMany(Comment::class, 'post_id', 'id');", $content);
    }

    public function test_reroots_models_into_relative_subnamespace(): void
    {
        // The export preserves each model's relative sub-namespace (re-rooted under the SDK base) so
        // same-named models from different databases don't collide. App\Models\Crm\Contact becomes
        // Acme\Sdk\Models\Crm\Contact. The source model-namespace root to strip is passed in.
        $schemas = [
            'Contact' => new SdkSchemaContext(
                table: new TableDefinition(
                    tableName: 'contacts',
                    schemaClass: 'App\\Schemas\\Crm\\ContactSchema',
                    columns: [
                        new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
                    ],
                    relationships: [
                        new RelationshipDefinition(name: 'county', type: 'belongsTo', relatedModel: 'App\\Models\\Geo\\County', foreignColumn: 'county_id'),
                        new RelationshipDefinition(name: 'notes', type: 'hasMany', relatedModel: 'App\\Models\\Crm\\Note', foreignColumn: 'contact_id'),
                    ],
                ),
            ),
        ];

        $files = $this->generator->generate($schemas, 'Acme\\Sdk', 'App\\Models');

        $this->assertSame('src/Models/Crm/Contact.php', $files['model_Contact']->path);

        $content = $files['model_Contact']->content;
        $this->assertStringContainsString('namespace Acme\\Sdk\\Models\\Crm;', $content);
        $this->assertStringContainsString('class Contact extends ReadOnlyModel', $content);
        // Sub-namespaced model must import the base from the parent namespace.
        $this->assertStringContainsString('use Acme\\Sdk\\Models\\ReadOnlyModel;', $content);

        // Cross-sub-namespace relation target: imported, referenced by basename.
        $this->assertStringContainsString('use Acme\\Sdk\\Models\\Geo\\County;', $content);
        $this->assertStringContainsString("return \$this->belongsTo(County::class, 'county_id', 'id');", $content);

        // Same-sub-namespace relation target: no import needed.
        $this->assertStringContainsString("return \$this->hasMany(Note::class, 'contact_id', 'id');", $content);
        $this->assertStringNotContainsString('use Acme\\Sdk\\Models\\Crm\\Note;', $content);
    }

    public function test_emits_morph_relations(): void
    {
        $schemas = [
            'Post' => new SdkSchemaContext(
                table: new TableDefinition(
                    tableName: 'posts',
                    schemaClass: 'App\\Schemas\\PostSchema',
                    columns: [new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true)],
                    relationships: [
                        // morphTo has no related class — the morph columns are pinned from the morph name.
                        new RelationshipDefinition(name: 'commentable', type: 'morphTo', relatedModel: 'Illuminate\\Database\\Eloquent\\Model', morphName: 'commentable'),
                        new RelationshipDefinition(name: 'comments', type: 'morphMany', relatedModel: 'App\\Models\\Comment', morphName: 'commentable'),
                        new RelationshipDefinition(name: 'image', type: 'morphOne', relatedModel: 'App\\Models\\Image', morphName: 'imageable'),
                    ],
                ),
            ),
        ];

        $content = $this->generator->generate($schemas, 'Acme\\Sdk')['model_Post']->content;

        $this->assertStringContainsString("return \$this->morphTo('commentable', 'commentable_type', 'commentable_id');", $content);
        $this->assertStringContainsString("return \$this->morphMany(Comment::class, 'commentable', 'commentable_type', 'commentable_id', 'id');", $content);
        $this->assertStringContainsString("return \$this->morphOne(Image::class, 'imageable', 'imageable_type', 'imageable_id', 'id');", $content);
    }

    public function test_emits_morph_to_many_relations(): void
    {
        $schemas = [
            'Post' => new SdkSchemaContext(
                table: new TableDefinition(
                    tableName: 'posts',
                    schemaClass: 'App\\Schemas\\PostSchema',
                    columns: [new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true)],
                    relationships: [
                        new RelationshipDefinition(name: 'tags', type: 'morphToMany', relatedModel: 'App\\Models\\Tag', morphName: 'taggable'),
                        // morphedByMany is scanned as morphToMany with inverse = true.
                        new RelationshipDefinition(name: 'taggedPosts', type: 'morphToMany', relatedModel: 'App\\Models\\Post', morphName: 'taggable', inverse: true),
                    ],
                ),
            ),
        ];

        $content = $this->generator->generate($schemas, 'Acme\\Sdk')['model_Post']->content;

        $this->assertStringContainsString("return \$this->morphToMany(Tag::class, 'taggable', 'taggables', 'taggable_id', 'tag_id', 'id', 'id', false);", $content);
        $this->assertStringContainsString("return \$this->morphToMany(Post::class, 'taggable', 'taggables', 'taggable_id', 'post_id', 'id', 'id', true);", $content);
    }

    public function test_emits_through_relations(): void
    {
        $schemas = [
            'Country' => new SdkSchemaContext(
                table: new TableDefinition(
                    tableName: 'countries',
                    schemaClass: 'App\\Schemas\\CountrySchema',
                    columns: [new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true)],
                    relationships: [
                        new RelationshipDefinition(name: 'posts', type: 'hasManyThrough', relatedModel: 'App\\Models\\Post', through: 'App\\Models\\User'),
                        new RelationshipDefinition(name: 'latestPost', type: 'hasOneThrough', relatedModel: 'App\\Models\\Post', through: 'App\\Models\\User'),
                    ],
                ),
            ),
        ];

        $content = $this->generator->generate($schemas, 'Acme\\Sdk')['model_Country']->content;

        $this->assertStringContainsString("return \$this->hasManyThrough(Post::class, User::class, 'country_id', 'user_id', 'id', 'id');", $content);
        $this->assertStringContainsString("return \$this->hasOneThrough(Post::class, User::class, 'country_id', 'user_id', 'id', 'id');", $content);
    }

    public function test_omits_casts_block_when_no_native_casts(): void
    {
        $content = $this->generator->generate($this->makeSchemas(), 'Acme\\Sdk')['model_Post']->content;

        $this->assertStringNotContainsString('protected $casts', $content);
    }

    /**
     * @return array<string, SdkSchemaContext>
     */
    private function makeSchemas(): array
    {
        return [
            'Post' => new SdkSchemaContext(
                table: new TableDefinition(
                    tableName: 'posts',
                    schemaClass: 'App\\Schemas\\PostSchema',
                    columns: [
                        new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
                        new ColumnDefinition(name: 'title', columnType: 'string'),
                    ],
                ),
            ),
        ];
    }
}
