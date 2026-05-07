<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Generator\Filament\FilamentEntryMapper;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Tests\Fixtures\Enums\PostStatus;

class FilamentEntryMapperTest extends TestCase
{
    private FilamentEntryMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new FilamentEntryMapper;
    }

    public function test_string_column_renders_as_text_entry(): void
    {
        $result = $this->mapper->map(new ColumnDefinition(name: 'name', columnType: 'string'), '');

        $this->assertStringContainsString("Infolists\\Components\\TextEntry::make('name')", $result);
    }

    public function test_boolean_column_renders_as_icon_entry(): void
    {
        $result = $this->mapper->map(new ColumnDefinition(name: 'is_active', columnType: 'boolean'), '');

        $this->assertStringContainsString("Infolists\\Components\\IconEntry::make('is_active')", $result);
        $this->assertStringContainsString('->boolean()', $result);
    }

    public function test_date_column_renders_with_date_modifier(): void
    {
        $result = $this->mapper->map(new ColumnDefinition(name: 'published_on', columnType: 'date'), '');

        $this->assertStringContainsString('->date()', $result);
    }

    public function test_datetime_column_renders_with_datetime_modifier(): void
    {
        $result = $this->mapper->map(new ColumnDefinition(name: 'created_at', columnType: 'dateTime'), '');

        $this->assertStringContainsString('->dateTime()', $result);
    }

    public function test_numeric_column_renders_with_numeric_modifier(): void
    {
        $result = $this->mapper->map(new ColumnDefinition(name: 'view_count', columnType: 'integer'), '');

        $this->assertStringContainsString('->numeric()', $result);
    }

    public function test_long_text_column_uses_column_span_full(): void
    {
        $result = $this->mapper->map(new ColumnDefinition(name: 'body', columnType: 'text'), '');

        $this->assertStringContainsString('->columnSpanFull()', $result);
    }

    public function test_json_column_renders_as_badge(): void
    {
        $result = $this->mapper->map(new ColumnDefinition(name: 'tags', columnType: 'json'), '');

        $this->assertStringContainsString('->badge()', $result);
    }

    public function test_uuid_column_is_copyable(): void
    {
        $result = $this->mapper->map(new ColumnDefinition(name: 'external_id', columnType: 'uuid'), '');

        $this->assertStringContainsString('->copyable()', $result);
    }

    public function test_enum_cast_renders_as_badge(): void
    {
        $result = $this->mapper->map(new ColumnDefinition(
            name: 'status',
            columnType: 'string',
            castType: PostStatus::class,
        ), '');

        $this->assertStringContainsString("TextEntry::make('status')", $result);
        $this->assertStringContainsString('->badge()', $result);
    }

    public function test_nullable_column_gets_placeholder(): void
    {
        $result = $this->mapper->map(new ColumnDefinition(
            name: 'nickname',
            columnType: 'string',
            nullable: true,
        ), '');

        $this->assertStringContainsString("->placeholder('—')", $result);
    }

    public function test_non_nullable_column_has_no_placeholder(): void
    {
        $result = $this->mapper->map(new ColumnDefinition(
            name: 'name',
            columnType: 'string',
            nullable: false,
        ), '');

        $this->assertStringNotContainsString('placeholder', $result);
    }

    public function test_entries_do_not_include_sortable_or_searchable(): void
    {
        $result = $this->mapper->map(new ColumnDefinition(name: 'title', columnType: 'string'), '');

        $this->assertStringNotContainsString('->sortable()', $result);
        $this->assertStringNotContainsString('->searchable()', $result);
        $this->assertStringNotContainsString('->toggleable(', $result);
    }

    public function test_enum_entry_does_not_include_sortable(): void
    {
        $result = $this->mapper->map(new ColumnDefinition(
            name: 'status',
            columnType: 'string',
            castType: PostStatus::class,
        ), '');

        $this->assertStringContainsString('->badge()', $result);
        $this->assertStringNotContainsString('->sortable()', $result);
    }
}
