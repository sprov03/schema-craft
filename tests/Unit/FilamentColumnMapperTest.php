<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Generator\Filament\FilamentColumnMapper;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Tests\Fixtures\Enums\PostStatus;

class FilamentColumnMapperTest extends TestCase
{
    private FilamentColumnMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new FilamentColumnMapper;
    }

    public function test_string_column_renders_as_searchable_sortable_text_column(): void
    {
        $result = $this->mapper->map(new ColumnDefinition(name: 'name', columnType: 'string'), '');

        $this->assertStringContainsString("Tables\\Columns\\TextColumn::make('name')", $result);
        $this->assertStringContainsString('->searchable()', $result);
        $this->assertStringContainsString('->sortable()', $result);
    }

    public function test_numeric_column_renders_with_numeric_and_sortable(): void
    {
        $result = $this->mapper->map(new ColumnDefinition(name: 'view_count', columnType: 'integer'), '');

        $this->assertStringContainsString('->numeric()', $result);
        $this->assertStringContainsString('->sortable()', $result);
    }

    public function test_boolean_column_renders_as_icon_column(): void
    {
        $result = $this->mapper->map(new ColumnDefinition(name: 'is_active', columnType: 'boolean'), '');

        $this->assertStringContainsString("Tables\\Columns\\IconColumn::make('is_active')", $result);
        $this->assertStringContainsString('->boolean()', $result);
    }

    public function test_date_column_renders_with_date_and_sortable(): void
    {
        $result = $this->mapper->map(new ColumnDefinition(name: 'published_on', columnType: 'date'), '');

        $this->assertStringContainsString('->date()', $result);
        $this->assertStringContainsString('->sortable()', $result);
    }

    public function test_datetime_column_renders_with_datetime_and_sortable(): void
    {
        $result = $this->mapper->map(new ColumnDefinition(name: 'created_at', columnType: 'dateTime'), '');

        $this->assertStringContainsString('->dateTime()', $result);
        $this->assertStringContainsString('->sortable()', $result);
    }

    public function test_enum_cast_renders_as_badge_with_sortable(): void
    {
        $result = $this->mapper->map(new ColumnDefinition(
            name: 'status',
            columnType: 'string',
            castType: PostStatus::class,
        ), '');

        $this->assertStringContainsString("Tables\\Columns\\TextColumn::make('status')", $result);
        $this->assertStringContainsString('->badge()', $result);
        $this->assertStringContainsString('->sortable()', $result);
    }

    public function test_json_column_renders_as_badge_without_sortable(): void
    {
        $result = $this->mapper->map(new ColumnDefinition(name: 'tags', columnType: 'json'), '');

        $this->assertStringContainsString('->badge()', $result);
        $this->assertStringNotContainsString('->sortable()', $result);
    }

    public function test_uuid_column_is_copyable(): void
    {
        $result = $this->mapper->map(new ColumnDefinition(name: 'external_id', columnType: 'uuid'), '');

        $this->assertStringContainsString('->copyable()', $result);
    }

    public function test_timestamp_column_has_toggleable_hidden_by_default(): void
    {
        $result = $this->mapper->mapTimestamp('created_at', '');

        $this->assertStringContainsString("TextColumn::make('created_at')", $result);
        $this->assertStringContainsString('->dateTime()', $result);
        $this->assertStringContainsString('->sortable()', $result);
        $this->assertStringContainsString('->toggleable(isToggledHiddenByDefault: true)', $result);
    }
}
