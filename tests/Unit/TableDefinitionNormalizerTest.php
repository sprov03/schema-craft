<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Migration\CanonicalColumn;
use SchemaCraft\Migration\CanonicalIndex;
use SchemaCraft\Migration\TableDefinitionNormalizer;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Scanner\TableDefinition;

class TableDefinitionNormalizerTest extends TestCase
{
    private TableDefinitionNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new TableDefinitionNormalizer;
    }

    public function test_normalizes_basic_columns(): void
    {
        $table = new TableDefinition(
            tableName: 'tags',
            schemaClass: 'App\Schemas\TagSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true, autoIncrement: true, unsigned: true, castType: 'integer'),
                new ColumnDefinition(name: 'name', columnType: 'string', castType: 'string'),
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

    public function test_strips_schema_only_properties(): void
    {
        $table = new TableDefinition(
            tableName: 'tags',
            schemaClass: 'App\Schemas\TagSchema',
            columns: [
                new ColumnDefinition(
                    name: 'name',
                    columnType: 'string',
                    castType: 'string',
                    attributes: [new \stdClass],
                    renamedFrom: 'old_name',
                ),
            ],
        );

        $canonical = $this->normalizer->normalize($table);
        $col = $canonical->getColumn('name');

        // CanonicalColumn has no castType, attributes, or renamedFrom
        $this->assertSame('string', $col->type);
        $this->assertFalse(property_exists($col, 'castType'));
        $this->assertFalse(property_exists($col, 'attributes'));
        $this->assertFalse(property_exists($col, 'renamedFrom'));
    }

    public function test_maps_column_type_to_type(): void
    {
        $table = new TableDefinition(
            tableName: 'items',
            schemaClass: 'App\Schemas\ItemSchema',
            columns: [
                new ColumnDefinition(name: 'count', columnType: 'unsignedBigInteger', unsigned: true),
            ],
        );

        $canonical = $this->normalizer->normalize($table);
        $col = $canonical->getColumn('count');

        // Compound unsigned types decompose to base type + unsigned flag
        $this->assertSame('bigInteger', $col->type);
        $this->assertTrue($col->unsigned);
    }

    public function test_preserves_index_and_unique_flags(): void
    {
        $table = new TableDefinition(
            tableName: 'tags',
            schemaClass: 'App\Schemas\TagSchema',
            columns: [
                new ColumnDefinition(name: 'slug', columnType: 'string', unique: true),
                new ColumnDefinition(name: 'category', columnType: 'string', index: true),
                new ColumnDefinition(name: 'name', columnType: 'string'),
            ],
        );

        $canonical = $this->normalizer->normalize($table);

        $this->assertTrue($canonical->getColumn('slug')->unique);
        $this->assertFalse($canonical->getColumn('slug')->index);

        $this->assertTrue($canonical->getColumn('category')->index);
        $this->assertFalse($canonical->getColumn('category')->unique);

        $this->assertFalse($canonical->getColumn('name')->unique);
        $this->assertFalse($canonical->getColumn('name')->index);
    }

    public function test_preserves_nullable_and_default(): void
    {
        $table = new TableDefinition(
            tableName: 'items',
            schemaClass: 'App\Schemas\ItemSchema',
            columns: [
                new ColumnDefinition(name: 'bio', columnType: 'text', nullable: true),
                new ColumnDefinition(name: 'status', columnType: 'string', default: 'active', hasDefault: true),
            ],
        );

        $canonical = $this->normalizer->normalize($table);

        $this->assertTrue($canonical->getColumn('bio')->nullable);
        $this->assertTrue($canonical->getColumn('status')->hasDefault);
        $this->assertSame('active', $canonical->getColumn('status')->default);
    }

    public function test_preserves_length_precision_scale(): void
    {
        $table = new TableDefinition(
            tableName: 'items',
            schemaClass: 'App\Schemas\ItemSchema',
            columns: [
                new ColumnDefinition(name: 'code', columnType: 'string', length: 100),
                new ColumnDefinition(name: 'price', columnType: 'decimal', precision: 10, scale: 2),
            ],
        );

        $canonical = $this->normalizer->normalize($table);

        $this->assertSame(100, $canonical->getColumn('code')->length);
        $this->assertSame(10, $canonical->getColumn('price')->precision);
        $this->assertSame(2, $canonical->getColumn('price')->scale);
    }

    public function test_skips_managed_timestamp_columns(): void
    {
        $table = new TableDefinition(
            tableName: 'tags',
            schemaClass: 'App\Schemas\TagSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true),
                new ColumnDefinition(name: 'created_at', columnType: 'timestamp'),
                new ColumnDefinition(name: 'updated_at', columnType: 'timestamp'),
            ],
            hasTimestamps: true,
        );

        $canonical = $this->normalizer->normalize($table);

        $this->assertCount(1, $canonical->columns);
        $this->assertSame('id', $canonical->columns[0]->name);
        $this->assertTrue($canonical->hasTimestamps);
    }

    public function test_skips_managed_soft_delete_columns(): void
    {
        $table = new TableDefinition(
            tableName: 'tags',
            schemaClass: 'App\Schemas\TagSchema',
            columns: [
                new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true),
                new ColumnDefinition(name: 'deleted_at', columnType: 'timestamp'),
            ],
            hasSoftDeletes: true,
        );

        $canonical = $this->normalizer->normalize($table);

        $this->assertCount(1, $canonical->columns);
        $this->assertTrue($canonical->hasSoftDeletes);
    }

    public function test_normalizes_composite_indexes(): void
    {
        $table = new TableDefinition(
            tableName: 'tags',
            schemaClass: 'App\Schemas\TagSchema',
            columns: [
                new ColumnDefinition(name: 'a', columnType: 'string'),
                new ColumnDefinition(name: 'b', columnType: 'string'),
            ],
            compositeIndexes: [['a', 'b']],
        );

        $canonical = $this->normalizer->normalize($table);

        $this->assertCount(1, $canonical->compositeIndexes);
        $this->assertInstanceOf(CanonicalIndex::class, $canonical->compositeIndexes[0]);
        $this->assertSame(['a', 'b'], $canonical->compositeIndexes[0]->columns);
        $this->assertFalse($canonical->compositeIndexes[0]->unique);
    }

    public function test_extract_rename_map(): void
    {
        $table = new TableDefinition(
            tableName: 'tags',
            schemaClass: 'App\Schemas\TagSchema',
            columns: [
                new ColumnDefinition(name: 'label', columnType: 'string', renamedFrom: 'name'),
                new ColumnDefinition(name: 'slug', columnType: 'string'),
            ],
        );

        $map = TableDefinitionNormalizer::extractRenameMap($table);

        $this->assertSame(['label' => 'name'], $map);
    }

    public function test_extract_rename_map_empty_when_no_renames(): void
    {
        $table = new TableDefinition(
            tableName: 'tags',
            schemaClass: 'App\Schemas\TagSchema',
            columns: [
                new ColumnDefinition(name: 'name', columnType: 'string'),
            ],
        );

        $map = TableDefinitionNormalizer::extractRenameMap($table);

        $this->assertEmpty($map);
    }

    public function test_decomposes_unsigned_integer_to_base_type(): void
    {
        $table = new TableDefinition(
            tableName: 'items',
            schemaClass: 'App\Schemas\ItemSchema',
            columns: [
                new ColumnDefinition(name: 'count', columnType: 'unsignedInteger', unsigned: true),
            ],
        );

        $canonical = $this->normalizer->normalize($table);
        $col = $canonical->getColumn('count');

        $this->assertSame('integer', $col->type);
        $this->assertTrue($col->unsigned);
    }

    public function test_decomposes_unsigned_small_integer_to_base_type(): void
    {
        $table = new TableDefinition(
            tableName: 'items',
            schemaClass: 'App\Schemas\ItemSchema',
            columns: [
                new ColumnDefinition(name: 'priority', columnType: 'unsignedSmallInteger', unsigned: true),
            ],
        );

        $canonical = $this->normalizer->normalize($table);
        $col = $canonical->getColumn('priority');

        $this->assertSame('smallInteger', $col->type);
        $this->assertTrue($col->unsigned);
    }

    public function test_plain_integer_stays_unchanged(): void
    {
        $table = new TableDefinition(
            tableName: 'items',
            schemaClass: 'App\Schemas\ItemSchema',
            columns: [
                new ColumnDefinition(name: 'quantity', columnType: 'integer', unsigned: false),
            ],
        );

        $canonical = $this->normalizer->normalize($table);
        $col = $canonical->getColumn('quantity');

        $this->assertSame('integer', $col->type);
        $this->assertFalse($col->unsigned);
    }
}
