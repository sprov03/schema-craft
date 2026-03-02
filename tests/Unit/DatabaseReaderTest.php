<?php

namespace SchemaCraft\Tests\Unit;

use Illuminate\Support\Facades\Schema;
use SchemaCraft\Migration\DatabaseReader;
use SchemaCraft\Tests\TestCase;

class DatabaseReaderTest extends TestCase
{
    private DatabaseReader $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reader = new DatabaseReader;
    }

    public function test_returns_null_for_non_existent_table(): void
    {
        $result = $this->reader->read('non_existent_table');

        $this->assertNull($result);
    }

    public function test_reads_basic_table_structure(): void
    {
        Schema::create('test_tags', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        $state = $this->reader->read('test_tags');

        $this->assertNotNull($state);
        $this->assertSame('test_tags', $state->tableName);
        $this->assertNotEmpty($state->columns);

        $idColumn = $state->getColumn('id');
        $this->assertNotNull($idColumn);
        $this->assertTrue($idColumn->autoIncrement);
        $this->assertTrue($idColumn->primary);

        $nameColumn = $state->getColumn('name');
        $this->assertNotNull($nameColumn);
        $this->assertSame('string', $nameColumn->type);
    }

    public function test_reads_nullable_column(): void
    {
        Schema::create('test_nullable', function ($table) {
            $table->id();
            $table->string('bio')->nullable();
        });

        $state = $this->reader->read('test_nullable');
        $bioColumn = $state->getColumn('bio');

        $this->assertNotNull($bioColumn);
        $this->assertTrue($bioColumn->nullable);
    }

    public function test_reads_timestamp_columns(): void
    {
        Schema::create('test_timestamps', function ($table) {
            $table->id();
            $table->timestamps();
        });

        $state = $this->reader->read('test_timestamps');

        $this->assertTrue($state->hasTimestamps());
        $this->assertNotNull($state->getColumn('created_at'));
        $this->assertNotNull($state->getColumn('updated_at'));
    }

    public function test_detects_absence_of_timestamps(): void
    {
        Schema::create('test_no_timestamps', function ($table) {
            $table->id();
            $table->string('name');
        });

        $state = $this->reader->read('test_no_timestamps');

        $this->assertFalse($state->hasTimestamps());
    }

    public function test_reads_indexes(): void
    {
        Schema::create('test_indexes', function ($table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name')->index();
        });

        $state = $this->reader->read('test_indexes');

        $this->assertNotEmpty($state->indexes);

        // Find the unique index on slug
        $slugIndex = $state->getIndex(['slug']);
        $this->assertNotNull($slugIndex);
        $this->assertTrue($slugIndex->unique);
    }

    public function test_reads_foreign_keys(): void
    {
        Schema::create('test_users', function ($table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('test_posts', function ($table) {
            $table->id();
            $table->foreignId('user_id')->constrained('test_users');
            $table->string('title');
        });

        $state = $this->reader->read('test_posts');

        $fk = $state->getForeignKey('user_id');
        $this->assertNotNull($fk);
        $this->assertSame('test_users', $fk->foreignTable);
        $this->assertSame('id', $fk->foreignColumn);
    }

    public function test_reads_soft_deletes_column(): void
    {
        Schema::create('test_soft_deletes', function ($table) {
            $table->id();
            $table->softDeletes();
        });

        $state = $this->reader->read('test_soft_deletes');

        $this->assertTrue($state->hasSoftDeletes());
        $deletedAt = $state->getColumn('deleted_at');
        $this->assertNotNull($deletedAt);
        $this->assertTrue($deletedAt->nullable);
    }

    public function test_lists_all_tables(): void
    {
        Schema::create('test_alpha', function ($table) {
            $table->id();
        });

        Schema::create('test_beta', function ($table) {
            $table->id();
        });

        $tables = $this->reader->tables();

        $this->assertContains('test_alpha', $tables);
        $this->assertContains('test_beta', $tables);
    }

    public function test_reads_text_column_type(): void
    {
        Schema::create('test_text', function ($table) {
            $table->id();
            $table->text('body');
        });

        $state = $this->reader->read('test_text');
        $bodyColumn = $state->getColumn('body');

        $this->assertNotNull($bodyColumn);
        $this->assertSame('text', $bodyColumn->type);
    }

    public function test_reads_boolean_column(): void
    {
        Schema::create('test_boolean', function ($table) {
            $table->id();
            $table->boolean('is_active');
        });

        $state = $this->reader->read('test_boolean');
        $column = $state->getColumn('is_active');

        $this->assertNotNull($column);
        // SQLite stores boolean as integer, but normalization should handle it
        $this->assertContains($column->type, ['boolean', 'integer', 'tinyInteger']);
    }

    public function test_reads_json_column(): void
    {
        Schema::create('test_json', function ($table) {
            $table->id();
            $table->json('metadata');
        });

        $state = $this->reader->read('test_json');
        $column = $state->getColumn('metadata');

        $this->assertNotNull($column);
        // SQLite stores json as text, but some drivers normalize it
        $this->assertContains($column->type, ['json', 'text']);
    }

    public function test_reads_decimal_column(): void
    {
        Schema::create('test_decimal', function ($table) {
            $table->id();
            $table->decimal('price', 10, 2);
        });

        $state = $this->reader->read('test_decimal');
        $column = $state->getColumn('price');

        $this->assertNotNull($column);
    }

    public function test_reads_column_with_default(): void
    {
        Schema::create('test_defaults', function ($table) {
            $table->id();
            $table->string('status')->default('draft');
        });

        $state = $this->reader->read('test_defaults');
        $column = $state->getColumn('status');

        $this->assertNotNull($column);
        $this->assertTrue($column->hasDefault);
        $this->assertSame('draft', $column->default);
    }

    public function test_reads_multiple_columns_correctly(): void
    {
        Schema::create('test_multi', function ($table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->integer('views')->default(0);
            $table->timestamps();
        });

        $state = $this->reader->read('test_multi');

        $this->assertNotNull($state->getColumn('id'));
        $this->assertNotNull($state->getColumn('title'));
        $this->assertNotNull($state->getColumn('body'));
        $this->assertNotNull($state->getColumn('views'));
        $this->assertNotNull($state->getColumn('created_at'));
        $this->assertNotNull($state->getColumn('updated_at'));
        $this->assertCount(6, $state->columns);
    }
}
