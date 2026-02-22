<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SchemaCraft\Migration\CanonicalColumn;
use SchemaCraft\Migration\CanonicalTable;
use SchemaCraft\Migration\SchemaDiffer;

class SchemaDifferTest extends TestCase
{
    private SchemaDiffer $differ;

    protected function setUp(): void
    {
        parent::setUp();
        $this->differ = new SchemaDiffer;
    }

    public function test_produces_create_diff_when_table_does_not_exist(): void
    {
        $desired = new CanonicalTable(
            tableName: 'tags',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true, unsigned: true),
                new CanonicalColumn(name: 'name', type: 'string'),
                new CanonicalColumn(name: 'slug', type: 'string', unique: true),
            ],
            hasTimestamps: true,
        );

        $diff = $this->differ->diff($desired, null);

        $this->assertSame('create', $diff->type);
        $this->assertSame('tags', $diff->tableName);
        $this->assertCount(3, $diff->columnDiffs);
        $this->assertTrue($diff->addTimestamps);
        $this->assertFalse($diff->isEmpty());

        foreach ($diff->columnDiffs as $colDiff) {
            $this->assertSame('add', $colDiff->action);
        }

        $slugIndex = null;

        foreach ($diff->indexDiffs as $idx) {
            if ($idx->columns === ['slug']) {
                $slugIndex = $idx;
            }
        }

        $this->assertNotNull($slugIndex);
        $this->assertTrue($slugIndex->unique);
    }

    public function test_returns_empty_diff_when_table_matches(): void
    {
        $desired = new CanonicalTable(
            tableName: 'tags',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true),
                new CanonicalColumn(name: 'name', type: 'string'),
            ],
            hasTimestamps: true,
        );

        $actual = new CanonicalTable(
            tableName: 'tags',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true),
                new CanonicalColumn(name: 'name', type: 'string'),
                new CanonicalColumn(name: 'created_at', type: 'timestamp', nullable: true),
                new CanonicalColumn(name: 'updated_at', type: 'timestamp', nullable: true),
            ],
            hasTimestamps: true,
        );

        $diff = $this->differ->diff($desired, $actual);

        $this->assertTrue($diff->isEmpty());
    }

    public function test_detects_missing_column(): void
    {
        $desired = new CanonicalTable(
            tableName: 'tags',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true),
                new CanonicalColumn(name: 'name', type: 'string'),
                new CanonicalColumn(name: 'slug', type: 'string', unique: true),
            ],
            hasTimestamps: true,
        );

        $actual = new CanonicalTable(
            tableName: 'tags',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true),
                new CanonicalColumn(name: 'name', type: 'string'),
                new CanonicalColumn(name: 'created_at', type: 'timestamp', nullable: true),
                new CanonicalColumn(name: 'updated_at', type: 'timestamp', nullable: true),
            ],
            hasTimestamps: true,
        );

        $diff = $this->differ->diff($desired, $actual);

        $this->assertSame('update', $diff->type);
        $this->assertFalse($diff->isEmpty());

        $addDiffs = array_filter($diff->columnDiffs, fn ($d) => $d->action === 'add');
        $this->assertCount(1, $addDiffs);
        $this->assertSame('slug', array_values($addDiffs)[0]->columnName);
    }

    public function test_detects_column_type_change(): void
    {
        $desired = new CanonicalTable(
            tableName: 'tags',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true),
                new CanonicalColumn(name: 'name', type: 'text'),
            ],
        );

        $actual = new CanonicalTable(
            tableName: 'tags',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true),
                new CanonicalColumn(name: 'name', type: 'string'),
            ],
        );

        $diff = $this->differ->diff($desired, $actual);

        $modifyDiffs = array_filter($diff->columnDiffs, fn ($d) => $d->action === 'modify');
        $this->assertCount(1, $modifyDiffs);
        $this->assertSame('name', array_values($modifyDiffs)[0]->columnName);
    }

    public function test_detects_nullable_change(): void
    {
        $desired = new CanonicalTable(
            tableName: 'tags',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true),
                new CanonicalColumn(name: 'name', type: 'string', nullable: true),
            ],
        );

        $actual = new CanonicalTable(
            tableName: 'tags',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true),
                new CanonicalColumn(name: 'name', type: 'string', nullable: false),
            ],
        );

        $diff = $this->differ->diff($desired, $actual);

        $modifyDiffs = array_filter($diff->columnDiffs, fn ($d) => $d->action === 'modify');
        $this->assertCount(1, $modifyDiffs);
    }

    public function test_detects_extra_columns_as_drops(): void
    {
        $desired = new CanonicalTable(
            tableName: 'tags',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true),
                new CanonicalColumn(name: 'name', type: 'string'),
            ],
        );

        $actual = new CanonicalTable(
            tableName: 'tags',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true),
                new CanonicalColumn(name: 'name', type: 'string'),
                new CanonicalColumn(name: 'old_field', type: 'string'),
            ],
        );

        $diff = $this->differ->diff($desired, $actual);

        $dropDiffs = array_filter($diff->columnDiffs, fn ($d) => $d->action === 'drop');
        $this->assertCount(1, $dropDiffs);
        $this->assertSame('old_field', array_values($dropDiffs)[0]->columnName);
    }

    public function test_detects_missing_timestamps(): void
    {
        $desired = new CanonicalTable(
            tableName: 'tags',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true),
            ],
            hasTimestamps: true,
        );

        $actual = new CanonicalTable(
            tableName: 'tags',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true),
            ],
            hasTimestamps: false,
        );

        $diff = $this->differ->diff($desired, $actual);

        $this->assertTrue($diff->addTimestamps);
    }

    public function test_detects_missing_soft_deletes(): void
    {
        $desired = new CanonicalTable(
            tableName: 'tags',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true),
            ],
            hasSoftDeletes: true,
        );

        $actual = new CanonicalTable(
            tableName: 'tags',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true),
            ],
            hasSoftDeletes: false,
        );

        $diff = $this->differ->diff($desired, $actual);

        $this->assertTrue($diff->addSoftDeletes);
    }

    public function test_does_not_drop_primary_key_columns(): void
    {
        $desired = new CanonicalTable(
            tableName: 'tags',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true),
            ],
        );

        $actual = new CanonicalTable(
            tableName: 'tags',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true),
            ],
        );

        $diff = $this->differ->diff($desired, $actual);

        $dropDiffs = array_filter($diff->columnDiffs, fn ($d) => $d->action === 'drop');
        $this->assertCount(0, $dropDiffs);
    }

    public function test_does_not_drop_managed_timestamp_columns(): void
    {
        $desired = new CanonicalTable(
            tableName: 'tags',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true),
            ],
            hasTimestamps: false,
        );

        $actual = new CanonicalTable(
            tableName: 'tags',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true),
                new CanonicalColumn(name: 'created_at', type: 'timestamp', nullable: true),
                new CanonicalColumn(name: 'updated_at', type: 'timestamp', nullable: true),
            ],
            hasTimestamps: true,
        );

        $diff = $this->differ->diff($desired, $actual);

        $dropDiffs = array_filter($diff->columnDiffs, fn ($d) => $d->action === 'drop');
        $this->assertCount(0, $dropDiffs);
        $this->assertTrue($diff->dropTimestamps);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  RenamedFrom tests
    // ──────────────────────────────────────────────────────────────────────

    public function test_detects_rename_when_old_column_exists_in_db(): void
    {
        $desired = new CanonicalTable(
            tableName: 'posts',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true),
                new CanonicalColumn(name: 'title', type: 'string'),
            ],
        );

        $actual = new CanonicalTable(
            tableName: 'posts',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true),
                new CanonicalColumn(name: 'old_title', type: 'string'),
            ],
        );

        $diff = $this->differ->diff($desired, $actual, renameMap: ['title' => 'old_title']);

        $renameDiffs = array_filter($diff->columnDiffs, fn ($d) => $d->action === 'rename');
        $this->assertCount(1, $renameDiffs);

        $rename = array_values($renameDiffs)[0];
        $this->assertSame('title', $rename->columnName);
        $this->assertSame('old_title', $rename->oldColumnName);
    }

    public function test_rename_produces_no_add_or_drop(): void
    {
        $desired = new CanonicalTable(
            tableName: 'posts',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true),
                new CanonicalColumn(name: 'title', type: 'string'),
            ],
        );

        $actual = new CanonicalTable(
            tableName: 'posts',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true),
                new CanonicalColumn(name: 'old_title', type: 'string'),
            ],
        );

        $diff = $this->differ->diff($desired, $actual, renameMap: ['title' => 'old_title']);

        $addDiffs = array_filter($diff->columnDiffs, fn ($d) => $d->action === 'add');
        $dropDiffs = array_filter($diff->columnDiffs, fn ($d) => $d->action === 'drop');

        $this->assertCount(0, $addDiffs);
        $this->assertCount(0, $dropDiffs);
    }

    public function test_rename_with_type_change_produces_rename_and_modify(): void
    {
        $desired = new CanonicalTable(
            tableName: 'posts',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true),
                new CanonicalColumn(name: 'title', type: 'text'),
            ],
        );

        $actual = new CanonicalTable(
            tableName: 'posts',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true),
                new CanonicalColumn(name: 'old_title', type: 'string'),
            ],
        );

        $diff = $this->differ->diff($desired, $actual, renameMap: ['title' => 'old_title']);

        $renameDiffs = array_filter($diff->columnDiffs, fn ($d) => $d->action === 'rename');
        $modifyDiffs = array_filter($diff->columnDiffs, fn ($d) => $d->action === 'modify');

        $this->assertCount(1, $renameDiffs);
        $this->assertCount(1, $modifyDiffs);

        // Rename comes before modify in the diff
        $actions = array_map(fn ($d) => $d->action, $diff->columnDiffs);
        $renameIndex = array_search('rename', $actions);
        $modifyIndex = array_search('modify', $actions);
        $this->assertLessThan($modifyIndex, $renameIndex, 'Rename should come before modify');

        // Modify targets the new column name
        $modify = array_values($modifyDiffs)[0];
        $this->assertSame('title', $modify->columnName);
    }

    public function test_stale_renamed_from_is_ignored_when_new_column_exists(): void
    {
        // Old column gone, new column already exists — stale attribute, in sync
        $desired = new CanonicalTable(
            tableName: 'posts',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true),
                new CanonicalColumn(name: 'title', type: 'string'),
            ],
        );

        $actual = new CanonicalTable(
            tableName: 'posts',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true),
                new CanonicalColumn(name: 'title', type: 'string'),
            ],
        );

        $diff = $this->differ->diff($desired, $actual, renameMap: ['title' => 'old_title']);

        $this->assertTrue($diff->isEmpty(), 'Stale RenamedFrom with existing column should produce empty diff');
    }

    public function test_stale_renamed_from_with_missing_both_columns_becomes_add(): void
    {
        // Old column gone, new column also doesn't exist → normal add
        $desired = new CanonicalTable(
            tableName: 'posts',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true),
                new CanonicalColumn(name: 'title', type: 'string'),
            ],
        );

        $actual = new CanonicalTable(
            tableName: 'posts',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true),
            ],
        );

        $diff = $this->differ->diff($desired, $actual, renameMap: ['title' => 'old_title']);

        $addDiffs = array_filter($diff->columnDiffs, fn ($d) => $d->action === 'add');
        $this->assertCount(1, $addDiffs);
        $this->assertSame('title', array_values($addDiffs)[0]->columnName);

        $renameDiffs = array_filter($diff->columnDiffs, fn ($d) => $d->action === 'rename');
        $this->assertCount(0, $renameDiffs);
    }

    public function test_throws_when_both_old_and_new_column_exist_in_db(): void
    {
        $desired = new CanonicalTable(
            tableName: 'posts',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true),
                new CanonicalColumn(name: 'title', type: 'string'),
            ],
        );

        $actual = new CanonicalTable(
            tableName: 'posts',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true),
                new CanonicalColumn(name: 'old_title', type: 'string'),
                new CanonicalColumn(name: 'title', type: 'string'),
            ],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('both [title] and [old_title] exist');

        $this->differ->diff($desired, $actual, renameMap: ['title' => 'old_title']);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Unsigned comparison tests
    // ──────────────────────────────────────────────────────────────────────

    public function test_no_diff_when_unsigned_matches(): void
    {
        $desired = new CanonicalTable(
            tableName: 'posts',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true, unsigned: true),
                new CanonicalColumn(name: 'user_id', type: 'unsignedBigInteger', unsigned: true, index: true),
            ],
        );

        $actual = new CanonicalTable(
            tableName: 'posts',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true, unsigned: true),
                new CanonicalColumn(name: 'user_id', type: 'unsignedBigInteger', unsigned: true, index: true),
            ],
        );

        $diff = $this->differ->diff($desired, $actual);

        $this->assertTrue($diff->isEmpty(), 'No diff expected when unsigned columns match');
    }

    public function test_detects_unsigned_mismatch(): void
    {
        $desired = new CanonicalTable(
            tableName: 'posts',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true, unsigned: true),
                new CanonicalColumn(name: 'count', type: 'integer', unsigned: true),
            ],
        );

        $actual = new CanonicalTable(
            tableName: 'posts',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true, unsigned: true),
                new CanonicalColumn(name: 'count', type: 'integer', unsigned: false),
            ],
        );

        $diff = $this->differ->diff($desired, $actual);

        $modifyDiffs = array_filter($diff->columnDiffs, fn ($d) => $d->action === 'modify');
        $this->assertCount(1, $modifyDiffs);
        $this->assertSame('count', array_values($modifyDiffs)[0]->columnName);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Single-column index diff tests
    // ──────────────────────────────────────────────────────────────────────

    public function test_no_diff_when_index_already_exists(): void
    {
        $desired = new CanonicalTable(
            tableName: 'posts',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true, unsigned: true),
                new CanonicalColumn(name: 'status', type: 'string', index: true),
            ],
        );

        $actual = new CanonicalTable(
            tableName: 'posts',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true, unsigned: true),
                new CanonicalColumn(name: 'status', type: 'string', index: true),
            ],
        );

        $diff = $this->differ->diff($desired, $actual);

        $this->assertTrue($diff->isEmpty(), 'No diff expected when index already exists');
    }

    public function test_detects_missing_index_on_existing_column(): void
    {
        $desired = new CanonicalTable(
            tableName: 'posts',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true, unsigned: true),
                new CanonicalColumn(name: 'status', type: 'string', index: true),
            ],
        );

        $actual = new CanonicalTable(
            tableName: 'posts',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true, unsigned: true),
                new CanonicalColumn(name: 'status', type: 'string'),
            ],
        );

        $diff = $this->differ->diff($desired, $actual);

        $indexDiffs = array_filter($diff->indexDiffs, fn ($idx) => $idx->columns === ['status'] && $idx->action === 'add');
        $this->assertCount(1, $indexDiffs, 'Should detect missing index on existing column');
    }

    public function test_detects_missing_unique_on_existing_column(): void
    {
        $desired = new CanonicalTable(
            tableName: 'posts',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true, unsigned: true),
                new CanonicalColumn(name: 'slug', type: 'string', unique: true),
            ],
        );

        $actual = new CanonicalTable(
            tableName: 'posts',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true, unsigned: true),
                new CanonicalColumn(name: 'slug', type: 'string'),
            ],
        );

        $diff = $this->differ->diff($desired, $actual);

        $indexDiffs = array_filter($diff->indexDiffs, fn ($idx) => $idx->columns === ['slug'] && $idx->action === 'add' && $idx->unique);
        $this->assertCount(1, $indexDiffs, 'Should detect missing unique index on existing column');
    }

    public function test_no_diff_when_unique_index_already_exists(): void
    {
        $desired = new CanonicalTable(
            tableName: 'posts',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true, unsigned: true),
                new CanonicalColumn(name: 'slug', type: 'string', unique: true),
            ],
        );

        $actual = new CanonicalTable(
            tableName: 'posts',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true, unsigned: true),
                new CanonicalColumn(name: 'slug', type: 'string', unique: true),
            ],
        );

        $diff = $this->differ->diff($desired, $actual);

        $this->assertTrue($diff->isEmpty(), 'No diff expected when unique index already exists');
    }

    public function test_throws_when_duplicate_renamed_from_declared(): void
    {
        $desired = new CanonicalTable(
            tableName: 'posts',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true),
                new CanonicalColumn(name: 'title', type: 'string'),
                new CanonicalColumn(name: 'heading', type: 'string'),
            ],
        );

        $actual = new CanonicalTable(
            tableName: 'posts',
            columns: [
                new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true),
                new CanonicalColumn(name: 'old_name', type: 'string'),
            ],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Multiple columns on table [posts] declare #[RenamedFrom('old_name')]");

        $this->differ->diff($desired, $actual, renameMap: ['title' => 'old_name', 'heading' => 'old_name']);
    }

    public function test_detects_expression_default_mismatch(): void
    {
        $desired = new CanonicalTable(
            tableName: 'posts',
            columns: [
                new CanonicalColumn(name: 'id', type: 'bigInteger', primary: true, autoIncrement: true, unsigned: true),
                new CanonicalColumn(name: 'verified_at', type: 'timestamp', nullable: true, expressionDefault: 'CURRENT_TIMESTAMP'),
            ],
        );

        $actual = new CanonicalTable(
            tableName: 'posts',
            columns: [
                new CanonicalColumn(name: 'id', type: 'bigInteger', primary: true, autoIncrement: true, unsigned: true),
                new CanonicalColumn(name: 'verified_at', type: 'timestamp', nullable: true),
            ],
        );

        $diff = $this->differ->diff($desired, $actual);
        $this->assertFalse($diff->isEmpty());

        $verifiedDiff = null;
        foreach ($diff->columnDiffs as $colDiff) {
            if ($colDiff->desired?->name === 'verified_at') {
                $verifiedDiff = $colDiff;
            }
        }

        $this->assertNotNull($verifiedDiff);
        $this->assertSame('modify', $verifiedDiff->action);
    }

    public function test_no_diff_when_expression_defaults_match(): void
    {
        $desired = new CanonicalTable(
            tableName: 'posts',
            columns: [
                new CanonicalColumn(name: 'id', type: 'bigInteger', primary: true, autoIncrement: true, unsigned: true),
                new CanonicalColumn(name: 'verified_at', type: 'timestamp', nullable: true, expressionDefault: 'CURRENT_TIMESTAMP'),
            ],
        );

        $actual = new CanonicalTable(
            tableName: 'posts',
            columns: [
                new CanonicalColumn(name: 'id', type: 'bigInteger', primary: true, autoIncrement: true, unsigned: true),
                new CanonicalColumn(name: 'verified_at', type: 'timestamp', nullable: true, expressionDefault: 'CURRENT_TIMESTAMP'),
            ],
        );

        $diff = $this->differ->diff($desired, $actual);
        $this->assertTrue($diff->isEmpty());
    }

    public function test_normalizes_now_to_current_timestamp(): void
    {
        $desired = new CanonicalTable(
            tableName: 'posts',
            columns: [
                new CanonicalColumn(name: 'id', type: 'bigInteger', primary: true, autoIncrement: true, unsigned: true),
                new CanonicalColumn(name: 'verified_at', type: 'timestamp', nullable: true, expressionDefault: 'CURRENT_TIMESTAMP'),
            ],
        );

        $actual = new CanonicalTable(
            tableName: 'posts',
            columns: [
                new CanonicalColumn(name: 'id', type: 'bigInteger', primary: true, autoIncrement: true, unsigned: true),
                new CanonicalColumn(name: 'verified_at', type: 'timestamp', nullable: true, expressionDefault: 'NOW()'),
            ],
        );

        $diff = $this->differ->diff($desired, $actual);
        $this->assertTrue($diff->isEmpty());
    }
}
