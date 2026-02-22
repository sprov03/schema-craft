<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Generator\Sdk\SdkResourceGenerator;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Scanner\TableDefinition;

class SdkResourceGeneratorTest extends TestCase
{
    private SdkResourceGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new SdkResourceGenerator;
    }

    public function test_generates_class_with_correct_namespace_and_class_name(): void
    {
        $table = $this->makeSimpleTable();

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Resources', 'MyApp\\Sdk\\Data', 'Post');

        $this->assertStringContainsString('namespace MyApp\\Sdk\\Resources;', $output);
        $this->assertStringContainsString('class PostResource', $output);
    }

    public function test_imports_data_class(): void
    {
        $table = $this->makeSimpleTable();

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Resources', 'MyApp\\Sdk\\Data', 'Post');

        $this->assertStringContainsString('use MyApp\\Sdk\\Data\\PostData;', $output);
    }

    public function test_constructor_takes_sdk_connector(): void
    {
        $table = $this->makeSimpleTable();

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Resources', 'MyApp\\Sdk\\Data', 'Post');

        $this->assertStringContainsString('public function __construct(private SdkConnector $connector)', $output);
    }

    public function test_generates_list_method(): void
    {
        $table = $this->makeSimpleTable();

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Resources', 'MyApp\\Sdk\\Data', 'Post');

        $this->assertStringContainsString('public function list(): array', $output);
        $this->assertStringContainsString('PostData::fromArray($item)', $output);
        $this->assertStringContainsString("\$this->connector->get('posts')", $output);
    }

    public function test_generates_get_method(): void
    {
        $table = $this->makeSimpleTable();

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Resources', 'MyApp\\Sdk\\Data', 'Post');

        $this->assertStringContainsString('public function get(int|string $id): PostData', $output);
        $this->assertStringContainsString('PostData::fromArray($response[\'data\'])', $output);
    }

    public function test_generates_create_method_with_typed_params(): void
    {
        $table = $this->makeTable([
            new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
            new ColumnDefinition(name: 'title', columnType: 'string'),
            new ColumnDefinition(name: 'body', columnType: 'text', nullable: true),
            new ColumnDefinition(name: 'is_active', columnType: 'boolean'),
        ]);

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Resources', 'MyApp\\Sdk\\Data', 'Post');

        $this->assertStringContainsString('public function create(string $title, ?string $body = null, bool $isActive): PostData', $output);
        $this->assertStringContainsString("'title' => \$title", $output);
        $this->assertStringContainsString("'body' => \$body", $output);
        $this->assertStringContainsString("'is_active' => \$isActive", $output);
    }

    public function test_generates_update_method_with_same_params_as_create(): void
    {
        $table = $this->makeTable([
            new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
            new ColumnDefinition(name: 'title', columnType: 'string'),
            new ColumnDefinition(name: 'body', columnType: 'text', nullable: true),
        ]);

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Resources', 'MyApp\\Sdk\\Data', 'Post');

        // Update should have same param types as create (nullable matches column definition)
        $this->assertStringContainsString('public function update(int|string $id, string $title, ?string $body = null): PostData', $output);
    }

    public function test_non_nullable_params_are_required_in_both_create_and_update(): void
    {
        $table = $this->makeTable([
            new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
            new ColumnDefinition(name: 'name', columnType: 'string', nullable: false),
        ]);

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Resources', 'MyApp\\Sdk\\Data', 'User');

        // Both create and update have required 'name' param
        $this->assertStringContainsString('public function create(string $name): UserData', $output);
        $this->assertStringContainsString('public function update(int|string $id, string $name): UserData', $output);
    }

    public function test_generates_delete_method(): void
    {
        $table = $this->makeSimpleTable();

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Resources', 'MyApp\\Sdk\\Data', 'Post');

        $this->assertStringContainsString('public function delete(int|string $id): void', $output);
        $this->assertStringContainsString('$this->connector->delete("posts/{$id}")', $output);
    }

    public function test_excludes_primary_key_from_method_params(): void
    {
        $table = $this->makeTable([
            new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
            new ColumnDefinition(name: 'title', columnType: 'string'),
        ]);

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Resources', 'MyApp\\Sdk\\Data', 'Post');

        // create() should not have $id param (it's auto-increment)
        $this->assertStringNotContainsString('create(int $id', $output);
    }

    public function test_excludes_timestamps_from_method_params(): void
    {
        $table = new TableDefinition(
            tableName: 'posts',
            schemaClass: 'App\\Schemas\\PostSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
                new ColumnDefinition(name: 'title', columnType: 'string'),
                new ColumnDefinition(name: 'created_at', columnType: 'timestamp', nullable: true),
                new ColumnDefinition(name: 'updated_at', columnType: 'timestamp', nullable: true),
            ],
            hasTimestamps: true,
        );

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Resources', 'MyApp\\Sdk\\Data', 'Post');

        $this->assertStringNotContainsString('$createdAt', $output);
        $this->assertStringNotContainsString('$updatedAt', $output);
    }

    public function test_generates_custom_action_methods(): void
    {
        $table = $this->makeSimpleTable();

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Resources', 'MyApp\\Sdk\\Data', 'Post', ['cancel', 'publish']);

        $this->assertStringContainsString('public function cancel(int|string $id): void', $output);
        $this->assertStringContainsString('posts/{$id}/cancel', $output);

        $this->assertStringContainsString('public function publish(int|string $id): void', $output);
        $this->assertStringContainsString('posts/{$id}/publish', $output);
    }

    public function test_custom_action_slug_converts_to_kebab_case(): void
    {
        $table = $this->makeSimpleTable();

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Resources', 'MyApp\\Sdk\\Data', 'Post', ['markAsRead']);

        $this->assertStringContainsString('posts/{$id}/mark-as-read', $output);
    }

    public function test_uses_correct_route_prefix(): void
    {
        $table = $this->makeTable([
            new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
            new ColumnDefinition(name: 'name', columnType: 'string'),
        ]);

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Resources', 'MyApp\\Sdk\\Data', 'Category');

        $this->assertStringContainsString("'categories'", $output);
    }

    public function test_multi_word_model_name_route_prefix_is_kebab_case(): void
    {
        $table = $this->makeTable([
            new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
            new ColumnDefinition(name: 'name', columnType: 'string'),
        ]);

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Resources', 'MyApp\\Sdk\\Data', 'BlogPost');

        $this->assertStringContainsString("'blog-posts'", $output);
    }

    public function test_wraps_params_to_multi_line_when_more_than_three(): void
    {
        $table = $this->makeTable([
            new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
            new ColumnDefinition(name: 'first_name', columnType: 'string'),
            new ColumnDefinition(name: 'last_name', columnType: 'string'),
            new ColumnDefinition(name: 'email', columnType: 'string'),
            new ColumnDefinition(name: 'phone', columnType: 'string', nullable: true),
        ]);

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Resources', 'MyApp\\Sdk\\Data', 'User');

        // With 4 params, should be multiline
        $this->assertStringContainsString("string \$firstName,\n", $output);
    }

    public function test_maps_integer_param_types(): void
    {
        $table = $this->makeTable([
            new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
            new ColumnDefinition(name: 'user_id', columnType: 'unsignedBigInteger'),
            new ColumnDefinition(name: 'count', columnType: 'integer'),
        ]);

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Resources', 'MyApp\\Sdk\\Data', 'Post');

        $this->assertStringContainsString('int $userId', $output);
        $this->assertStringContainsString('int $count', $output);
    }

    public function test_maps_decimal_param_type(): void
    {
        $table = $this->makeTable([
            new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
            new ColumnDefinition(name: 'price', columnType: 'decimal'),
        ]);

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Resources', 'MyApp\\Sdk\\Data', 'Product');

        $this->assertStringContainsString('float $price', $output);
    }

    public function test_maps_boolean_param_type(): void
    {
        $table = $this->makeTable([
            new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
            new ColumnDefinition(name: 'is_active', columnType: 'boolean'),
        ]);

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Resources', 'MyApp\\Sdk\\Data', 'User');

        $this->assertStringContainsString('bool $isActive', $output);
    }

    public function test_data_array_uses_original_snake_case_keys(): void
    {
        $table = $this->makeTable([
            new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
            new ColumnDefinition(name: 'first_name', columnType: 'string'),
        ]);

        $output = $this->generator->generate($table, 'MyApp\\Sdk\\Resources', 'MyApp\\Sdk\\Data', 'User');

        $this->assertStringContainsString("'first_name' => \$firstName", $output);
    }

    private function makeSimpleTable(): TableDefinition
    {
        return $this->makeTable([
            new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
            new ColumnDefinition(name: 'title', columnType: 'string'),
        ]);
    }

    /**
     * @param  ColumnDefinition[]  $columns
     */
    private function makeTable(array $columns): TableDefinition
    {
        return new TableDefinition(
            tableName: 'test_table',
            schemaClass: 'App\\Schemas\\TestSchema',
            columns: $columns,
        );
    }
}
