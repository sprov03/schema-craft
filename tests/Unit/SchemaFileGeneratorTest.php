<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Generator\SchemaFileGenerator;
use SchemaCraft\Migration\DatabaseColumnState;
use SchemaCraft\Migration\DatabaseForeignKeyState;
use SchemaCraft\Migration\DatabaseIndexState;
use SchemaCraft\Migration\DatabaseTableState;

class SchemaFileGeneratorTest extends TestCase
{
    private SchemaFileGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new SchemaFileGenerator;
    }

    // --- Model Name Resolution ---

    public function test_resolves_model_name_from_plural_table(): void
    {
        $this->assertSame('Dog', $this->generator->resolveModelName('dogs'));
    }

    public function test_resolves_model_name_from_multi_word_table(): void
    {
        $this->assertSame('UserProfile', $this->generator->resolveModelName('user_profiles'));
    }

    public function test_resolves_model_name_from_singular_table(): void
    {
        $this->assertSame('Cache', $this->generator->resolveModelName('cache'));
    }

    // --- Column Type Mapping ---

    public function test_maps_string_column(): void
    {
        $table = $this->makeTable('tests', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('name', 'string'),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString('public string $name;', $result->schemaContent);
    }

    public function test_maps_string_column_with_length(): void
    {
        $table = $this->makeTable('tests', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('subtitle', 'string', length: 100),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString('#[Length(100)]', $result->schemaContent);
        $this->assertStringContainsString('public string $subtitle;', $result->schemaContent);
    }

    public function test_skips_length_attribute_for_default255(): void
    {
        $table = $this->makeTable('tests', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('name', 'string', length: 255),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringNotContainsString('#[Length', $result->schemaContent);
    }

    public function test_maps_text_column(): void
    {
        $table = $this->makeTable('tests', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('body', 'text'),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString('#[Text]', $result->schemaContent);
        $this->assertStringContainsString('public string $body;', $result->schemaContent);
    }

    public function test_maps_medium_text_column(): void
    {
        $table = $this->makeTable('tests', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('content', 'mediumText'),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString('#[MediumText]', $result->schemaContent);
    }

    public function test_maps_long_text_column(): void
    {
        $table = $this->makeTable('tests', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('html', 'longText'),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString('#[LongText]', $result->schemaContent);
    }

    public function test_maps_integer_column(): void
    {
        $table = $this->makeTable('tests', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('count', 'integer'),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString('public int $count;', $result->schemaContent);
        $this->assertStringNotContainsString('#[BigInt]', $result->schemaContent);
    }

    public function test_maps_big_integer_column(): void
    {
        $table = $this->makeTable('tests', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('total', 'bigInteger'),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString('#[BigInt]', $result->schemaContent);
        $this->assertStringContainsString('public int $total;', $result->schemaContent);
    }

    public function test_maps_small_integer_column(): void
    {
        $table = $this->makeTable('tests', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('rank', 'smallInteger'),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString('#[SmallInt]', $result->schemaContent);
    }

    public function test_maps_tiny_integer_column(): void
    {
        $table = $this->makeTable('tests', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('level', 'tinyInteger'),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString('#[TinyInt]', $result->schemaContent);
    }

    public function test_maps_boolean_column(): void
    {
        $table = $this->makeTable('tests', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('is_active', 'boolean'),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString('public bool $is_active;', $result->schemaContent);
    }

    public function test_maps_double_column(): void
    {
        $table = $this->makeTable('tests', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('rating', 'double'),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString('public float $rating;', $result->schemaContent);
        $this->assertStringNotContainsString('#[FloatColumn]', $result->schemaContent);
    }

    public function test_maps_float_column(): void
    {
        $table = $this->makeTable('tests', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('score', 'float'),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString('#[FloatColumn]', $result->schemaContent);
    }

    public function test_maps_decimal_column(): void
    {
        $table = $this->makeTable('tests', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('price', 'decimal', precision: 10, scale: 2),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString('#[Decimal(10, 2)]', $result->schemaContent);
        $this->assertStringContainsString('public float $price;', $result->schemaContent);
    }

    public function test_maps_json_column(): void
    {
        $table = $this->makeTable('tests', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('metadata', 'json'),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString('public array $metadata;', $result->schemaContent);
    }

    public function test_maps_timestamp_column(): void
    {
        $table = $this->makeTable('tests', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('published_at', 'timestamp', nullable: true),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString('public ?Carbon $published_at;', $result->schemaContent);
        $this->assertStringContainsString('use Illuminate\\Support\\Carbon;', $result->schemaContent);
    }

    public function test_maps_date_column(): void
    {
        $table = $this->makeTable('tests', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('birthday', 'date'),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString('#[Date]', $result->schemaContent);
        $this->assertStringContainsString('public Carbon $birthday;', $result->schemaContent);
    }

    public function test_maps_time_column(): void
    {
        $table = $this->makeTable('tests', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('starts_at', 'time'),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString('#[Time]', $result->schemaContent);
    }

    public function test_maps_year_column(): void
    {
        $table = $this->makeTable('tests', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('graduation_year', 'year'),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString('#[Year]', $result->schemaContent);
        $this->assertStringContainsString('public int $graduation_year;', $result->schemaContent);
    }

    // --- Primary Key ---

    public function test_maps_auto_increment_primary_key_to_int(): void
    {
        $table = $this->makeTable('tests', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString('#[Primary]', $result->schemaContent);
        $this->assertStringContainsString('#[AutoIncrement]', $result->schemaContent);
        $this->assertStringContainsString('public int $id;', $result->schemaContent);
        $this->assertStringContainsString('use SchemaCraft\\Attributes\\Primary;', $result->schemaContent);
        $this->assertStringContainsString('use SchemaCraft\\Attributes\\AutoIncrement;', $result->schemaContent);
    }

    public function test_maps_uuid_primary_key(): void
    {
        $table = $this->makeTable('tests', [
            $this->col('id', 'uuid', primary: true),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString('#[Primary]', $result->schemaContent);
        $this->assertStringContainsString("#[ColumnType('uuid')]", $result->schemaContent);
        $this->assertStringContainsString('public string $id;', $result->schemaContent);
        $this->assertStringContainsString('use SchemaCraft\\Attributes\\Primary;', $result->schemaContent);
        $this->assertStringContainsString('use SchemaCraft\\Attributes\\ColumnType;', $result->schemaContent);
    }

    public function test_maps_ulid_primary_key(): void
    {
        $table = $this->makeTable('tests', [
            $this->col('id', 'ulid', primary: true),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString('#[Primary]', $result->schemaContent);
        $this->assertStringContainsString("#[ColumnType('ulid')]", $result->schemaContent);
        $this->assertStringContainsString('public string $id;', $result->schemaContent);
    }

    // --- Nullable and Defaults ---

    public function test_handles_nullable_column(): void
    {
        $table = $this->makeTable('tests', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('bio', 'string', nullable: true),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString('public ?string $bio;', $result->schemaContent);
    }

    public function test_handles_integer_default(): void
    {
        $table = $this->makeTable('tests', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('views', 'integer', default: 0, hasDefault: true),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString('public int $views = 0;', $result->schemaContent);
    }

    public function test_handles_boolean_default(): void
    {
        $table = $this->makeTable('tests', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('is_featured', 'boolean', default: false, hasDefault: true),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString('public bool $is_featured = false;', $result->schemaContent);
    }

    public function test_handles_string_default(): void
    {
        $table = $this->makeTable('tests', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('role', 'string', default: 'member', hasDefault: true),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString("public string \$role = 'member';", $result->schemaContent);
    }

    // --- Unsigned ---

    public function test_handles_unsigned_integer(): void
    {
        $table = $this->makeTable('tests', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('quantity', 'integer', unsigned: true),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString('#[Unsigned]', $result->schemaContent);
    }

    // --- Indexes ---

    public function test_handles_unique_index(): void
    {
        $table = $this->makeTable('tests', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('email', 'string'),
        ], indexes: [
            new DatabaseIndexState('tests_email_unique', ['email'], unique: true),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString('#[Unique]', $result->schemaContent);
    }

    public function test_handles_regular_index(): void
    {
        $table = $this->makeTable('tests', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('slug', 'string'),
        ], indexes: [
            new DatabaseIndexState('tests_slug_index', ['slug']),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString('#[Index]', $result->schemaContent);
    }

    public function test_handles_composite_index(): void
    {
        $table = $this->makeTable('tests', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('status', 'string'),
            $this->col('published_at', 'timestamp', nullable: true),
        ], indexes: [
            new DatabaseIndexState('tests_status_published_at_index', ['status', 'published_at']),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString("#[Index(['status', 'published_at'])]", $result->schemaContent);
    }

    // --- Timestamps and Soft Deletes ---

    public function test_detects_timestamps(): void
    {
        $table = $this->makeTable('tests', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('name', 'string'),
            $this->col('created_at', 'timestamp', nullable: true),
            $this->col('updated_at', 'timestamp', nullable: true),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString('use TimestampsSchema;', $result->schemaContent);
        $this->assertStringNotContainsString('$created_at', $result->schemaContent);
        $this->assertStringNotContainsString('$updated_at', $result->schemaContent);
    }

    public function test_detects_soft_deletes(): void
    {
        $table = $this->makeTable('tests', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('created_at', 'timestamp', nullable: true),
            $this->col('updated_at', 'timestamp', nullable: true),
            $this->col('deleted_at', 'timestamp', nullable: true),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString('use SoftDeletesSchema;', $result->schemaContent);
        $this->assertTrue($result->hasSoftDeletes);
        $this->assertStringNotContainsString('$deleted_at', $result->schemaContent);
    }

    // --- BelongsTo Relationships ---

    public function test_detects_belongs_to_from_foreign_key(): void
    {
        $table = $this->makeTable('dogs', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('owner_id', 'unsignedBigInteger'),
        ], foreignKeys: [
            new DatabaseForeignKeyState('owner_id', 'owners', 'id'),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString('#[BelongsTo(Owner::class)]', $result->schemaContent);
        $this->assertStringContainsString('public Owner $owner;', $result->schemaContent);
        // FK column should NOT appear as a separate property
        $this->assertStringNotContainsString('public int $owner_id', $result->schemaContent);
    }

    public function test_detects_nullable_belongs_to(): void
    {
        $table = $this->makeTable('dogs', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('owner_id', 'unsignedBigInteger', nullable: true),
        ], foreignKeys: [
            new DatabaseForeignKeyState('owner_id', 'owners', 'id'),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString('public ?Owner $owner;', $result->schemaContent);
    }

    public function test_detects_on_delete_cascade(): void
    {
        $table = $this->makeTable('dogs', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('owner_id', 'unsignedBigInteger'),
        ], foreignKeys: [
            new DatabaseForeignKeyState('owner_id', 'owners', 'id', onDelete: 'cascade'),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString("#[OnDelete('cascade')]", $result->schemaContent);
    }

    public function test_skips_default_on_delete_action(): void
    {
        $table = $this->makeTable('dogs', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('owner_id', 'unsignedBigInteger'),
        ], foreignKeys: [
            new DatabaseForeignKeyState('owner_id', 'owners', 'id', onDelete: 'no action'),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringNotContainsString('#[OnDelete', $result->schemaContent);
    }

    public function test_handles_multiple_foreign_keys_to_same_table(): void
    {
        $table = $this->makeTable('messages', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('sender_id', 'unsignedBigInteger'),
            $this->col('recipient_id', 'unsignedBigInteger'),
        ], foreignKeys: [
            new DatabaseForeignKeyState('sender_id', 'users', 'id'),
            new DatabaseForeignKeyState('recipient_id', 'users', 'id'),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString('public User $sender;', $result->schemaContent);
        $this->assertStringContainsString('public User $recipient;', $result->schemaContent);
    }

    public function test_handles_fk_column_not_ending_in_id(): void
    {
        $table = $this->makeTable('posts', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('created_by', 'unsignedBigInteger'),
        ], foreignKeys: [
            new DatabaseForeignKeyState('created_by', 'users', 'id'),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString('public User $createdBy;', $result->schemaContent);
    }

    // --- HasMany Inverses ---

    public function test_generates_has_many_inverse(): void
    {
        $ownersTable = $this->makeTable('owners', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('name', 'string'),
        ]);

        $dogsTable = $this->makeTable('dogs', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('owner_id', 'unsignedBigInteger'),
        ], foreignKeys: [
            new DatabaseForeignKeyState('owner_id', 'owners', 'id'),
        ]);

        $allTables = [
            'owners' => $ownersTable,
            'dogs' => $dogsTable,
        ];

        $result = $this->generator->generate($ownersTable, $allTables);

        $this->assertStringContainsString('#[HasMany(Dog::class)]', $result->schemaContent);
        $this->assertStringContainsString('public Collection $dogs;', $result->schemaContent);
        $this->assertStringContainsString('/** @var Collection<int, Dog> */', $result->schemaContent);
    }

    public function test_has_many_single_fk_keeps_standard_name(): void
    {
        $usersTable = $this->makeTable('users', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('name', 'string'),
        ]);

        $postsTable = $this->makeTable('posts', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('user_id', 'unsignedBigInteger'),
        ], foreignKeys: [
            new DatabaseForeignKeyState('user_id', 'users', 'id'),
        ]);

        $allTables = [
            'users' => $usersTable,
            'posts' => $postsTable,
        ];

        $result = $this->generator->generate($usersTable, $allTables);

        $this->assertStringContainsString('public Collection $posts;', $result->schemaContent);
        $this->assertStringContainsString('#[HasMany(Post::class)]', $result->schemaContent);
        $this->assertStringNotContainsString('#[ForeignColumn', $result->schemaContent);
    }

    public function test_has_many_collision_generates_unique_names(): void
    {
        $usersTable = $this->makeTable('users', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('name', 'string'),
        ]);

        $tasksTable = $this->makeTable('tasks', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('creator_user_id', 'unsignedBigInteger'),
            $this->col('assigned_to_user_id', 'unsignedBigInteger'),
        ], foreignKeys: [
            new DatabaseForeignKeyState('creator_user_id', 'users', 'id'),
            new DatabaseForeignKeyState('assigned_to_user_id', 'users', 'id'),
        ]);

        $allTables = [
            'users' => $usersTable,
            'tasks' => $tasksTable,
        ];

        $result = $this->generator->generate($usersTable, $allTables);

        $this->assertStringContainsString('public Collection $creatorUserTasks;', $result->schemaContent);
        $this->assertStringContainsString('public Collection $assignedToUserTasks;', $result->schemaContent);
        // Standard name should NOT appear
        $this->assertStringNotContainsString('public Collection $tasks;', $result->schemaContent);
    }

    public function test_has_many_collision_adds_foreign_column_attribute(): void
    {
        $usersTable = $this->makeTable('users', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
        ]);

        $tasksTable = $this->makeTable('tasks', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('creator_user_id', 'unsignedBigInteger'),
            $this->col('assigned_to_user_id', 'unsignedBigInteger'),
        ], foreignKeys: [
            new DatabaseForeignKeyState('creator_user_id', 'users', 'id'),
            new DatabaseForeignKeyState('assigned_to_user_id', 'users', 'id'),
        ]);

        $allTables = [
            'users' => $usersTable,
            'tasks' => $tasksTable,
        ];

        $result = $this->generator->generate($usersTable, $allTables);

        $this->assertStringContainsString("#[ForeignColumn('creator_user_id')]", $result->schemaContent);
        $this->assertStringContainsString("#[ForeignColumn('assigned_to_user_id')]", $result->schemaContent);
        $this->assertStringContainsString('use SchemaCraft\\Attributes\\ForeignColumn;', $result->schemaContent);
    }

    public function test_has_many_collision_method_docblock_uses_unique_names(): void
    {
        $usersTable = $this->makeTable('users', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
        ]);

        $tasksTable = $this->makeTable('tasks', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('creator_user_id', 'unsignedBigInteger'),
            $this->col('assigned_to_user_id', 'unsignedBigInteger'),
        ], foreignKeys: [
            new DatabaseForeignKeyState('creator_user_id', 'users', 'id'),
            new DatabaseForeignKeyState('assigned_to_user_id', 'users', 'id'),
        ]);

        $allTables = [
            'users' => $usersTable,
            'tasks' => $tasksTable,
        ];

        $result = $this->generator->generate($usersTable, $allTables);

        $this->assertStringContainsString('@method Eloquent\\HasMany|Task creatorUserTasks()', $result->schemaContent);
        $this->assertStringContainsString('@method Eloquent\\HasMany|Task assignedToUserTasks()', $result->schemaContent);
    }

    public function test_has_many_no_collision_across_different_models(): void
    {
        $usersTable = $this->makeTable('users', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
        ]);

        $postsTable = $this->makeTable('posts', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('user_id', 'unsignedBigInteger'),
        ], foreignKeys: [
            new DatabaseForeignKeyState('user_id', 'users', 'id'),
        ]);

        $commentsTable = $this->makeTable('comments', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('user_id', 'unsignedBigInteger'),
        ], foreignKeys: [
            new DatabaseForeignKeyState('user_id', 'users', 'id'),
        ]);

        $allTables = [
            'users' => $usersTable,
            'posts' => $postsTable,
            'comments' => $commentsTable,
        ];

        $result = $this->generator->generate($usersTable, $allTables);

        $this->assertStringContainsString('public Collection $posts;', $result->schemaContent);
        $this->assertStringContainsString('public Collection $comments;', $result->schemaContent);
        $this->assertStringNotContainsString('#[ForeignColumn', $result->schemaContent);
    }

    public function test_has_many_self_referencing_generates_unique_names(): void
    {
        $tasksTable = $this->makeTable('tasks', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('name', 'string'),
            $this->col('predecessor_task_id', 'unsignedBigInteger', nullable: true),
            $this->col('successor_task_id', 'unsignedBigInteger', nullable: true),
        ], foreignKeys: [
            new DatabaseForeignKeyState('predecessor_task_id', 'tasks', 'id'),
            new DatabaseForeignKeyState('successor_task_id', 'tasks', 'id'),
        ]);

        $allTables = ['tasks' => $tasksTable];

        $result = $this->generator->generate($tasksTable, $allTables);

        // BelongsTo side should still work
        $this->assertStringContainsString('#[BelongsTo(Task::class)]', $result->schemaContent);
        $this->assertStringContainsString('public ?Task $predecessorTask;', $result->schemaContent);
        $this->assertStringContainsString('public ?Task $successorTask;', $result->schemaContent);

        // HasMany side — self-referencing with collision should have unique names
        $this->assertStringContainsString('public Collection $predecessorTaskTasks;', $result->schemaContent);
        $this->assertStringContainsString('public Collection $successorTaskTasks;', $result->schemaContent);
        $this->assertStringContainsString("#[ForeignColumn('predecessor_task_id')]", $result->schemaContent);
        $this->assertStringContainsString("#[ForeignColumn('successor_task_id')]", $result->schemaContent);
    }

    public function test_has_many_three_fks_to_same_table(): void
    {
        $usersTable = $this->makeTable('users', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
        ]);

        $loansTable = $this->makeTable('loans', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('originator_user_id', 'unsignedBigInteger'),
            $this->col('processor_user_id', 'unsignedBigInteger'),
            $this->col('updated_by_id', 'unsignedBigInteger'),
        ], foreignKeys: [
            new DatabaseForeignKeyState('originator_user_id', 'users', 'id'),
            new DatabaseForeignKeyState('processor_user_id', 'users', 'id'),
            new DatabaseForeignKeyState('updated_by_id', 'users', 'id'),
        ]);

        $allTables = [
            'users' => $usersTable,
            'loans' => $loansTable,
        ];

        $result = $this->generator->generate($usersTable, $allTables);

        $this->assertStringContainsString('public Collection $originatorUserLoans;', $result->schemaContent);
        $this->assertStringContainsString('public Collection $processorUserLoans;', $result->schemaContent);
        $this->assertStringContainsString('public Collection $updatedByLoans;', $result->schemaContent);
        $this->assertSame(3, substr_count($result->schemaContent, '/** @var Collection<int, Loan> */'));
    }

    // --- Pivot Table Detection ---

    public function test_detects_pivot_table(): void
    {
        $table = $this->makeTable('dog_owner', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('dog_id', 'unsignedBigInteger'),
            $this->col('owner_id', 'unsignedBigInteger'),
        ], foreignKeys: [
            new DatabaseForeignKeyState('dog_id', 'dogs', 'id'),
            new DatabaseForeignKeyState('owner_id', 'owners', 'id'),
        ]);

        $pivot = $this->generator->detectPivotTable($table);

        $this->assertNotNull($pivot);
        $this->assertSame('dogs', $pivot['tableA']);
        $this->assertSame('owners', $pivot['tableB']);
    }

    public function test_does_not_detect_non_pivot_table(): void
    {
        $table = $this->makeTable('dogs', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('name', 'string'),
            $this->col('owner_id', 'unsignedBigInteger'),
        ], foreignKeys: [
            new DatabaseForeignKeyState('owner_id', 'owners', 'id'),
        ]);

        $pivot = $this->generator->detectPivotTable($table);

        $this->assertNull($pivot);
    }

    // --- MorphTo Detection ---

    public function test_detects_morph_to_columns(): void
    {
        $table = $this->makeTable('comments', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('body', 'text'),
            $this->col('commentable_type', 'string'),
            $this->col('commentable_id', 'unsignedBigInteger'),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString("#[MorphTo('commentable')]", $result->schemaContent);
        $this->assertStringContainsString('public Model $commentable;', $result->schemaContent);
        // MorphTo columns should NOT appear as separate properties
        $this->assertStringNotContainsString('$commentable_type', $result->schemaContent);
        $this->assertStringNotContainsString('$commentable_id', $result->schemaContent);
    }

    public function test_skips_morph_to_when_id_has_foreign_key(): void
    {
        $table = $this->makeTable('comments', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('post_type', 'string'),
            $this->col('post_id', 'unsignedBigInteger'),
        ], foreignKeys: [
            new DatabaseForeignKeyState('post_id', 'posts', 'id'),
        ]);

        $result = $this->generator->generate($table);

        // Should be BelongsTo, not MorphTo, because post_id has a FK constraint
        $this->assertStringNotContainsString('#[MorphTo', $result->schemaContent);
        $this->assertStringContainsString('#[BelongsTo(Post::class)]', $result->schemaContent);
    }

    // --- Generated File Structure ---

    public function test_schema_file_is_valid_php(): void
    {
        $table = $this->makeTable('dogs', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('name', 'string'),
            $this->col('owner_id', 'unsignedBigInteger'),
            $this->col('created_at', 'timestamp', nullable: true),
            $this->col('updated_at', 'timestamp', nullable: true),
        ], foreignKeys: [
            new DatabaseForeignKeyState('owner_id', 'owners', 'id'),
        ]);

        $result = $this->generator->generate($table);

        // Check that it starts with <?php
        $this->assertStringStartsWith('<?php', $result->schemaContent);

        // Check namespace
        $this->assertStringContainsString('namespace App\\Schemas;', $result->schemaContent);

        // Check class declaration
        $this->assertStringContainsString('class DogSchema extends Schema', $result->schemaContent);
    }

    public function test_model_file_structure(): void
    {
        $table = $this->makeTable('dogs', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('name', 'string'),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString('namespace App\\Models;', $result->modelContent);
        $this->assertStringContainsString('@mixin DogSchema', $result->modelContent);
        $this->assertStringContainsString('class Dog extends BaseModel', $result->modelContent);
        $this->assertStringContainsString('protected static string $schema = DogSchema::class;', $result->modelContent);
        $this->assertSame('Dog', $result->modelClassName);
        $this->assertSame('DogSchema', $result->schemaClassName);
    }

    public function test_model_with_soft_deletes_includes_trait(): void
    {
        $table = $this->makeTable('dogs', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('created_at', 'timestamp', nullable: true),
            $this->col('updated_at', 'timestamp', nullable: true),
            $this->col('deleted_at', 'timestamp', nullable: true),
        ]);

        $result = $this->generator->generate($table);

        $this->assertStringContainsString('use SoftDeletes;', $result->modelContent);
        $this->assertStringContainsString('use Illuminate\\Database\\Eloquent\\SoftDeletes;', $result->modelContent);
    }

    public function test_import_ordering_follows_convention(): void
    {
        $ownersTable = $this->makeTable('owners', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('name', 'string'),
            $this->col('created_at', 'timestamp', nullable: true),
            $this->col('updated_at', 'timestamp', nullable: true),
        ]);

        $dogsTable = $this->makeTable('dogs', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('owner_id', 'unsignedBigInteger'),
            $this->col('created_at', 'timestamp', nullable: true),
            $this->col('updated_at', 'timestamp', nullable: true),
        ], foreignKeys: [
            new DatabaseForeignKeyState('owner_id', 'owners', 'id'),
        ]);

        $result = $this->generator->generate($ownersTable, ['owners' => $ownersTable, 'dogs' => $dogsTable]);

        $content = $result->schemaContent;

        // App\Models imports should come before Illuminate imports
        $appPos = strpos($content, 'use App\\');
        $illuminatePos = strpos($content, 'use Illuminate\\');
        $schemaCraftPos = strpos($content, 'use SchemaCraft\\');

        $this->assertNotFalse($appPos);
        $this->assertNotFalse($illuminatePos);
        $this->assertNotFalse($schemaCraftPos);
        $this->assertLessThan($illuminatePos, $appPos);
        $this->assertLessThan($schemaCraftPos, $illuminatePos);
    }

    public function test_method_docblock_generated(): void
    {
        $ownersTable = $this->makeTable('owners', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
        ]);

        $dogsTable = $this->makeTable('dogs', [
            $this->col('id', 'unsignedBigInteger', primary: true, autoIncrement: true),
            $this->col('owner_id', 'unsignedBigInteger'),
        ], foreignKeys: [
            new DatabaseForeignKeyState('owner_id', 'owners', 'id'),
        ]);

        $result = $this->generator->generate($ownersTable, ['owners' => $ownersTable, 'dogs' => $dogsTable]);

        $this->assertStringContainsString('@method Eloquent\\HasMany|Dog dogs()', $result->schemaContent);
    }

    // --- Helpers ---

    private function col(
        string $name,
        string $type,
        bool $nullable = false,
        mixed $default = null,
        bool $hasDefault = false,
        bool $unsigned = false,
        ?int $length = null,
        ?int $precision = null,
        ?int $scale = null,
        bool $primary = false,
        bool $autoIncrement = false,
    ): DatabaseColumnState {
        return new DatabaseColumnState(
            name: $name,
            type: $type,
            nullable: $nullable,
            default: $default,
            hasDefault: $hasDefault,
            unsigned: $unsigned,
            length: $length,
            precision: $precision,
            scale: $scale,
            primary: $primary,
            autoIncrement: $autoIncrement,
        );
    }

    /**
     * @param  DatabaseColumnState[]  $columns
     * @param  DatabaseIndexState[]  $indexes
     * @param  DatabaseForeignKeyState[]  $foreignKeys
     */
    private function makeTable(
        string $name,
        array $columns,
        array $indexes = [],
        array $foreignKeys = [],
    ): DatabaseTableState {
        return new DatabaseTableState(
            tableName: $name,
            columns: $columns,
            indexes: $indexes,
            foreignKeys: $foreignKeys,
        );
    }
}
