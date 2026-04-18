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
            connection: 'testing',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
                new ColumnDefinition(name: 'user_id', columnType: 'unsignedBigInteger'),
                new ColumnDefinition(name: 'first_name', columnType: 'string'),
                new ColumnDefinition(name: 'email', columnType: 'string'),
                new ColumnDefinition(name: 'age', columnType: 'integer', nullable: true),
                new ColumnDefinition(name: 'created_at', columnType: 'timestamp', nullable: true),
                new ColumnDefinition(name: 'updated_at', columnType: 'timestamp', nullable: true),
                new ColumnDefinition(name: 'deleted_at', columnType: 'timestamp', nullable: true),
            ],
            relationships: [
                new RelationshipDefinition(name: 'user', type: 'belongsTo', relatedModel: 'App\\Models\\User'),
                new RelationshipDefinition(name: 'posts', type: 'hasMany', relatedModel: 'App\\Models\\Post'),
                new RelationshipDefinition(name: 'tags', type: 'belongsToMany', relatedModel: 'App\\Models\\Tag'),
            ],
            hasTimestamps: true,
            hasSoftDeletes: true,
            fillable: ['first_name', 'email', 'age'],
            hidden: ['email'],
            with: ['user'],
            titleColumns: ['first_name'],
        );
    }

    private function makeTableWithoutMetadata(): TableDefinition
    {
        return new TableDefinition(
            tableName: 'notes',
            schemaClass: 'App\\Schemas\\NoteSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true),
                new ColumnDefinition(name: 'body', columnType: 'text'),
            ],
        );
    }

    // ─── Model NameChain ──────────────────────────────────────────

    public function test_model_is_name_chain(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, null);

        $this->assertInstanceOf(NameChain::class, $ctx->model);
    }

    public function test_model_title_is_studly_singular(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, null);

        $this->assertSame('UserProfile', (string) $ctx->model->title);
    }

    public function test_model_camel_is_camel_singular(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, null);

        $this->assertSame('userProfile', (string) $ctx->model->camel);
    }

    public function test_model_snake_is_snake_singular(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, null);

        $this->assertSame('user_profile', (string) $ctx->model->snake);
    }

    public function test_model_plural_snake(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, null);

        $this->assertSame('user_profiles', (string) $ctx->model->plural->snake);
    }

    public function test_model_plural_title(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, null);

        $this->assertSame('UserProfiles', (string) $ctx->model->plural->title);
    }

    public function test_table_name_is_raw(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, null);

        $this->assertSame('user_profiles', $ctx->tableName);
    }

    // ─── Column selection ─────────────────────────────────────────

    public function test_all_columns_contains_all_columns(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, null);

        $this->assertCount(8, $ctx->allColumns);
        $this->assertContainsOnlyInstancesOf(GeneratorColumn::class, $ctx->allColumns);
    }

    public function test_empty_selection_means_columns_equals_all_columns(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, null);

        $this->assertCount(8, $ctx->columns);
        $this->assertSame($ctx->allColumns, $ctx->columns);
    }

    public function test_selected_columns_filters_correctly(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), ['first_name', 'email'], null);

        $this->assertCount(2, $ctx->columns);
        $this->assertSame('first_name', $ctx->columns[0]->name);
        $this->assertSame('email', $ctx->columns[1]->name);
    }

    public function test_all_columns_still_contains_all_when_filtered(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), ['first_name'], null);

        $this->assertCount(8, $ctx->allColumns);
        $this->assertCount(1, $ctx->columns);
    }

    public function test_unknown_column_names_are_silently_excluded(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), ['first_name', 'nonexistent'], null);

        $this->assertCount(1, $ctx->columns);
        $this->assertSame('first_name', $ctx->columns[0]->name);
    }

    public function test_null_column_selection_means_all(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, null);

        $this->assertCount(8, $ctx->columns);
        $this->assertSame($ctx->allColumns, $ctx->columns);
    }

    public function test_empty_array_column_selection_means_none(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), [], null);

        $this->assertCount(0, $ctx->columns);
    }

    // ─── Relationship selection ───────────────────────────────────

    public function test_all_relationships_contains_all(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, null);

        $this->assertCount(3, $ctx->allRelationships);
        $this->assertContainsOnlyInstancesOf(GeneratorRelationship::class, $ctx->allRelationships);
    }

    public function test_empty_relationship_selection_means_all(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, null);

        $this->assertCount(3, $ctx->relationships);
        $this->assertSame($ctx->allRelationships, $ctx->relationships);
    }

    public function test_null_relationship_selection_means_all(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, null);

        $this->assertCount(3, $ctx->relationships);
        $this->assertSame($ctx->allRelationships, $ctx->relationships);
    }

    public function test_empty_array_relationship_selection_means_none(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, []);

        $this->assertCount(0, $ctx->relationships);
    }

    public function test_selected_relationships_filters_correctly(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, ['posts', 'tags']);

        $this->assertCount(2, $ctx->relationships);
        $this->assertSame('post', (string) $ctx->relationships[0]->name);
        $this->assertSame('tag', (string) $ctx->relationships[1]->name);
    }

    public function test_all_relationships_still_contains_all_when_filtered(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, ['posts']);

        $this->assertCount(3, $ctx->allRelationships);
        $this->assertCount(1, $ctx->relationships);
    }

    public function test_relationships_have_name_chains(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, null);

        $this->assertSame('User', (string) $ctx->relationships[0]->name->title);
        $this->assertSame('Posts', (string) $ctx->relationships[1]->name->plural->title);
    }

    public function test_relationships_report_cardinality(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, null);

        $this->assertTrue($ctx->relationships[0]->isSingular());  // belongsTo
        $this->assertTrue($ctx->relationships[1]->isCollection()); // hasMany
        $this->assertTrue($ctx->relationships[2]->isCollection()); // belongsToMany
    }

    // ─── Schema metadata ──────────────────────────────────────────

    public function test_schema_class_is_exposed(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, null);

        $this->assertSame('App\\Schemas\\UserProfileSchema', $ctx->schemaClass);
    }

    public function test_model_class_uses_convention_when_schema_class_not_loadable(): void
    {
        // The fake FQCN isn't autoloadable — resolver must fall back to the
        // `Schemas → Models`, strip-`Schema`-suffix convention.
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, null);

        $this->assertSame('App\\Models\\UserProfile', $ctx->modelClass);
    }

    public function test_model_class_handles_nested_namespace_convention(): void
    {
        $table = new TableDefinition(
            tableName: 'strikes',
            schemaClass: 'App\\DroneStrike\\Schemas\\DroneStrikeSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true),
            ],
        );

        $ctx = new GeneratorSchemaContext($table, null, null);

        $this->assertSame('App\\DroneStrike\\Models\\DroneStrike', $ctx->modelClass);
    }

    public function test_model_class_reads_explicit_model_property_via_reflection(): void
    {
        // ModelOverrideSchema declares `public static string $model`.
        // Reflection path must win over the convention fallback.
        $table = new TableDefinition(
            tableName: 'overridden',
            schemaClass: \SchemaCraft\Tests\Fixtures\Schemas\ModelOverrideSchema::class,
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true),
            ],
        );

        $ctx = new GeneratorSchemaContext($table, null, null);

        $this->assertSame('Custom\\Namespace\\OverriddenModel', $ctx->modelClass);
    }

    public function test_connection_is_exposed(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, null);

        $this->assertSame('testing', $ctx->connection);
    }

    public function test_connection_is_null_when_default(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTableWithoutMetadata(), null, null);

        $this->assertNull($ctx->connection);
    }

    public function test_fillable_is_exposed(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, null);

        $this->assertSame(['first_name', 'email', 'age'], $ctx->fillable);
    }

    public function test_hidden_is_exposed(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, null);

        $this->assertSame(['email'], $ctx->hidden);
    }

    public function test_default_with_is_exposed(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, null);

        $this->assertSame(['user'], $ctx->defaultWith);
    }

    public function test_title_columns_is_exposed(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, null);

        $this->assertSame(['first_name'], $ctx->titleColumns);
    }

    // ─── Flags ────────────────────────────────────────────────────

    public function test_has_timestamps_reflects_table_definition(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, null);

        $this->assertTrue($ctx->hasTimestamps);
    }

    public function test_has_timestamps_is_false_when_not_set(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTableWithoutMetadata(), null, null);

        $this->assertFalse($ctx->hasTimestamps);
    }

    public function test_has_soft_deletes_true_via_flag(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, null);

        $this->assertTrue($ctx->hasSoftDeletes);
    }

    public function test_has_soft_deletes_true_via_deleted_at_column(): void
    {
        $table = new TableDefinition(
            tableName: 'logs',
            schemaClass: 'App\\Schemas\\LogSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true),
                new ColumnDefinition(name: 'deleted_at', columnType: 'timestamp', nullable: true),
            ],
            // hasSoftDeletes flag is NOT set — should still detect via column
        );

        $ctx = new GeneratorSchemaContext($table, null, null);

        $this->assertTrue($ctx->hasSoftDeletes);
    }

    public function test_has_soft_deletes_is_false_when_no_trait_or_column(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTableWithoutMetadata(), null, null);

        $this->assertFalse($ctx->hasSoftDeletes);
    }

    // ─── Column lookups ───────────────────────────────────────────

    public function test_primary_key_picks_first_primary_column(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, null);

        $this->assertNotNull($ctx->primaryKey);
        $this->assertSame('id', $ctx->primaryKey->name);
        $this->assertTrue($ctx->primaryKey->isPrimary());
    }

    public function test_primary_key_is_null_when_no_primary_column(): void
    {
        $table = new TableDefinition(
            tableName: 'no_pk_table',
            schemaClass: 'App\\Schemas\\NoPkSchema',
            columns: [
                new ColumnDefinition(name: 'data', columnType: 'string'),
            ],
        );

        $ctx = new GeneratorSchemaContext($table, null, null);

        $this->assertNull($ctx->primaryKey);
    }

    public function test_title_column_uses_title_columns_first(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, null);

        $this->assertNotNull($ctx->titleColumn);
        $this->assertSame('first_name', $ctx->titleColumn->name);
    }

    public function test_title_column_falls_back_to_primary_key(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTableWithoutMetadata(), null, null);

        $this->assertNotNull($ctx->titleColumn);
        $this->assertSame('id', $ctx->titleColumn->name);
    }

    public function test_column_finds_by_name(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, null);

        $col = $ctx->column('email');
        $this->assertNotNull($col);
        $this->assertSame('email', $col->name);
    }

    public function test_column_returns_null_when_missing(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, null);

        $this->assertNull($ctx->column('does_not_exist'));
    }

    public function test_has_column_checks_existence(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, null);

        $this->assertTrue($ctx->hasColumn('email'));
        $this->assertFalse($ctx->hasColumn('missing'));
    }

    public function test_relationship_finds_by_name(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, null);

        $rel = $ctx->relationship('posts');
        $this->assertNotNull($rel);
        $this->assertSame('posts', $rel->definition->name);
    }

    public function test_relationship_returns_null_when_missing(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, null);

        $this->assertNull($ctx->relationship('nonexistent'));
    }

    // ─── Filtered column sets ─────────────────────────────────────

    public function test_foreign_key_columns_returns_only_fk_columns(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, null);

        $fks = $ctx->foreignKeyColumns();

        $this->assertCount(1, $fks);
        $this->assertSame('user_id', $fks[0]->name);
    }

    public function test_searchable_columns_excludes_fks_pks_timestamps_soft_delete(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, null);

        $searchable = $ctx->searchableColumns();
        $names = array_map(fn ($c) => $c->name, $searchable);

        // Only 'first_name' and 'email' (string types, not FK/PK/timestamps/soft-delete)
        $this->assertSame(['first_name', 'email'], $names);
    }

    public function test_searchable_columns_returns_empty_when_no_string_columns(): void
    {
        $table = new TableDefinition(
            tableName: 'numbers',
            schemaClass: 'App\\Schemas\\NumberSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true),
                new ColumnDefinition(name: 'value', columnType: 'integer'),
            ],
        );

        $ctx = new GeneratorSchemaContext($table, null, null);

        $this->assertSame([], $ctx->searchableColumns());
    }

    public function test_fillable_columns_intersects_all_columns_with_fillable(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTable(), null, null);

        $fillable = $ctx->fillableColumns();
        $names = array_map(fn ($c) => $c->name, $fillable);

        $this->assertSame(['first_name', 'email', 'age'], $names);
    }

    public function test_fillable_columns_returns_empty_when_fillable_unset(): void
    {
        $ctx = new GeneratorSchemaContext($this->makeTableWithoutMetadata(), null, null);

        $this->assertSame([], $ctx->fillableColumns());
    }
}
