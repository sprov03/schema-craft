<?php

namespace SchemaCraft\Tests\Unit\Generators;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Generators\GeneratorColumn;
use SchemaCraft\Generators\GeneratorSchemaContext;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Scanner\TableDefinition;

class GeneratorSchemaContextTest extends TestCase
{
    private function makeTable(): TableDefinition
    {
        return new TableDefinition(
            tableName: 'user_profiles',
            schemaClass: 'App\\Schemas\\UserProfileSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true),
                new ColumnDefinition(name: 'first_name', columnType: 'string'),
                new ColumnDefinition(name: 'email', columnType: 'string'),
                new ColumnDefinition(name: 'age', columnType: 'integer', nullable: true),
            ],
        );
    }

    // ─── Name helpers ─────────────────────────────────────────────

    public function test_model_name_is_studly_singular(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable());

        $this->assertSame('UserProfile', $ctx->ModelName);
    }

    public function test_model_name_camel_is_camel_singular(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable());

        $this->assertSame('userProfile', $ctx->modelName);
    }

    public function test_model_name_snake_is_snake_singular(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable());

        $this->assertSame('user_profile', $ctx->model_name);
    }

    public function test_model_names_snake_plural(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable());

        $this->assertSame('user_profiles', $ctx->model_names);
    }

    public function test_table_name_is_raw(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable());

        $this->assertSame('user_profiles', $ctx->tableName);
    }

    // ─── Column selection ─────────────────────────────────────────

    public function test_all_columns_contains_all_columns(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable());

        $this->assertCount(4, $ctx->allColumns);
        $this->assertContainsOnlyInstancesOf(GeneratorColumn::class, $ctx->allColumns);
    }

    public function test_empty_selection_means_columns_equals_all_columns(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable());

        $this->assertCount(4, $ctx->columns);
        $this->assertSame($ctx->allColumns, $ctx->columns);
    }

    public function test_selected_columns_filters_correctly(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), ['first_name', 'email']);

        $this->assertCount(2, $ctx->columns);
        $this->assertSame('first_name', $ctx->columns[0]->name);
        $this->assertSame('email', $ctx->columns[1]->name);
    }

    public function test_all_columns_still_contains_all_when_filtered(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), ['first_name']);

        $this->assertCount(4, $ctx->allColumns);
        $this->assertCount(1, $ctx->columns);
    }

    public function test_unknown_column_names_are_silently_excluded(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), ['first_name', 'nonexistent']);

        $this->assertCount(1, $ctx->columns);
        $this->assertSame('first_name', $ctx->columns[0]->name);
    }
}
