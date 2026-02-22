<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Migration\CanonicalColumn;
use SchemaCraft\Migration\CanonicalForeignKey;
use SchemaCraft\Migration\CanonicalIndex;
use SchemaCraft\Migration\DatabaseColumnState;
use SchemaCraft\Migration\DatabaseForeignKeyState;
use SchemaCraft\Migration\DatabaseIndexState;
use SchemaCraft\Migration\DatabaseTableNormalizer;
use SchemaCraft\Migration\DatabaseTableState;

class DatabaseTableNormalizerTest extends TestCase
{
    private DatabaseTableNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new DatabaseTableNormalizer;
    }

    public function test_normalizes_basic_columns(): void
    {
        $table = new DatabaseTableState(
            tableName: 'tags',
            columns: [
                new DatabaseColumnState(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true, unsigned: true),
                new DatabaseColumnState(name: 'name', type: 'string'),
            ],
        );

        $canonical = $this->normalizer->normalize($table);

        $this->assertSame('tags', $canonical->tableName);
        $this->assertCount(2, $canonical->columns);

        $idCol = $canonical->getColumn('id');
        $this->assertInstanceOf(CanonicalColumn::class, $idCol);
        $this->assertSame('bigInteger', $idCol->type);
        $this->assertTrue($idCol->primary);
        $this->assertTrue($idCol->autoIncrement);
        $this->assertTrue($idCol->unsigned);

        $nameCol = $canonical->getColumn('name');
        $this->assertSame('string', $nameCol->type);
        $this->assertFalse($nameCol->nullable);
    }

    public function test_flattens_single_column_non_unique_index(): void
    {
        $table = new DatabaseTableState(
            tableName: 'posts',
            columns: [
                new DatabaseColumnState(name: 'user_id', type: 'unsignedBigInteger', unsigned: true),
            ],
            indexes: [
                new DatabaseIndexState(name: 'posts_user_id_index', columns: ['user_id'], unique: false),
            ],
        );

        $canonical = $this->normalizer->normalize($table);
        $col = $canonical->getColumn('user_id');

        $this->assertTrue($col->index);
        $this->assertFalse($col->unique);
    }

    public function test_flattens_single_column_unique_index(): void
    {
        $table = new DatabaseTableState(
            tableName: 'users',
            columns: [
                new DatabaseColumnState(name: 'email', type: 'string'),
            ],
            indexes: [
                new DatabaseIndexState(name: 'users_email_unique', columns: ['email'], unique: true),
            ],
        );

        $canonical = $this->normalizer->normalize($table);
        $col = $canonical->getColumn('email');

        $this->assertTrue($col->unique);
        $this->assertFalse($col->index);
    }

    public function test_no_index_flags_when_no_indexes(): void
    {
        $table = new DatabaseTableState(
            tableName: 'tags',
            columns: [
                new DatabaseColumnState(name: 'name', type: 'string'),
            ],
        );

        $canonical = $this->normalizer->normalize($table);
        $col = $canonical->getColumn('name');

        $this->assertFalse($col->unique);
        $this->assertFalse($col->index);
    }

    public function test_primary_index_not_flattened_onto_column(): void
    {
        $table = new DatabaseTableState(
            tableName: 'tags',
            columns: [
                new DatabaseColumnState(name: 'id', type: 'unsignedBigInteger', primary: true),
            ],
            indexes: [
                new DatabaseIndexState(name: 'PRIMARY', columns: ['id'], unique: true, primary: true),
            ],
        );

        $canonical = $this->normalizer->normalize($table);
        $col = $canonical->getColumn('id');

        // Primary index should not set unique/index flags
        $this->assertFalse($col->unique);
        $this->assertFalse($col->index);
        $this->assertTrue($col->primary);
    }

    public function test_multi_column_indexes_become_composite(): void
    {
        $table = new DatabaseTableState(
            tableName: 'posts',
            columns: [
                new DatabaseColumnState(name: 'user_id', type: 'unsignedBigInteger'),
                new DatabaseColumnState(name: 'category_id', type: 'unsignedBigInteger'),
            ],
            indexes: [
                new DatabaseIndexState(name: 'posts_user_category_index', columns: ['user_id', 'category_id']),
            ],
        );

        $canonical = $this->normalizer->normalize($table);

        // Multi-column indexes are NOT flattened onto columns
        $this->assertFalse($canonical->getColumn('user_id')->index);
        $this->assertFalse($canonical->getColumn('category_id')->index);

        // They become composite indexes
        $this->assertCount(1, $canonical->compositeIndexes);
        $this->assertInstanceOf(CanonicalIndex::class, $canonical->compositeIndexes[0]);
        $this->assertSame(['user_id', 'category_id'], $canonical->compositeIndexes[0]->columns);
        $this->assertFalse($canonical->compositeIndexes[0]->unique);
    }

    public function test_primary_index_excluded_from_composite(): void
    {
        $table = new DatabaseTableState(
            tableName: 'tags',
            columns: [
                new DatabaseColumnState(name: 'id', type: 'unsignedBigInteger', primary: true),
                new DatabaseColumnState(name: 'name', type: 'string'),
            ],
            indexes: [
                new DatabaseIndexState(name: 'PRIMARY', columns: ['id'], unique: true, primary: true),
                new DatabaseIndexState(name: 'tags_name_id_index', columns: ['name', 'id']),
            ],
        );

        $canonical = $this->normalizer->normalize($table);

        // Only the non-primary multi-column index
        $this->assertCount(1, $canonical->compositeIndexes);
        $this->assertSame(['name', 'id'], $canonical->compositeIndexes[0]->columns);
    }

    public function test_normalizes_foreign_keys(): void
    {
        $table = new DatabaseTableState(
            tableName: 'posts',
            columns: [
                new DatabaseColumnState(name: 'user_id', type: 'unsignedBigInteger'),
            ],
            foreignKeys: [
                new DatabaseForeignKeyState(
                    column: 'user_id',
                    foreignTable: 'users',
                    foreignColumn: 'id',
                    onDelete: 'cascade',
                    onUpdate: 'no action',
                ),
            ],
        );

        $canonical = $this->normalizer->normalize($table);

        $this->assertCount(1, $canonical->foreignKeys);
        $fk = $canonical->foreignKeys[0];
        $this->assertInstanceOf(CanonicalForeignKey::class, $fk);
        $this->assertSame('user_id', $fk->column);
        $this->assertSame('users', $fk->foreignTable);
        $this->assertSame('id', $fk->foreignColumn);
        $this->assertSame('cascade', $fk->onDelete);
        $this->assertSame('no action', $fk->onUpdate);
    }

    public function test_detects_timestamps(): void
    {
        $table = new DatabaseTableState(
            tableName: 'tags',
            columns: [
                new DatabaseColumnState(name: 'id', type: 'unsignedBigInteger', primary: true),
                new DatabaseColumnState(name: 'created_at', type: 'timestamp', nullable: true),
                new DatabaseColumnState(name: 'updated_at', type: 'timestamp', nullable: true),
            ],
        );

        $canonical = $this->normalizer->normalize($table);

        $this->assertTrue($canonical->hasTimestamps);
    }

    public function test_detects_soft_deletes(): void
    {
        $table = new DatabaseTableState(
            tableName: 'tags',
            columns: [
                new DatabaseColumnState(name: 'id', type: 'unsignedBigInteger', primary: true),
                new DatabaseColumnState(name: 'deleted_at', type: 'timestamp', nullable: true),
            ],
        );

        $canonical = $this->normalizer->normalize($table);

        $this->assertTrue($canonical->hasSoftDeletes);
    }

    public function test_preserves_column_properties(): void
    {
        $table = new DatabaseTableState(
            tableName: 'items',
            columns: [
                new DatabaseColumnState(
                    name: 'price',
                    type: 'decimal',
                    nullable: true,
                    default: 0.0,
                    hasDefault: true,
                    precision: 10,
                    scale: 2,
                ),
            ],
        );

        $canonical = $this->normalizer->normalize($table);
        $col = $canonical->getColumn('price');

        $this->assertSame('decimal', $col->type);
        $this->assertTrue($col->nullable);
        $this->assertTrue($col->hasDefault);
        $this->assertSame(0.0, $col->default);
        $this->assertSame(10, $col->precision);
        $this->assertSame(2, $col->scale);
    }

    public function test_decomposes_unsigned_integer_to_base_type(): void
    {
        $table = new DatabaseTableState(
            tableName: 'items',
            columns: [
                new DatabaseColumnState(name: 'count', type: 'unsignedInteger', unsigned: true),
            ],
        );

        $canonical = $this->normalizer->normalize($table);
        $col = $canonical->getColumn('count');

        $this->assertSame('integer', $col->type);
        $this->assertTrue($col->unsigned);
    }

    public function test_decomposes_unsigned_big_integer_to_base_type(): void
    {
        $table = new DatabaseTableState(
            tableName: 'items',
            columns: [
                new DatabaseColumnState(name: 'ref_id', type: 'unsignedBigInteger', unsigned: true),
            ],
        );

        $canonical = $this->normalizer->normalize($table);
        $col = $canonical->getColumn('ref_id');

        $this->assertSame('bigInteger', $col->type);
        $this->assertTrue($col->unsigned);
    }

    public function test_plain_integer_stays_unchanged(): void
    {
        $table = new DatabaseTableState(
            tableName: 'items',
            columns: [
                new DatabaseColumnState(name: 'quantity', type: 'integer', unsigned: false),
            ],
        );

        $canonical = $this->normalizer->normalize($table);
        $col = $canonical->getColumn('quantity');

        $this->assertSame('integer', $col->type);
        $this->assertFalse($col->unsigned);
    }
}
