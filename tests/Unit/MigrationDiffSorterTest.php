<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Migration\ForeignKeyDiff;
use SchemaCraft\Migration\MigrationDiffSorter;
use SchemaCraft\Migration\TableDiff;

class MigrationDiffSorterTest extends TestCase
{
    private MigrationDiffSorter $sorter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sorter = new MigrationDiffSorter;
    }

    public function test_empty_array_returns_empty(): void
    {
        $this->assertSame([], $this->sorter->sort([]));
    }

    public function test_single_diff_returns_unchanged(): void
    {
        $diff = new TableDiff(tableName: 'users', type: 'create');
        $result = $this->sorter->sort([$diff]);

        $this->assertCount(1, $result);
        $this->assertSame('users', $result[0]->tableName);
    }

    public function test_creates_sorted_by_fk_dependency(): void
    {
        $orders = new TableDiff(
            tableName: 'orders',
            type: 'create',
            fkDiffs: [
                new ForeignKeyDiff(
                    action: 'add',
                    column: 'customer_id',
                    foreignTable: 'customers',
                    foreignColumn: 'id',
                ),
            ],
        );

        $customers = new TableDiff(tableName: 'customers', type: 'create');

        $result = $this->sorter->sort([$orders, $customers]);

        $this->assertSame('customers', $result[0]->tableName);
        $this->assertSame('orders', $result[1]->tableName);
    }

    public function test_chain_dependency_ordering(): void
    {
        $orderItems = new TableDiff(
            tableName: 'order_items',
            type: 'create',
            fkDiffs: [
                new ForeignKeyDiff(action: 'add', column: 'order_id', foreignTable: 'orders', foreignColumn: 'id'),
            ],
        );

        $orders = new TableDiff(
            tableName: 'orders',
            type: 'create',
            fkDiffs: [
                new ForeignKeyDiff(action: 'add', column: 'customer_id', foreignTable: 'customers', foreignColumn: 'id'),
            ],
        );

        $customers = new TableDiff(tableName: 'customers', type: 'create');

        // Pass in reverse dependency order
        $result = $this->sorter->sort([$orderItems, $orders, $customers]);

        $names = array_map(fn (TableDiff $d) => $d->tableName, $result);
        $this->assertSame(['customers', 'orders', 'order_items'], $names);
    }

    public function test_creates_without_fks_maintain_original_order(): void
    {
        $a = new TableDiff(tableName: 'alpha', type: 'create');
        $b = new TableDiff(tableName: 'bravo', type: 'create');
        $c = new TableDiff(tableName: 'charlie', type: 'create');

        $result = $this->sorter->sort([$a, $b, $c]);

        $names = array_map(fn (TableDiff $d) => $d->tableName, $result);
        $this->assertSame(['alpha', 'bravo', 'charlie'], $names);
    }

    public function test_updates_come_after_creates(): void
    {
        $update = new TableDiff(tableName: 'existing', type: 'update');
        $create = new TableDiff(tableName: 'new_table', type: 'create');

        $result = $this->sorter->sort([$update, $create]);

        $this->assertSame('new_table', $result[0]->tableName);
        $this->assertSame('existing', $result[1]->tableName);
    }

    public function test_fk_to_external_table_is_ignored(): void
    {
        $posts = new TableDiff(
            tableName: 'posts',
            type: 'create',
            fkDiffs: [
                new ForeignKeyDiff(action: 'add', column: 'user_id', foreignTable: 'users', foreignColumn: 'id'),
            ],
        );

        $tags = new TableDiff(tableName: 'tags', type: 'create');

        // 'users' is not in the batch — FK should be ignored for ordering
        $result = $this->sorter->sort([$posts, $tags]);

        $names = array_map(fn (TableDiff $d) => $d->tableName, $result);
        $this->assertSame(['posts', 'tags'], $names);
    }

    public function test_self_referencing_fk_does_not_loop(): void
    {
        $categories = new TableDiff(
            tableName: 'categories',
            type: 'create',
            fkDiffs: [
                new ForeignKeyDiff(action: 'add', column: 'parent_id', foreignTable: 'categories', foreignColumn: 'id'),
            ],
        );

        $result = $this->sorter->sort([$categories]);

        $this->assertCount(1, $result);
        $this->assertSame('categories', $result[0]->tableName);
    }

    public function test_circular_dependency_does_not_hang(): void
    {
        $a = new TableDiff(
            tableName: 'table_a',
            type: 'create',
            fkDiffs: [
                new ForeignKeyDiff(action: 'add', column: 'b_id', foreignTable: 'table_b', foreignColumn: 'id'),
            ],
        );

        $b = new TableDiff(
            tableName: 'table_b',
            type: 'create',
            fkDiffs: [
                new ForeignKeyDiff(action: 'add', column: 'a_id', foreignTable: 'table_a', foreignColumn: 'id'),
            ],
        );

        $result = $this->sorter->sort([$a, $b]);

        // Both should be present (fallback appends remaining)
        $names = array_map(fn (TableDiff $d) => $d->tableName, $result);
        $this->assertCount(2, $names);
        $this->assertContains('table_a', $names);
        $this->assertContains('table_b', $names);
    }

    public function test_drop_fks_are_ignored_for_ordering(): void
    {
        $orders = new TableDiff(
            tableName: 'orders',
            type: 'create',
            fkDiffs: [
                new ForeignKeyDiff(action: 'drop', column: 'customer_id', foreignTable: 'customers'),
            ],
        );

        $customers = new TableDiff(tableName: 'customers', type: 'create');

        // Drop FK should not create dependency — original order preserved
        $result = $this->sorter->sort([$orders, $customers]);

        $this->assertSame('orders', $result[0]->tableName);
        $this->assertSame('customers', $result[1]->tableName);
    }

    public function test_multiple_fk_dependencies(): void
    {
        $assignments = new TableDiff(
            tableName: 'assignments',
            type: 'create',
            fkDiffs: [
                new ForeignKeyDiff(action: 'add', column: 'user_id', foreignTable: 'users', foreignColumn: 'id'),
                new ForeignKeyDiff(action: 'add', column: 'project_id', foreignTable: 'projects', foreignColumn: 'id'),
            ],
        );

        $users = new TableDiff(tableName: 'users', type: 'create');
        $projects = new TableDiff(tableName: 'projects', type: 'create');

        $result = $this->sorter->sort([$assignments, $users, $projects]);

        $names = array_map(fn (TableDiff $d) => $d->tableName, $result);
        $assignmentsIdx = array_search('assignments', $names);
        $usersIdx = array_search('users', $names);
        $projectsIdx = array_search('projects', $names);

        $this->assertGreaterThan($usersIdx, $assignmentsIdx);
        $this->assertGreaterThan($projectsIdx, $assignmentsIdx);
    }
}
