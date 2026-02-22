<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Generator\Sdk\SdkGenerator;
use SchemaCraft\Generator\Sdk\SdkSchemaContext;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Scanner\RelationshipDefinition;
use SchemaCraft\Scanner\TableDefinition;

class SdkGeneratorTest extends TestCase
{
    private SdkGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new SdkGenerator;
    }

    public function test_generates_composer_json(): void
    {
        $schemas = $this->makeSchemas();

        $files = $this->generator->generate($schemas, 'acme/my-sdk', 'Acme\\Sdk', 'AcmeClient');

        $this->assertArrayHasKey('composer.json', $files);
        $this->assertSame('composer.json', $files['composer.json']->path);

        $content = $files['composer.json']->content;
        $this->assertStringContainsString('"acme/my-sdk"', $content);
        $this->assertStringContainsString('"Acme\\\\Sdk\\\\": "src/"', $content);
        $this->assertStringContainsString('guzzlehttp/guzzle', $content);
    }

    public function test_generates_sdk_connector(): void
    {
        $schemas = $this->makeSchemas();

        $files = $this->generator->generate($schemas, 'acme/my-sdk', 'Acme\\Sdk', 'AcmeClient');

        $this->assertArrayHasKey('connector', $files);
        $this->assertSame('src/SdkConnector.php', $files['connector']->path);
        $this->assertStringContainsString('namespace Acme\\Sdk;', $files['connector']->content);
        $this->assertStringContainsString('class SdkConnector', $files['connector']->content);
    }

    public function test_generates_data_dto_for_each_schema(): void
    {
        $schemas = $this->makeSchemas();

        $files = $this->generator->generate($schemas, 'acme/my-sdk', 'Acme\\Sdk', 'AcmeClient');

        $this->assertArrayHasKey('data_Post', $files);
        $this->assertSame('src/Data/PostData.php', $files['data_Post']->path);
        $this->assertStringContainsString('class PostData', $files['data_Post']->content);
        $this->assertStringContainsString('namespace Acme\\Sdk\\Data;', $files['data_Post']->content);

        $this->assertArrayHasKey('data_Comment', $files);
        $this->assertSame('src/Data/CommentData.php', $files['data_Comment']->path);
        $this->assertStringContainsString('class CommentData', $files['data_Comment']->content);
    }

    public function test_generates_resource_for_each_schema(): void
    {
        $schemas = $this->makeSchemas();

        $files = $this->generator->generate($schemas, 'acme/my-sdk', 'Acme\\Sdk', 'AcmeClient');

        $this->assertArrayHasKey('resource_Post', $files);
        $this->assertSame('src/Resources/PostResource.php', $files['resource_Post']->path);
        $this->assertStringContainsString('class PostResource', $files['resource_Post']->content);
        $this->assertStringContainsString('namespace Acme\\Sdk\\Resources;', $files['resource_Post']->content);

        $this->assertArrayHasKey('resource_Comment', $files);
        $this->assertSame('src/Resources/CommentResource.php', $files['resource_Comment']->path);
    }

    public function test_generates_client_class(): void
    {
        $schemas = $this->makeSchemas();

        $files = $this->generator->generate($schemas, 'acme/my-sdk', 'Acme\\Sdk', 'AcmeClient');

        $this->assertArrayHasKey('client', $files);
        $this->assertSame('src/AcmeClient.php', $files['client']->path);

        $content = $files['client']->content;
        $this->assertStringContainsString('class AcmeClient', $content);
        $this->assertStringContainsString('namespace Acme\\Sdk;', $content);
        $this->assertStringContainsString('public function posts(): PostResource', $content);
        $this->assertStringContainsString('public function comments(): CommentResource', $content);
    }

    public function test_client_imports_all_resources(): void
    {
        $schemas = $this->makeSchemas();

        $files = $this->generator->generate($schemas, 'acme/my-sdk', 'Acme\\Sdk', 'AcmeClient');

        $content = $files['client']->content;
        $this->assertStringContainsString('use Acme\\Sdk\\Resources\\PostResource;', $content);
        $this->assertStringContainsString('use Acme\\Sdk\\Resources\\CommentResource;', $content);
    }

    public function test_resource_includes_custom_actions(): void
    {
        $schemas = [
            'Post' => new SdkSchemaContext(
                table: new TableDefinition(
                    tableName: 'posts',
                    schemaClass: 'App\\Schemas\\PostSchema',
                    columns: [
                        new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
                        new ColumnDefinition(name: 'title', columnType: 'string'),
                    ],
                ),
                customActions: ['cancel', 'publish'],
            ),
        ];

        $files = $this->generator->generate($schemas, 'acme/my-sdk', 'Acme\\Sdk', 'AcmeClient');

        $content = $files['resource_Post']->content;
        $this->assertStringContainsString('public function cancel(', $content);
        $this->assertStringContainsString('public function publish(', $content);
    }

    public function test_generated_file_count(): void
    {
        $schemas = $this->makeSchemas();

        $files = $this->generator->generate($schemas, 'acme/my-sdk', 'Acme\\Sdk', 'AcmeClient');

        // composer.json + connector + (data + resource) * 2 schemas + client
        // = 1 + 1 + 4 + 1 = 7
        $this->assertCount(7, $files);
    }

    public function test_single_schema_generates_minimal_package(): void
    {
        $schemas = [
            'User' => new SdkSchemaContext(
                table: new TableDefinition(
                    tableName: 'users',
                    schemaClass: 'App\\Schemas\\UserSchema',
                    columns: [
                        new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
                        new ColumnDefinition(name: 'name', columnType: 'string'),
                        new ColumnDefinition(name: 'email', columnType: 'string'),
                    ],
                ),
            ),
        ];

        $files = $this->generator->generate($schemas, 'acme/my-sdk', 'Acme\\Sdk', 'AcmeClient');

        // composer.json + connector + data_User + resource_User + client = 5
        $this->assertCount(5, $files);
        $this->assertArrayHasKey('data_User', $files);
        $this->assertArrayHasKey('resource_User', $files);
    }

    public function test_default_parameter_values(): void
    {
        $schemas = $this->makeSchemas();

        $files = $this->generator->generate($schemas);

        // Should work with all defaults
        $this->assertArrayHasKey('composer.json', $files);
        $content = $files['composer.json']->content;
        $this->assertStringContainsString('"my-app/sdk"', $content);

        $this->assertStringContainsString('class MyAppClient', $files['client']->content);
    }

    public function test_resource_uses_data_namespace(): void
    {
        $schemas = $this->makeSchemas();

        $files = $this->generator->generate($schemas, 'acme/my-sdk', 'Acme\\Sdk', 'AcmeClient');

        $content = $files['resource_Post']->content;
        $this->assertStringContainsString('use Acme\\Sdk\\Data\\PostData;', $content);
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
                        new ColumnDefinition(name: 'body', columnType: 'text', nullable: true),
                    ],
                    relationships: [
                        new RelationshipDefinition(name: 'comments', type: 'hasMany', relatedModel: 'App\\Models\\Comment'),
                    ],
                    hasTimestamps: true,
                ),
            ),
            'Comment' => new SdkSchemaContext(
                table: new TableDefinition(
                    tableName: 'comments',
                    schemaClass: 'App\\Schemas\\CommentSchema',
                    columns: [
                        new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
                        new ColumnDefinition(name: 'body', columnType: 'text'),
                        new ColumnDefinition(name: 'post_id', columnType: 'unsignedBigInteger'),
                    ],
                ),
            ),
        ];
    }
}
