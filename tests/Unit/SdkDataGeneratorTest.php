<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Generator\Sdk\SdkDataGenerator;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Scanner\RelationshipDefinition;
use SchemaCraft\Scanner\TableDefinition;

class SdkDataGeneratorTest extends TestCase
{
    private SdkDataGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new SdkDataGenerator;
    }

    public function test_generates_class_with_correct_namespace_and_class_name(): void
    {
        $table = $this->makeTable([
            new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
            new ColumnDefinition(name: 'title', columnType: 'string'),
        ]);

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Data', 'Post');

        $this->assertStringContainsString('namespace MyApp\\Sdk\\Data;', $output);
        $this->assertStringContainsString('class PostData', $output);
    }

    public function test_generates_readonly_properties_for_columns(): void
    {
        $table = $this->makeTable([
            new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
            new ColumnDefinition(name: 'title', columnType: 'string'),
            new ColumnDefinition(name: 'view_count', columnType: 'integer'),
        ]);

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Data', 'Post');

        $this->assertStringContainsString('public readonly int $id', $output);
        $this->assertStringContainsString('public readonly string $title', $output);
        $this->assertStringContainsString('public readonly int $viewCount', $output);
    }

    public function test_nullable_column_becomes_nullable_property(): void
    {
        $table = $this->makeTable([
            new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true),
            new ColumnDefinition(name: 'body', columnType: 'text', nullable: true),
        ]);

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Data', 'Post');

        $this->assertStringContainsString('public readonly ?string $body', $output);
    }

    public function test_non_nullable_column_becomes_required_property(): void
    {
        $table = $this->makeTable([
            new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true),
            new ColumnDefinition(name: 'title', columnType: 'string', nullable: false),
        ]);

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Data', 'Post');

        $this->assertStringContainsString('public readonly string $title', $output);
        $this->assertStringNotContainsString('?string $title', $output);
    }

    public function test_maps_integer_column_types(): void
    {
        $table = $this->makeTable([
            new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true),
            new ColumnDefinition(name: 'count', columnType: 'integer'),
            new ColumnDefinition(name: 'big', columnType: 'bigInteger'),
            new ColumnDefinition(name: 'small', columnType: 'smallInteger'),
            new ColumnDefinition(name: 'tiny', columnType: 'tinyInteger'),
        ]);

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Data', 'Item');

        $this->assertStringContainsString('public readonly int $count', $output);
        $this->assertStringContainsString('public readonly int $big', $output);
        $this->assertStringContainsString('public readonly int $small', $output);
        $this->assertStringContainsString('public readonly int $tiny', $output);
    }

    public function test_maps_boolean_column_type(): void
    {
        $table = $this->makeTable([
            new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true),
            new ColumnDefinition(name: 'is_active', columnType: 'boolean'),
        ]);

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Data', 'User');

        $this->assertStringContainsString('public readonly bool $isActive', $output);
    }

    public function test_maps_decimal_column_type(): void
    {
        $table = $this->makeTable([
            new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true),
            new ColumnDefinition(name: 'price', columnType: 'decimal'),
        ]);

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Data', 'Product');

        $this->assertStringContainsString('public readonly float $price', $output);
    }

    public function test_maps_json_column_type(): void
    {
        $table = $this->makeTable([
            new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true),
            new ColumnDefinition(name: 'settings', columnType: 'json'),
        ]);

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Data', 'User');

        $this->assertStringContainsString('public readonly array $settings', $output);
    }

    public function test_maps_timestamp_column_type(): void
    {
        $table = $this->makeTable([
            new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true),
            new ColumnDefinition(name: 'published_at', columnType: 'timestamp', nullable: true),
        ]);

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Data', 'Post');

        $this->assertStringContainsString('public readonly ?string $publishedAt', $output);
    }

    public function test_includes_timestamp_properties_when_has_timestamps(): void
    {
        $table = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true),
                new ColumnDefinition(name: 'title', columnType: 'string'),
                new ColumnDefinition(name: 'created_at', columnType: 'timestamp', nullable: true),
                new ColumnDefinition(name: 'updated_at', columnType: 'timestamp', nullable: true),
            ],
            hasTimestamps: true,
        );

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Data', 'Post');

        $this->assertStringContainsString('public readonly ?string $createdAt', $output);
        $this->assertStringContainsString('public readonly ?string $updatedAt', $output);
    }

    public function test_does_not_duplicate_timestamp_columns(): void
    {
        $table = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true),
                new ColumnDefinition(name: 'title', columnType: 'string'),
                new ColumnDefinition(name: 'created_at', columnType: 'timestamp', nullable: true),
                new ColumnDefinition(name: 'updated_at', columnType: 'timestamp', nullable: true),
            ],
            hasTimestamps: true,
        );

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Data', 'Post');

        // Should only appear once (in the managed section, not in regular columns)
        $this->assertSame(1, substr_count($output, '$createdAt'));
        $this->assertSame(1, substr_count($output, '$updatedAt'));
    }

    public function test_includes_soft_delete_property(): void
    {
        $table = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true),
                new ColumnDefinition(name: 'title', columnType: 'string'),
                new ColumnDefinition(name: 'deleted_at', columnType: 'timestamp', nullable: true),
            ],
            hasSoftDeletes: true,
        );

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Data', 'Post');

        $this->assertStringContainsString('public readonly ?string $deletedAt', $output);
    }

    public function test_excludes_hidden_columns(): void
    {
        $table = new TableDefinition(
            tableName: 'users',
            schemaClass: 'App\\Schemas\\UserSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true),
                new ColumnDefinition(name: 'name', columnType: 'string'),
                new ColumnDefinition(name: 'password', columnType: 'string'),
            ],
            hidden: ['password'],
        );

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Data', 'User');

        $this->assertStringContainsString('$name', $output);
        $this->assertStringNotContainsString('$password', $output);
    }

    public function test_excludes_hidden_timestamps(): void
    {
        $table = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true),
                new ColumnDefinition(name: 'title', columnType: 'string'),
                new ColumnDefinition(name: 'created_at', columnType: 'timestamp', nullable: true),
                new ColumnDefinition(name: 'updated_at', columnType: 'timestamp', nullable: true),
            ],
            hasTimestamps: true,
            hidden: ['updated_at'],
        );

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Data', 'Post');

        $this->assertStringContainsString('$createdAt', $output);
        $this->assertStringNotContainsString('$updatedAt', $output);
    }

    public function test_includes_has_many_relationship_as_nullable_array(): void
    {
        $table = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true),
                new ColumnDefinition(name: 'title', columnType: 'string'),
            ],
            relationships: [
                new RelationshipDefinition(name: 'comments', type: 'hasMany', relatedModel: 'App\\Models\\Comment'),
            ],
        );

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Data', 'Post');

        $this->assertStringContainsString('CommentData[]|null', $output);
        $this->assertStringContainsString('public readonly ?array $comments', $output);
    }

    public function test_includes_belongs_to_many_relationship_as_nullable_array(): void
    {
        $table = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true),
            ],
            relationships: [
                new RelationshipDefinition(name: 'tags', type: 'belongsToMany', relatedModel: 'App\\Models\\Tag'),
            ],
        );

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Data', 'Post');

        $this->assertStringContainsString('TagData[]|null', $output);
        $this->assertStringContainsString('public readonly ?array $tags', $output);
    }

    public function test_includes_has_one_relationship_as_nullable_singular(): void
    {
        $table = new TableDefinition(
            tableName: 'users',
            schemaClass: 'App\\Schemas\\UserSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true),
            ],
            relationships: [
                new RelationshipDefinition(name: 'profile', type: 'hasOne', relatedModel: 'App\\Models\\Profile'),
            ],
        );

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Data', 'User');

        $this->assertStringContainsString('public readonly ?ProfileData $profile', $output);
    }

    public function test_excludes_belongs_to_relationships(): void
    {
        $table = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true),
                new ColumnDefinition(name: 'author_id', columnType: 'unsignedBigInteger'),
            ],
            relationships: [
                new RelationshipDefinition(name: 'author', type: 'belongsTo', relatedModel: 'App\\Models\\User'),
            ],
        );

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Data', 'Post');

        // FK column is included as a regular column
        $this->assertStringContainsString('$authorId', $output);
        // But no UserData relationship property
        $this->assertStringNotContainsString('UserData', $output);
    }

    public function test_excludes_hidden_relationships(): void
    {
        $table = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true),
            ],
            relationships: [
                new RelationshipDefinition(name: 'comments', type: 'hasMany', relatedModel: 'App\\Models\\Comment'),
                new RelationshipDefinition(name: 'secrets', type: 'hasMany', relatedModel: 'App\\Models\\Secret'),
            ],
            hidden: ['secrets'],
        );

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Data', 'Post');

        $this->assertStringContainsString('$comments', $output);
        $this->assertStringNotContainsString('$secrets', $output);
    }

    public function test_generates_from_array_method(): void
    {
        $table = $this->makeTable([
            new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true),
            new ColumnDefinition(name: 'title', columnType: 'string'),
            new ColumnDefinition(name: 'body', columnType: 'text', nullable: true),
        ]);

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Data', 'Post');

        $this->assertStringContainsString('public static function fromArray(array $data): self', $output);
        $this->assertStringContainsString("title: \$data['title']", $output);
        $this->assertStringContainsString("body: \$data['body'] ?? null", $output);
    }

    public function test_from_array_maps_relationships_with_from_array(): void
    {
        $table = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true),
            ],
            relationships: [
                new RelationshipDefinition(name: 'comments', type: 'hasMany', relatedModel: 'App\\Models\\Comment'),
                new RelationshipDefinition(name: 'profile', type: 'hasOne', relatedModel: 'App\\Models\\Profile'),
            ],
        );

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Data', 'Post');

        // HasMany maps with array_map
        $this->assertStringContainsString('CommentData::fromArray($item)', $output);
        // HasOne maps directly
        $this->assertStringContainsString("ProfileData::fromArray(\$data['profile'])", $output);
    }

    public function test_camel_cases_snake_case_column_names(): void
    {
        $table = $this->makeTable([
            new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true),
            new ColumnDefinition(name: 'first_name', columnType: 'string'),
            new ColumnDefinition(name: 'last_login_at', columnType: 'timestamp', nullable: true),
        ]);

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Data', 'User');

        $this->assertStringContainsString('$firstName', $output);
        $this->assertStringContainsString('$lastLoginAt', $output);
    }

    public function test_from_array_uses_original_snake_case_keys(): void
    {
        $table = $this->makeTable([
            new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true),
            new ColumnDefinition(name: 'first_name', columnType: 'string'),
        ]);

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Data', 'User');

        // Property is camelCase, but data key is original snake_case
        $this->assertStringContainsString("firstName: \$data['first_name']", $output);
    }

    /**
     * @param  ColumnDefinition[]  $columns
     */
    private function makeTable(array $columns, array $relationships = []): TableDefinition
    {
        return new TableDefinition(
            tableName: 'test_table',
            schemaClass: 'App\\Schemas\\TestSchema',
            columns: $columns,
            relationships: $relationships,
        );
    }
}
