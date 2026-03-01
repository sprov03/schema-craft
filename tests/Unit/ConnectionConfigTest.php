<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Config\ConnectionConfig;

class ConnectionConfigTest extends TestCase
{
    // ─── fromArray with defaults ────────────────────────────────

    public function test_from_array_with_empty_config_uses_defaults(): void
    {
        $config = ConnectionConfig::fromArray('default', []);

        $this->assertSame('default', $config->name);
        $this->assertSame('default', $config->connection);
        $this->assertSame('App\\Schemas', $config->schemaNamespace);
        $this->assertSame('App\\Models', $config->modelNamespace);
        $this->assertSame('App\\Models\\Services', $config->serviceNamespace);
        $this->assertSame('', $config->schemaPrefix);
        $this->assertSame('', $config->modelPrefix);
        $this->assertSame('', $config->servicePrefix);
    }

    public function test_from_array_with_full_config(): void
    {
        $config = ConnectionConfig::fromArray('prefix-example', [
            'prefixes' => [
                'service' => 'Prefix',
                'schema' => 'Prefix',
                'model' => 'Prefix',
            ],
            'namespaces' => [
                'service' => 'App\\Models\\Services',
                'schema' => 'App\\Schemas',
                'model' => 'App\\Models',
            ],
            'connection' => 'prefix-example',
        ]);

        $this->assertSame('prefix-example', $config->name);
        $this->assertSame('prefix-example', $config->connection);
        $this->assertSame('App\\Schemas', $config->schemaNamespace);
        $this->assertSame('App\\Models', $config->modelNamespace);
        $this->assertSame('App\\Models\\Services', $config->serviceNamespace);
        $this->assertSame('Prefix', $config->schemaPrefix);
        $this->assertSame('Prefix', $config->modelPrefix);
        $this->assertSame('Prefix', $config->servicePrefix);
    }

    public function test_from_array_with_custom_namespaces(): void
    {
        $config = ConnectionConfig::fromArray('name-spaced', [
            'prefixes' => [
                'service' => '',
                'schema' => '',
                'model' => '',
            ],
            'namespaces' => [
                'service' => 'App\\Models\\Services\\Namespaced',
                'schema' => 'App\\Schemas\\Namespaced',
                'model' => 'App\\Models\\Namespaced',
            ],
            'connection' => 'name-spaced-example',
        ]);

        $this->assertSame('App\\Schemas\\Namespaced', $config->schemaNamespace);
        $this->assertSame('App\\Models\\Namespaced', $config->modelNamespace);
        $this->assertSame('App\\Models\\Services\\Namespaced', $config->serviceNamespace);
        $this->assertSame('', $config->schemaPrefix);
        $this->assertSame('name-spaced-example', $config->connection);
    }

    public function test_from_array_with_partial_config(): void
    {
        $config = ConnectionConfig::fromArray('partial', [
            'prefixes' => [
                'model' => 'Custom',
            ],
            'connection' => 'custom-db',
        ]);

        $this->assertSame('Custom', $config->modelPrefix);
        $this->assertSame('', $config->schemaPrefix);
        $this->assertSame('', $config->servicePrefix);
        $this->assertSame('custom-db', $config->connection);
        $this->assertSame('App\\Schemas', $config->schemaNamespace);
        $this->assertSame('App\\Models', $config->modelNamespace);
    }

    public function test_from_array_connection_defaults_to_name(): void
    {
        $config = ConnectionConfig::fromArray('my-db', []);

        $this->assertSame('my-db', $config->connection);
    }

    // ─── Prefix helpers ─────────────────────────────────────────

    public function test_prefixed_model_name_without_prefix(): void
    {
        $config = ConnectionConfig::fromArray('default', []);

        $this->assertSame('Account', $config->prefixedModelName('Account'));
    }

    public function test_prefixed_model_name_with_prefix(): void
    {
        $config = ConnectionConfig::fromArray('prefixed', [
            'prefixes' => ['model' => 'Prefix'],
        ]);

        $this->assertSame('PrefixAccount', $config->prefixedModelName('Account'));
    }

    public function test_prefixed_schema_name_without_prefix(): void
    {
        $config = ConnectionConfig::fromArray('default', []);

        $this->assertSame('AccountSchema', $config->prefixedSchemaName('Account'));
    }

    public function test_prefixed_schema_name_with_prefix(): void
    {
        $config = ConnectionConfig::fromArray('prefixed', [
            'prefixes' => ['schema' => 'Prefix'],
        ]);

        $this->assertSame('PrefixAccountSchema', $config->prefixedSchemaName('Account'));
    }

    // ─── Directory helpers ──────────────────────────────────────

    public function test_schema_directory_default(): void
    {
        $config = ConnectionConfig::fromArray('default', []);

        $this->assertSame('app/Schemas', $config->schemaDirectory());
    }

    public function test_schema_directory_custom_namespace(): void
    {
        $config = ConnectionConfig::fromArray('custom', [
            'namespaces' => ['schema' => 'App\\Schemas\\Namespaced'],
        ]);

        $this->assertSame('app/Schemas/Namespaced', $config->schemaDirectory());
    }

    public function test_model_directory_default(): void
    {
        $config = ConnectionConfig::fromArray('default', []);

        $this->assertSame('app/Models', $config->modelDirectory());
    }

    public function test_model_directory_custom_namespace(): void
    {
        $config = ConnectionConfig::fromArray('custom', [
            'namespaces' => ['model' => 'App\\Models\\Namespaced'],
        ]);

        $this->assertSame('app/Models/Namespaced', $config->modelDirectory());
    }
}
