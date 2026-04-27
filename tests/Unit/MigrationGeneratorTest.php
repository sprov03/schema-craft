<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Migration\CanonicalColumn;
use SchemaCraft\Migration\ColumnDiff;
use SchemaCraft\Migration\ForeignKeyDiff;
use SchemaCraft\Migration\IndexDiff;
use SchemaCraft\Migration\MigrationGenerator;
use SchemaCraft\Migration\TableDiff;

class MigrationGeneratorTest extends TestCase
{
    private MigrationGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new MigrationGenerator;
    }

    public function test_generates_create_table_migration(): void
    {
        $diff = new TableDiff(
            tableName: 'tags',
            type: 'create',
            columnDiffs: [
                new ColumnDiff(action: 'add', columnName: 'id', desired: new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true)),
                new ColumnDiff(action: 'add', columnName: 'name', desired: new CanonicalColumn(name: 'name', type: 'string')),
            ],
            addTimestamps: true,
        );

        $code = $this->generator->generate($diff);

        $this->assertStringContainsString('Schema::create(\'tags\'', $code);
        $this->assertStringContainsString('$table->id();', $code);
        $this->assertStringContainsString('$table->string(\'name\')', $code);
        $this->assertStringContainsString('$table->timestamps();', $code);
        $this->assertStringContainsString('Schema::dropIfExists(\'tags\')', $code);
    }

    public function test_generates_id_shorthand(): void
    {
        $diff = new TableDiff(
            tableName: 'posts',
            type: 'create',
            columnDiffs: [
                new ColumnDiff(action: 'add', columnName: 'id', desired: new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true)),
            ],
        );

        $code = $this->generator->generate($diff);

        $this->assertStringContainsString('$table->id();', $code);
        $this->assertStringNotContainsString('unsignedBigInteger', $code);
    }

    public function test_generates_custom_id_name(): void
    {
        $diff = new TableDiff(
            tableName: 'posts',
            type: 'create',
            columnDiffs: [
                new ColumnDiff(action: 'add', columnName: 'post_id', desired: new CanonicalColumn(name: 'post_id', type: 'unsignedBigInteger', primary: true, autoIncrement: true)),
            ],
        );

        $code = $this->generator->generate($diff);

        $this->assertStringContainsString('$table->id(\'post_id\')', $code);
    }

    public function test_generates_uuid_primary_key(): void
    {
        $diff = new TableDiff(
            tableName: 'posts',
            type: 'create',
            columnDiffs: [
                new ColumnDiff(action: 'add', columnName: 'id', desired: new CanonicalColumn(name: 'id', type: 'uuid', primary: true, autoIncrement: true)),
            ],
        );

        $code = $this->generator->generate($diff);

        $this->assertStringContainsString('$table->uuid(\'id\')->primary()', $code);
    }

    public function test_generates_update_migration_with_add_column(): void
    {
        $diff = new TableDiff(
            tableName: 'tags',
            type: 'update',
            columnDiffs: [
                new ColumnDiff(action: 'add', columnName: 'slug', desired: new CanonicalColumn(name: 'slug', type: 'string', unique: true)),
            ],
        );

        $code = $this->generator->generate($diff);

        $this->assertStringContainsString('Schema::table(\'tags\'', $code);
        $this->assertStringContainsString('$table->string(\'slug\')->unique();', $code);
        $this->assertStringContainsString('$table->dropColumn(\'slug\')', $code);
    }

    public function test_generates_modify_column_with_change(): void
    {
        $diff = new TableDiff(
            tableName: 'tags',
            type: 'update',
            columnDiffs: [
                new ColumnDiff(
                    action: 'modify',
                    columnName: 'name',
                    desired: new CanonicalColumn(name: 'name', type: 'text'),
                    actual: new CanonicalColumn(name: 'name', type: 'string'),
                ),
            ],
        );

        $code = $this->generator->generate($diff);

        $this->assertStringContainsString('$table->text(\'name\')->change();', $code);
        $this->assertStringContainsString('$table->string(\'name\')->change();', $code);
    }

    public function test_generates_drop_column_as_comment(): void
    {
        $diff = new TableDiff(
            tableName: 'tags',
            type: 'update',
            columnDiffs: [
                new ColumnDiff(
                    action: 'drop',
                    columnName: 'old_field',
                    actual: new CanonicalColumn(name: 'old_field', type: 'string'),
                ),
            ],
        );

        $code = $this->generator->generate($diff);

        $this->assertStringContainsString("// Column 'old_field' exists in database but not in schema.", $code);
        $this->assertStringContainsString("// \$table->dropColumn('old_field');", $code);
    }

    public function test_generates_foreign_key_constraint(): void
    {
        $diff = new TableDiff(
            tableName: 'posts',
            type: 'create',
            columnDiffs: [
                new ColumnDiff(action: 'add', columnName: 'id', desired: new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true)),
                new ColumnDiff(action: 'add', columnName: 'user_id', desired: new CanonicalColumn(name: 'user_id', type: 'unsignedBigInteger')),
            ],
            fkDiffs: [
                new ForeignKeyDiff(action: 'add', column: 'user_id', foreignTable: 'users', foreignColumn: 'id', onDelete: 'cascade'),
            ],
        );

        $code = $this->generator->generate($diff);

        $this->assertStringContainsString('$table->foreign(\'user_id\')->references(\'id\')->on(\'users\')->onDelete(\'cascade\')', $code);
    }

    public function test_does_not_render_no_constraint_foreign_keys(): void
    {
        $diff = new TableDiff(
            tableName: 'posts',
            type: 'create',
            columnDiffs: [
                new ColumnDiff(action: 'add', columnName: 'id', desired: new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true)),
                new ColumnDiff(action: 'add', columnName: 'user_id', desired: new CanonicalColumn(name: 'user_id', type: 'unsignedBigInteger')),
            ],
            fkDiffs: [
                new ForeignKeyDiff(action: 'add', column: 'user_id', foreignTable: 'users', foreignColumn: 'id', noConstraint: true),
            ],
        );

        $code = $this->generator->generate($diff);

        $this->assertStringNotContainsString('foreign', $code);
    }

    public function test_generates_nullable_column(): void
    {
        $diff = new TableDiff(
            tableName: 'posts',
            type: 'create',
            columnDiffs: [
                new ColumnDiff(action: 'add', columnName: 'id', desired: new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true)),
                new ColumnDiff(action: 'add', columnName: 'subtitle', desired: new CanonicalColumn(name: 'subtitle', type: 'string', nullable: true)),
            ],
        );

        $code = $this->generator->generate($diff);

        $this->assertStringContainsString('$table->string(\'subtitle\')->nullable();', $code);
    }

    public function test_generates_column_with_default(): void
    {
        $diff = new TableDiff(
            tableName: 'posts',
            type: 'create',
            columnDiffs: [
                new ColumnDiff(action: 'add', columnName: 'id', desired: new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true)),
                new ColumnDiff(action: 'add', columnName: 'status', desired: new CanonicalColumn(name: 'status', type: 'string', hasDefault: true, default: 'draft')),
            ],
        );

        $code = $this->generator->generate($diff);

        $this->assertStringContainsString("->default('draft')", $code);
    }

    public function test_generates_boolean_default(): void
    {
        $diff = new TableDiff(
            tableName: 'posts',
            type: 'create',
            columnDiffs: [
                new ColumnDiff(action: 'add', columnName: 'id', desired: new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true)),
                new ColumnDiff(action: 'add', columnName: 'is_published', desired: new CanonicalColumn(name: 'is_published', type: 'boolean', hasDefault: true, default: false)),
            ],
        );

        $code = $this->generator->generate($diff);

        $this->assertStringContainsString('->default(false)', $code);
    }

    public function test_generates_decimal_with_precision_and_scale(): void
    {
        $diff = new TableDiff(
            tableName: 'products',
            type: 'create',
            columnDiffs: [
                new ColumnDiff(action: 'add', columnName: 'id', desired: new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true)),
                new ColumnDiff(action: 'add', columnName: 'price', desired: new CanonicalColumn(name: 'price', type: 'decimal', precision: 10, scale: 2)),
            ],
        );

        $code = $this->generator->generate($diff);

        $this->assertStringContainsString('$table->decimal(\'price\', 10, 2)', $code);
    }

    public function test_generates_string_with_length(): void
    {
        $diff = new TableDiff(
            tableName: 'tags',
            type: 'create',
            columnDiffs: [
                new ColumnDiff(action: 'add', columnName: 'id', desired: new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true)),
                new ColumnDiff(action: 'add', columnName: 'code', desired: new CanonicalColumn(name: 'code', type: 'string', length: 50)),
            ],
        );

        $code = $this->generator->generate($diff);

        $this->assertStringContainsString('$table->string(\'code\', 50)', $code);
    }

    public function test_generates_soft_deletes(): void
    {
        $diff = new TableDiff(
            tableName: 'posts',
            type: 'create',
            columnDiffs: [
                new ColumnDiff(action: 'add', columnName: 'id', desired: new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true)),
            ],
            addSoftDeletes: true,
        );

        $code = $this->generator->generate($diff);

        $this->assertStringContainsString('$table->softDeletes();', $code);
    }

    public function test_generates_timestamps_in_update_migration(): void
    {
        $diff = new TableDiff(
            tableName: 'tags',
            type: 'update',
            addTimestamps: true,
        );

        $code = $this->generator->generate($diff);

        $this->assertStringContainsString('$table->timestamps();', $code);
        $this->assertStringContainsString('$table->dropTimestamps();', $code);
    }

    public function test_generates_drop_timestamps_in_update_migration(): void
    {
        $diff = new TableDiff(
            tableName: 'tags',
            type: 'update',
            dropTimestamps: true,
        );

        $code = $this->generator->generate($diff);

        $this->assertStringContainsString('$table->dropTimestamps();', $code);
        // Down should restore timestamps
        $this->assertStringContainsString('$table->timestamps();', $code);
    }

    public function test_generates_valid_php_syntax(): void
    {
        $diff = new TableDiff(
            tableName: 'tags',
            type: 'create',
            columnDiffs: [
                new ColumnDiff(action: 'add', columnName: 'id', desired: new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true)),
                new ColumnDiff(action: 'add', columnName: 'name', desired: new CanonicalColumn(name: 'name', type: 'string')),
                new ColumnDiff(action: 'add', columnName: 'slug', desired: new CanonicalColumn(name: 'slug', type: 'string', unique: true)),
            ],
            addTimestamps: true,
        );

        $code = $this->generator->generate($diff);

        // Write to temp file and check syntax
        $tmpFile = tempnam(sys_get_temp_dir(), 'migration_test_');
        file_put_contents($tmpFile, $code);

        $output = [];
        $exitCode = 0;
        exec("php -l {$tmpFile} 2>&1", $output, $exitCode);
        unlink($tmpFile);

        $this->assertSame(0, $exitCode, 'Generated migration has syntax errors: '.implode("\n", $output));
    }

    public function test_writes_migration_file_to_disk(): void
    {
        $diff = new TableDiff(
            tableName: 'tags',
            type: 'create',
            columnDiffs: [
                new ColumnDiff(action: 'add', columnName: 'id', desired: new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true)),
                new ColumnDiff(action: 'add', columnName: 'name', desired: new CanonicalColumn(name: 'name', type: 'string')),
            ],
        );

        $tmpDir = sys_get_temp_dir().'/schema_craft_test_'.uniqid();
        mkdir($tmpDir);

        try {
            $path = $this->generator->write($diff, $tmpDir);

            $this->assertFileExists($path);
            $this->assertStringContainsString('create_tags_table.php', $path);

            $content = file_get_contents($path);
            $this->assertStringContainsString('Schema::create(\'tags\'', $content);
        } finally {
            // Clean up
            array_map('unlink', glob($tmpDir.'/*.php'));
            rmdir($tmpDir);
        }
    }

    public function test_generates_composite_index_in_create_migration(): void
    {
        $diff = new TableDiff(
            tableName: 'posts',
            type: 'create',
            columnDiffs: [
                new ColumnDiff(action: 'add', columnName: 'id', desired: new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true)),
                new ColumnDiff(action: 'add', columnName: 'status', desired: new CanonicalColumn(name: 'status', type: 'string')),
                new ColumnDiff(action: 'add', columnName: 'published_at', desired: new CanonicalColumn(name: 'published_at', type: 'timestamp', nullable: true)),
            ],
            indexDiffs: [
                new IndexDiff(action: 'add', columns: ['status', 'published_at']),
            ],
        );

        $code = $this->generator->generate($diff);

        $this->assertStringContainsString("['status', 'published_at']", $code);
    }

    public function test_generates_indexed_column(): void
    {
        $diff = new TableDiff(
            tableName: 'posts',
            type: 'create',
            columnDiffs: [
                new ColumnDiff(action: 'add', columnName: 'id', desired: new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true)),
                new ColumnDiff(action: 'add', columnName: 'status', desired: new CanonicalColumn(name: 'status', type: 'string', index: true)),
            ],
        );

        $code = $this->generator->generate($diff);

        $this->assertStringContainsString('->index()', $code);
    }

    public function test_generates_unsigned_column(): void
    {
        $diff = new TableDiff(
            tableName: 'posts',
            type: 'create',
            columnDiffs: [
                new ColumnDiff(action: 'add', columnName: 'id', desired: new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true)),
                new ColumnDiff(action: 'add', columnName: 'count', desired: new CanonicalColumn(name: 'count', type: 'integer', unsigned: true)),
            ],
        );

        $code = $this->generator->generate($diff);

        $this->assertStringContainsString('->unsigned()', $code);
    }

    public function test_migration_contains_required_imports(): void
    {
        $diff = new TableDiff(
            tableName: 'tags',
            type: 'create',
            columnDiffs: [
                new ColumnDiff(action: 'add', columnName: 'id', desired: new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true)),
            ],
        );

        $code = $this->generator->generate($diff);

        $this->assertStringContainsString('use Illuminate\Database\Migrations\Migration;', $code);
        $this->assertStringContainsString('use Illuminate\Database\Schema\Blueprint;', $code);
        $this->assertStringContainsString('use Illuminate\Support\Facades\Schema;', $code);
        $this->assertStringContainsString('return new class extends Migration', $code);
    }

    public function test_generates_drop_foreign_key_in_update_down(): void
    {
        $diff = new TableDiff(
            tableName: 'posts',
            type: 'update',
            fkDiffs: [
                new ForeignKeyDiff(action: 'add', column: 'user_id', foreignTable: 'users', foreignColumn: 'id'),
            ],
        );

        $code = $this->generator->generate($diff);

        $this->assertStringContainsString('$table->foreign(\'user_id\')->references(\'id\')->on(\'users\')', $code);
        $this->assertStringContainsString('$table->dropForeign([\'user_id\'])', $code);
    }

    public function test_generates_nullable_modify_with_down(): void
    {
        $diff = new TableDiff(
            tableName: 'tags',
            type: 'update',
            columnDiffs: [
                new ColumnDiff(
                    action: 'modify',
                    columnName: 'name',
                    desired: new CanonicalColumn(name: 'name', type: 'string', nullable: true),
                    actual: new CanonicalColumn(name: 'name', type: 'string', nullable: false),
                ),
            ],
        );

        $code = $this->generator->generate($diff);

        $this->assertStringContainsString('$table->string(\'name\')->nullable()->change();', $code);
        // Down should restore to non-nullable
        $this->assertStringContainsString('$table->string(\'name\')->change();', $code);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Rename column tests
    // ──────────────────────────────────────────────────────────────────────

    public function test_generates_rename_column(): void
    {
        $diff = new TableDiff(
            tableName: 'posts',
            type: 'update',
            columnDiffs: [
                new ColumnDiff(
                    action: 'rename',
                    columnName: 'title',
                    desired: new CanonicalColumn(name: 'title', type: 'string'),
                    actual: new CanonicalColumn(name: 'old_title', type: 'string'),
                    oldColumnName: 'old_title',
                ),
            ],
        );

        $code = $this->generator->generate($diff);

        // Up should rename
        $this->assertStringContainsString("\$table->renameColumn('old_title', 'title');", $code);

        // Down should reverse the rename
        $this->assertStringContainsString("\$table->renameColumn('title', 'old_title');", $code);
    }

    public function test_generates_rename_with_type_change(): void
    {
        $diff = new TableDiff(
            tableName: 'posts',
            type: 'update',
            columnDiffs: [
                new ColumnDiff(
                    action: 'rename',
                    columnName: 'title',
                    desired: new CanonicalColumn(name: 'title', type: 'text'),
                    actual: new CanonicalColumn(name: 'old_title', type: 'string'),
                    oldColumnName: 'old_title',
                ),
                new ColumnDiff(
                    action: 'modify',
                    columnName: 'title',
                    desired: new CanonicalColumn(name: 'title', type: 'text'),
                    actual: new CanonicalColumn(name: 'old_title', type: 'string'),
                ),
            ],
        );

        $code = $this->generator->generate($diff);

        // Up: rename first, then modify
        $this->assertStringContainsString("\$table->renameColumn('old_title', 'title');", $code);
        $this->assertStringContainsString("\$table->text('title')->change();", $code);

        $renamePos = strpos($code, "renameColumn('old_title', 'title')");
        $modifyPos = strpos($code, "text('title')->change()");
        $this->assertLessThan($modifyPos, $renamePos, 'In up(), rename should come before modify');

        // Down: modify-revert first, then rename-revert
        // Find the down() section
        $downPos = strpos($code, 'public function down()');
        $downCode = substr($code, $downPos);

        $downModifyPos = strpos($downCode, "string('old_title')->change()");
        $downRenamePos = strpos($downCode, "renameColumn('title', 'old_title')");

        $this->assertNotFalse($downModifyPos, 'Down should contain modify-revert');
        $this->assertNotFalse($downRenamePos, 'Down should contain rename-revert');
        $this->assertLessThan($downRenamePos, $downModifyPos, 'In down(), modify-revert should come before rename-revert');
    }

    public function test_rename_column_migration_is_valid_php(): void
    {
        $diff = new TableDiff(
            tableName: 'posts',
            type: 'update',
            columnDiffs: [
                new ColumnDiff(
                    action: 'rename',
                    columnName: 'title',
                    desired: new CanonicalColumn(name: 'title', type: 'text'),
                    actual: new CanonicalColumn(name: 'old_title', type: 'string'),
                    oldColumnName: 'old_title',
                ),
                new ColumnDiff(
                    action: 'modify',
                    columnName: 'title',
                    desired: new CanonicalColumn(name: 'title', type: 'text'),
                    actual: new CanonicalColumn(name: 'old_title', type: 'string'),
                ),
            ],
        );

        $code = $this->generator->generate($diff);

        $tmpFile = tempnam(sys_get_temp_dir(), 'migration_rename_');
        file_put_contents($tmpFile, $code);

        $output = [];
        $exitCode = 0;
        exec("php -l {$tmpFile} 2>&1", $output, $exitCode);
        unlink($tmpFile);

        $this->assertSame(0, $exitCode, 'Rename migration has syntax errors: '.implode("\n", $output));
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Down migration preserves unsigned
    // ──────────────────────────────────────────────────────────────────────

    public function test_down_migration_preserves_unsigned(): void
    {
        $diff = new TableDiff(
            tableName: 'posts',
            type: 'update',
            columnDiffs: [
                new ColumnDiff(
                    action: 'modify',
                    columnName: 'sort_order',
                    desired: new CanonicalColumn(name: 'sort_order', type: 'bigInteger', unsigned: true),
                    actual: new CanonicalColumn(name: 'sort_order', type: 'integer', unsigned: true),
                ),
            ],
        );

        $code = $this->generator->generate($diff);

        // Down should include ->unsigned() when restoring the original column
        $downPos = strpos($code, 'public function down()');
        $downCode = substr($code, $downPos);

        $this->assertStringContainsString('->unsigned()->change()', $downCode);
    }

    public function test_down_migration_renders_unsigned_flag_for_base_type(): void
    {
        $diff = new TableDiff(
            tableName: 'posts',
            type: 'update',
            columnDiffs: [
                new ColumnDiff(
                    action: 'modify',
                    columnName: 'user_id',
                    desired: new CanonicalColumn(name: 'user_id', type: 'bigInteger'),
                    actual: new CanonicalColumn(name: 'user_id', type: 'bigInteger', unsigned: true),
                ),
            ],
        );

        $code = $this->generator->generate($diff);

        // Down should use bigInteger() with ->unsigned() (canonical uses base types)
        $downPos = strpos($code, 'public function down()');
        $downCode = substr($code, $downPos);

        $this->assertStringContainsString("bigInteger('user_id')->unsigned()->change()", $downCode);
    }

    public function test_generates_expression_default(): void
    {
        $diff = new TableDiff(
            tableName: 'posts',
            type: 'create',
            columnDiffs: [
                new ColumnDiff(
                    action: 'add',
                    columnName: 'id',
                    desired: new CanonicalColumn(name: 'id', type: 'bigInteger', primary: true, autoIncrement: true, unsigned: true),
                ),
                new ColumnDiff(
                    action: 'add',
                    columnName: 'verified_at',
                    desired: new CanonicalColumn(name: 'verified_at', type: 'timestamp', nullable: true, expressionDefault: 'CURRENT_TIMESTAMP'),
                ),
            ],
        );

        $code = $this->generator->generate($diff);

        $this->assertStringContainsString("->default(DB::raw('CURRENT_TIMESTAMP'))", $code);
        $this->assertStringContainsString('use Illuminate\Support\Facades\DB;', $code);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Batch (two-pass) migration tests
    // ──────────────────────────────────────────────────────────────────────

    public function test_batch_creates_tables_without_fks_in_pass_one(): void
    {
        // Circular dependency: rabbits → litters, litters → rabbits
        $rabbits = new TableDiff(
            tableName: 'rabbits',
            type: 'create',
            columnDiffs: [
                new ColumnDiff(action: 'add', columnName: 'id', desired: new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true)),
                new ColumnDiff(action: 'add', columnName: 'litter_id', desired: new CanonicalColumn(name: 'litter_id', type: 'unsignedBigInteger', nullable: true)),
            ],
            fkDiffs: [
                new ForeignKeyDiff(action: 'add', column: 'litter_id', foreignTable: 'litters', foreignColumn: 'id', onDelete: 'set null'),
            ],
        );

        $litters = new TableDiff(
            tableName: 'litters',
            type: 'create',
            columnDiffs: [
                new ColumnDiff(action: 'add', columnName: 'id', desired: new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true)),
                new ColumnDiff(action: 'add', columnName: 'rabbit_id', desired: new CanonicalColumn(name: 'rabbit_id', type: 'unsignedBigInteger')),
            ],
            fkDiffs: [
                new ForeignKeyDiff(action: 'add', column: 'rabbit_id', foreignTable: 'rabbits', foreignColumn: 'id', onDelete: 'cascade'),
            ],
        );

        $code = $this->generator->generateBatch([$rabbits, $litters]);

        // Both tables should be created (without FKs) before any FK constraints are added
        $rabbitsCreatePos = strpos($code, "Schema::create('rabbits'");
        $littersCreatePos = strpos($code, "Schema::create('litters'");
        $rabbitsTablePos = strpos($code, "Schema::table('rabbits'");
        $littersTablePos = strpos($code, "Schema::table('litters'");

        $this->assertNotFalse($rabbitsCreatePos, 'rabbits Schema::create not found');
        $this->assertNotFalse($littersCreatePos, 'litters Schema::create not found');
        $this->assertNotFalse($rabbitsTablePos, 'rabbits Schema::table (FK pass) not found');
        $this->assertNotFalse($littersTablePos, 'litters Schema::table (FK pass) not found');

        // Creates should come before FK table blocks in up()
        $upStart = strpos($code, 'public function up()');
        $downStart = strpos($code, 'public function down()');
        $upSection = substr($code, $upStart, $downStart - $upStart);

        $upRabbitsCreate = strpos($upSection, "Schema::create('rabbits'");
        $upLittersCreate = strpos($upSection, "Schema::create('litters'");
        $upRabbitsTable = strpos($upSection, "Schema::table('rabbits'");
        $upLittersTable = strpos($upSection, "Schema::table('litters'");

        $this->assertLessThan($upRabbitsTable, $upRabbitsCreate, 'rabbits create should precede its FK table block');
        $this->assertLessThan($upLittersTable, $upLittersCreate, 'litters create should precede its FK table block');
        $this->assertLessThan($upRabbitsTable, $upLittersCreate, 'both creates should precede FK blocks');
    }

    public function test_batch_fk_constraints_added_in_pass_two(): void
    {
        $diff = new TableDiff(
            tableName: 'posts',
            type: 'create',
            columnDiffs: [
                new ColumnDiff(action: 'add', columnName: 'id', desired: new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true)),
                new ColumnDiff(action: 'add', columnName: 'user_id', desired: new CanonicalColumn(name: 'user_id', type: 'unsignedBigInteger')),
            ],
            fkDiffs: [
                new ForeignKeyDiff(action: 'add', column: 'user_id', foreignTable: 'users', foreignColumn: 'id', onDelete: 'cascade'),
            ],
        );

        $code = $this->generator->generateBatch([$diff]);

        // Schema::create should NOT contain the FK
        $createBlock = substr($code, strpos($code, "Schema::create('posts'"), strpos($code, "Schema::table('posts'") - strpos($code, "Schema::create('posts'"));
        $this->assertStringNotContainsString('foreign', $createBlock);

        // Schema::table should contain the FK
        $tablePos = strpos($code, "Schema::table('posts'");
        $tableBlock = substr($code, $tablePos, 300);
        $this->assertStringContainsString("->foreign('user_id')", $tableBlock);
    }

    public function test_batch_includes_updates_inline_in_pass_one(): void
    {
        $createDiff = new TableDiff(
            tableName: 'cats',
            type: 'create',
            columnDiffs: [
                new ColumnDiff(action: 'add', columnName: 'id', desired: new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true)),
                new ColumnDiff(action: 'add', columnName: 'name', desired: new CanonicalColumn(name: 'name', type: 'string')),
            ],
        );

        $updateDiff = new TableDiff(
            tableName: 'dogs',
            type: 'update',
            columnDiffs: [
                new ColumnDiff(action: 'add', columnName: 'breed', desired: new CanonicalColumn(name: 'breed', type: 'string', nullable: true)),
            ],
        );

        $code = $this->generator->generateBatch([$createDiff, $updateDiff]);

        // Both create and table blocks should be present
        $this->assertStringContainsString("Schema::create('cats'", $code);
        $this->assertStringContainsString("Schema::table('dogs'", $code);
        $this->assertStringContainsString("string('breed')", $code);
    }

    public function test_batch_down_drops_fks_before_tables(): void
    {
        $diff = new TableDiff(
            tableName: 'orders',
            type: 'create',
            columnDiffs: [
                new ColumnDiff(action: 'add', columnName: 'id', desired: new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true)),
                new ColumnDiff(action: 'add', columnName: 'user_id', desired: new CanonicalColumn(name: 'user_id', type: 'unsignedBigInteger')),
            ],
            fkDiffs: [
                new ForeignKeyDiff(action: 'add', column: 'user_id', foreignTable: 'users', foreignColumn: 'id'),
            ],
        );

        $code = $this->generator->generateBatch([$diff]);

        $downStart = strpos($code, 'public function down()');
        $downSection = substr($code, $downStart);

        $dropFkPos = strpos($downSection, "dropForeign(['user_id'])");
        $dropTablePos = strpos($downSection, "Schema::dropIfExists('orders')");

        $this->assertNotFalse($dropFkPos, 'down() should dropForeign for user_id');
        $this->assertNotFalse($dropTablePos, 'down() should dropIfExists for orders');
        $this->assertLessThan($dropTablePos, $dropFkPos, 'FK drop should come before table drop in down()');
    }

    public function test_batch_down_reverses_update_diffs(): void
    {
        $updateDiff = new TableDiff(
            tableName: 'users',
            type: 'update',
            columnDiffs: [
                new ColumnDiff(action: 'add', columnName: 'bio', desired: new CanonicalColumn(name: 'bio', type: 'text', nullable: true)),
            ],
        );

        $code = $this->generator->generateBatch([$updateDiff]);

        $this->assertStringContainsString("text('bio')->nullable()", $code);
        $this->assertStringContainsString("dropColumn('bio')", $code);
    }

    public function test_batch_generates_valid_php_syntax(): void
    {
        $tableA = new TableDiff(
            tableName: 'batch_a',
            type: 'create',
            columnDiffs: [
                new ColumnDiff(action: 'add', columnName: 'id', desired: new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true)),
                new ColumnDiff(action: 'add', columnName: 'b_id', desired: new CanonicalColumn(name: 'b_id', type: 'unsignedBigInteger', nullable: true)),
            ],
            fkDiffs: [
                new ForeignKeyDiff(action: 'add', column: 'b_id', foreignTable: 'batch_b', foreignColumn: 'id'),
            ],
        );

        $tableB = new TableDiff(
            tableName: 'batch_b',
            type: 'create',
            columnDiffs: [
                new ColumnDiff(action: 'add', columnName: 'id', desired: new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true)),
                new ColumnDiff(action: 'add', columnName: 'a_id', desired: new CanonicalColumn(name: 'a_id', type: 'unsignedBigInteger', nullable: true)),
            ],
            fkDiffs: [
                new ForeignKeyDiff(action: 'add', column: 'a_id', foreignTable: 'batch_a', foreignColumn: 'id'),
            ],
        );

        $code = $this->generator->generateBatch([$tableA, $tableB]);

        $tmpFile = tempnam(sys_get_temp_dir(), 'batch_migration_test_');
        file_put_contents($tmpFile, $code);

        $output = [];
        $exitCode = 0;
        exec("php -l {$tmpFile} 2>&1", $output, $exitCode);
        unlink($tmpFile);

        $this->assertSame(0, $exitCode, 'Batch migration has syntax errors: '.implode("\n", $output));
    }

    public function test_write_batch_creates_single_file(): void
    {
        $tableA = new TableDiff(
            tableName: 'batch_x',
            type: 'create',
            columnDiffs: [
                new ColumnDiff(action: 'add', columnName: 'id', desired: new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true)),
            ],
        );

        $tableB = new TableDiff(
            tableName: 'batch_y',
            type: 'create',
            columnDiffs: [
                new ColumnDiff(action: 'add', columnName: 'id', desired: new CanonicalColumn(name: 'id', type: 'unsignedBigInteger', primary: true, autoIncrement: true)),
            ],
        );

        $tmpDir = sys_get_temp_dir().'/schema_craft_batch_'.uniqid();
        mkdir($tmpDir);

        try {
            $path = $this->generator->writeBatch([$tableA, $tableB], $tmpDir);

            $this->assertFileExists($path);
            $this->assertStringContainsString('batch_migration.php', $path);

            // Only one file should have been written
            $files = glob($tmpDir.'/*.php');
            $this->assertCount(1, $files);

            $content = file_get_contents($path);
            $this->assertStringContainsString("Schema::create('batch_x'", $content);
            $this->assertStringContainsString("Schema::create('batch_y'", $content);
        } finally {
            array_map('unlink', glob($tmpDir.'/*.php'));
            rmdir($tmpDir);
        }
    }
}
