<?php

namespace SchemaCraft\Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use SchemaCraft\Writer\SchemaWriter;

class SchemaWriterTest extends TestCase
{
    private Filesystem $files;

    private SchemaWriter $writer;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->writer = new SchemaWriter($this->files);
        $this->tempDir = sys_get_temp_dir().'/schema-writer-test-'.uniqid();
        $this->files->ensureDirectoryExists($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    private function createSchemaFile(string $name, string $content): string
    {
        $path = $this->tempDir."/{$name}.php";
        $this->files->put($path, $content);

        return $path;
    }

    private function minimalSchema(): string
    {
        return <<<'PHP'
<?php

namespace App\Schemas;

use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Schema;
use SchemaCraft\Traits\TimestampsSchema;

class DogSchema extends Schema
{
    use TimestampsSchema;

    #[Primary]
    #[AutoIncrement]
    public int $id;
}
PHP;
    }

    private function schemaWithPhpDoc(): string
    {
        return <<<'PHP'
<?php

namespace App\Schemas;

use App\Models\Owner;
use Illuminate\Database\Eloquent\Relations as Eloquent;
use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Attributes\Relations\BelongsTo;
use SchemaCraft\Schema;
use SchemaCraft\Traits\TimestampsSchema;

/**
 * @method Eloquent\BelongsTo|Owner owner()
 */
class WalkSchema extends Schema
{
    use TimestampsSchema;

    #[Primary]
    #[AutoIncrement]
    public int $id;

    #[BelongsTo(Owner::class)]
    public Owner $owner;
}
PHP;
    }

    // ── BelongsTo ───────────────────────────────────────

    public function test_adds_belongs_to_relationship(): void
    {
        $path = $this->createSchemaFile('DogSchema', $this->minimalSchema());

        $result = $this->writer->addRelationship($path, 'belongsTo', 'App\Models\Owner');

        $this->assertTrue($result->success);
        $this->assertEquals('owner', $result->propertyName);

        $content = $this->files->get($path);

        $this->assertStringContainsString('use App\Models\Owner;', $content);
        $this->assertStringContainsString('use SchemaCraft\Attributes\Relations\BelongsTo;', $content);
        $this->assertStringContainsString('use Illuminate\Database\Eloquent\Relations as Eloquent;', $content);
        $this->assertStringContainsString('@method Eloquent\BelongsTo|Owner owner()', $content);
        $this->assertStringContainsString('#[BelongsTo(Owner::class)]', $content);
        $this->assertStringContainsString('public Owner $owner;', $content);
    }

    // ── HasMany ─────────────────────────────────────────

    public function test_adds_has_many_relationship(): void
    {
        $path = $this->createSchemaFile('OwnerSchema', $this->minimalSchema());

        $result = $this->writer->addRelationship($path, 'hasMany', 'App\Models\Dog');

        $this->assertTrue($result->success);
        $this->assertEquals('dogs', $result->propertyName);

        $content = $this->files->get($path);

        $this->assertStringContainsString('use App\Models\Dog;', $content);
        $this->assertStringContainsString('use SchemaCraft\Attributes\Relations\HasMany;', $content);
        $this->assertStringContainsString('use Illuminate\Database\Eloquent\Collection;', $content);
        $this->assertStringContainsString('use Illuminate\Database\Eloquent\Relations as Eloquent;', $content);
        $this->assertStringContainsString('@method Eloquent\HasMany|Dog dogs()', $content);
        $this->assertStringContainsString('/** @var Collection<int, Dog> */', $content);
        $this->assertStringContainsString('#[HasMany(Dog::class)]', $content);
        $this->assertStringContainsString('public Collection $dogs;', $content);
    }

    // ── HasOne ──────────────────────────────────────────

    public function test_adds_has_one_relationship(): void
    {
        $path = $this->createSchemaFile('UserSchema', $this->minimalSchema());

        $result = $this->writer->addRelationship($path, 'hasOne', 'App\Models\Profile');

        $this->assertTrue($result->success);
        $this->assertEquals('profile', $result->propertyName);

        $content = $this->files->get($path);

        $this->assertStringContainsString('#[HasOne(Profile::class)]', $content);
        $this->assertStringContainsString('public Profile $profile;', $content);
        $this->assertStringContainsString('@method Eloquent\HasOne|Profile profile()', $content);
        $this->assertStringNotContainsString('Collection', $content);
    }

    // ── BelongsToMany ───────────────────────────────────

    public function test_adds_belongs_to_many_relationship(): void
    {
        $path = $this->createSchemaFile('PostSchema', $this->minimalSchema());

        $result = $this->writer->addRelationship($path, 'belongsToMany', 'App\Models\Tag');

        $this->assertTrue($result->success);
        $this->assertEquals('tags', $result->propertyName);

        $content = $this->files->get($path);

        $this->assertStringContainsString('#[BelongsToMany(Tag::class)]', $content);
        $this->assertStringContainsString('public Collection $tags;', $content);
        $this->assertStringContainsString('/** @var Collection<int, Tag> */', $content);
        $this->assertStringContainsString('@method Eloquent\BelongsToMany|Tag tags()', $content);
    }

    // ── MorphTo ─────────────────────────────────────────

    public function test_adds_morph_to_relationship(): void
    {
        $path = $this->createSchemaFile('CommentSchema', $this->minimalSchema());

        $result = $this->writer->addRelationship($path, 'morphTo', 'App\Models\Post', 'commentable');

        $this->assertTrue($result->success);
        $this->assertEquals('commentable', $result->propertyName);

        $content = $this->files->get($path);

        $this->assertStringContainsString("use Illuminate\Database\Eloquent\Model;", $content);
        $this->assertStringContainsString('#[MorphTo(\'commentable\')]', $content);
        $this->assertStringContainsString('public Model $commentable;', $content);
        $this->assertStringContainsString('@method Eloquent\MorphTo|Model commentable()', $content);
    }

    // ── MorphMany ───────────────────────────────────────

    public function test_adds_morph_many_relationship(): void
    {
        $path = $this->createSchemaFile('PostSchema', $this->minimalSchema());

        $result = $this->writer->addRelationship($path, 'morphMany', 'App\Models\Comment', 'commentable');

        $this->assertTrue($result->success);
        $this->assertEquals('comments', $result->propertyName);

        $content = $this->files->get($path);

        $this->assertStringContainsString('#[MorphMany(Comment::class, \'commentable\')]', $content);
        $this->assertStringContainsString('public Collection $comments;', $content);
        $this->assertStringContainsString('/** @var Collection<int, Comment> */', $content);
        $this->assertStringContainsString('@method Eloquent\MorphMany|Comment comments()', $content);
    }

    // ── MorphOne ────────────────────────────────────────

    public function test_adds_morph_one_relationship(): void
    {
        $path = $this->createSchemaFile('PostSchema', $this->minimalSchema());

        $result = $this->writer->addRelationship($path, 'morphOne', 'App\Models\Image', 'imageable');

        $this->assertTrue($result->success);
        $this->assertEquals('image', $result->propertyName);

        $content = $this->files->get($path);

        $this->assertStringContainsString('#[MorphOne(Image::class, \'imageable\')]', $content);
        $this->assertStringContainsString('public Image $image;', $content);
        $this->assertStringContainsString('@method Eloquent\MorphOne|Image image()', $content);
        $this->assertStringNotContainsString('Collection', $content);
    }

    // ── MorphToMany ─────────────────────────────────────

    public function test_adds_morph_to_many_relationship(): void
    {
        $path = $this->createSchemaFile('PostSchema', $this->minimalSchema());

        $result = $this->writer->addRelationship($path, 'morphToMany', 'App\Models\Tag', 'taggable');

        $this->assertTrue($result->success);
        $this->assertEquals('tags', $result->propertyName);

        $content = $this->files->get($path);

        $this->assertStringContainsString('#[MorphToMany(Tag::class, \'taggable\')]', $content);
        $this->assertStringContainsString('public Collection $tags;', $content);
        $this->assertStringContainsString('/** @var Collection<int, Tag> */', $content);
        $this->assertStringContainsString('@method Eloquent\MorphToMany|Tag tags()', $content);
    }

    // ── Duplicate detection ─────────────────────────────

    public function test_does_not_duplicate_existing_relationship(): void
    {
        $path = $this->createSchemaFile('DogSchema', $this->minimalSchema());

        $first = $this->writer->addRelationship($path, 'belongsTo', 'App\Models\Owner');
        $second = $this->writer->addRelationship($path, 'belongsTo', 'App\Models\Owner');

        $this->assertTrue($first->success);
        $this->assertFalse($second->success);
        $this->assertStringContainsString('already exists', $second->message);
    }

    // ── Import deduplication ────────────────────────────

    public function test_does_not_duplicate_existing_imports(): void
    {
        $path = $this->createSchemaFile('OwnerSchema', $this->minimalSchema());

        $this->writer->addRelationship($path, 'hasMany', 'App\Models\Dog');
        $this->writer->addRelationship($path, 'hasMany', 'App\Models\Walk');

        $content = $this->files->get($path);

        $this->assertEquals(1, substr_count($content, 'use Illuminate\Database\Eloquent\Collection;'));
        $this->assertEquals(1, substr_count($content, 'use Illuminate\Database\Eloquent\Relations as Eloquent;'));
        $this->assertEquals(1, substr_count($content, 'use SchemaCraft\Attributes\Relations\HasMany;'));
    }

    // ── PHPDoc block creation ───────────────────────────

    public function test_creates_phpdoc_block_when_none_exists(): void
    {
        $path = $this->createSchemaFile('DogSchema', $this->minimalSchema());

        $this->writer->addRelationship($path, 'belongsTo', 'App\Models\Owner');

        $content = $this->files->get($path);

        $this->assertMatchesRegularExpression('/\/\*\*\n \* @method Eloquent\\\\BelongsTo\|Owner owner\(\)\n \*\/\nclass DogSchema/', $content);
    }

    // ── PHPDoc block appending ──────────────────────────

    public function test_appends_to_existing_phpdoc_block(): void
    {
        $path = $this->createSchemaFile('WalkSchema', $this->schemaWithPhpDoc());

        $this->writer->addRelationship($path, 'belongsTo', 'App\Models\Dog');

        $content = $this->files->get($path);

        $this->assertStringContainsString('@method Eloquent\BelongsTo|Owner owner()', $content);
        $this->assertStringContainsString('@method Eloquent\BelongsTo|Dog dog()', $content);
        $this->assertEquals(1, substr_count($content, '/**'));
    }

    // ── PHPDoc does not drift ─────────────────────────────

    public function test_phpdoc_does_not_drift_with_multiple_insertions(): void
    {
        $path = $this->createSchemaFile('FeedingSchema', $this->minimalSchema());

        $this->writer->addRelationship($path, 'belongsTo', 'App\Models\Dog');
        $this->writer->addRelationship($path, 'belongsTo', 'App\Models\Food');
        $this->writer->addRelationship($path, 'belongsTo', 'App\Models\Owner');

        $content = $this->files->get($path);

        $this->assertStringContainsString(" * @method Eloquent\\BelongsTo|Dog dog()\n * @method Eloquent\\BelongsTo|Food food()\n * @method Eloquent\\BelongsTo|Owner owner()\n */\n", $content);
    }

    // ── File not found ──────────────────────────────────

    public function test_fails_when_schema_file_not_found(): void
    {
        $result = $this->writer->addRelationship('/nonexistent/path.php', 'belongsTo', 'App\Models\Owner');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('not found', $result->message);
    }

    // ── Unknown relationship type ───────────────────────

    public function test_fails_with_unknown_relationship_type(): void
    {
        $path = $this->createSchemaFile('DogSchema', $this->minimalSchema());

        $result = $this->writer->addRelationship($path, 'invalidType', 'App\Models\Owner');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Unknown relationship type', $result->message);
    }

    // ── Custom property name ────────────────────────────

    public function test_uses_custom_property_name(): void
    {
        $path = $this->createSchemaFile('DogSchema', $this->minimalSchema());

        $result = $this->writer->addRelationship($path, 'belongsTo', 'App\Models\Owner', null, 'currentOwner');

        $this->assertTrue($result->success);
        $this->assertEquals('currentOwner', $result->propertyName);

        $content = $this->files->get($path);

        $this->assertStringContainsString('public Owner $currentOwner;', $content);
        $this->assertStringContainsString('@method Eloquent\BelongsTo|Owner currentOwner()', $content);
    }

    // ── Valid PHP ───────────────────────────────────────

    public function test_generated_file_is_valid_php(): void
    {
        $path = $this->createSchemaFile('DogSchema', $this->minimalSchema());

        $this->writer->addRelationship($path, 'belongsTo', 'App\Models\Owner');
        $this->writer->addRelationship($path, 'hasMany', 'App\Models\Walk');

        $output = [];
        $exitCode = 0;
        exec('php -l '.escapeshellarg($path).' 2>&1', $output, $exitCode);

        $this->assertEquals(0, $exitCode, 'Generated file has PHP syntax errors: '.implode("\n", $output));
    }

    // ── Success message includes schema name ────────────

    public function test_success_message_includes_schema_and_relationship(): void
    {
        $path = $this->createSchemaFile('DogSchema', $this->minimalSchema());

        $result = $this->writer->addRelationship($path, 'belongsTo', 'App\Models\Owner');

        $this->assertStringContainsString('BelongsTo', $result->message);
        $this->assertStringContainsString('Owner', $result->message);
        $this->assertStringContainsString('DogSchema', $result->message);
    }
}
