<?php

namespace SchemaCraft\Tests\Unit\Generators;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Generators\GeneratorColumn;
use SchemaCraft\Generators\GeneratorRelationship;
use SchemaCraft\Generators\GeneratorSchemaContext;
use SchemaCraft\Generators\NameChain;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Scanner\RelationshipDefinition;
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
            relationships: [
                new RelationshipDefinition(name: 'user', type: 'belongsTo', relatedModel: 'App\\Models\\User'),
                new RelationshipDefinition(name: 'posts', type: 'hasMany', relatedModel: 'App\\Models\\Post'),
                new RelationshipDefinition(name: 'tags', type: 'belongsToMany', relatedModel: 'App\\Models\\Tag'),
            ],
        );
    }

    // ─── Model NameChain ──────────────────────────────────────────

    public function test_model_is_name_chain(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable());

        $this->assertInstanceOf(NameChain::class, $ctx->model);
    }

    public function test_model_title_is_studly_singular(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable());

        $this->assertSame('UserProfile', (string) $ctx->model->title);
    }

    public function test_model_camel_is_camel_singular(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable());

        $this->assertSame('userProfile', (string) $ctx->model->camel);
    }

    public function test_model_snake_is_snake_singular(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable());

        $this->assertSame('user_profile', (string) $ctx->model->snake);
    }

    public function test_model_plural_snake(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable());

        $this->assertSame('user_profiles', (string) $ctx->model->plural->snake);
    }

    public function test_model_plural_title(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable());

        $this->assertSame('UserProfiles', (string) $ctx->model->plural->title);
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

    // ─── Relationship selection ───────────────────────────────────

    public function test_all_relationships_contains_all(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable());

        $this->assertCount(3, $ctx->allRelationships);
        $this->assertContainsOnlyInstancesOf(GeneratorRelationship::class, $ctx->allRelationships);
    }

    public function test_empty_relationship_selection_means_all(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable());

        $this->assertCount(3, $ctx->relationships);
        $this->assertSame($ctx->allRelationships, $ctx->relationships);
    }

    public function test_selected_relationships_filters_correctly(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), [], ['posts', 'tags']);

        $this->assertCount(2, $ctx->relationships);
        $this->assertSame('post', (string) $ctx->relationships[0]->name);
        $this->assertSame('tag', (string) $ctx->relationships[1]->name);
    }

    public function test_all_relationships_still_contains_all_when_filtered(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), [], ['posts']);

        $this->assertCount(3, $ctx->allRelationships);
        $this->assertCount(1, $ctx->relationships);
    }

    public function test_relationships_have_name_chains(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable());

        $this->assertSame('User', (string) $ctx->relationships[0]->name->title);
        $this->assertSame('Posts', (string) $ctx->relationships[1]->name->plural->title);
    }

    public function test_relationships_report_cardinality(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable());

        $this->assertTrue($ctx->relationships[0]->isSingular());  // belongsTo
        $this->assertTrue($ctx->relationships[1]->isCollection()); // hasMany
        $this->assertTrue($ctx->relationships[2]->isCollection()); // belongsToMany
    }
}
