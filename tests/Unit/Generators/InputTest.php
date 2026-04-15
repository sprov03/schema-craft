<?php

namespace SchemaCraft\Tests\Unit\Generators;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Generators\Input;
use SchemaCraft\Generators\InputDefinition;

class InputTest extends TestCase
{
    public function test_text_creates_correct_definition(): void
    {
        $input = Input::text('class_name', 'Class Name');

        $this->assertInstanceOf(InputDefinition::class, $input);
        $this->assertSame('class_name', $input->key);
        $this->assertSame('Class Name', $input->label);
        $this->assertSame('text', $input->type);
        $this->assertNull($input->default);
        $this->assertSame([], $input->options);
        $this->assertNull($input->selectorKey);
    }

    public function test_select_creates_correct_definition(): void
    {
        $options = ['line' => 'Line', 'bar' => 'Bar'];
        $input = Input::select('chart_type', 'Chart Type', $options);

        $this->assertSame('select', $input->type);
        $this->assertSame($options, $input->options);
        $this->assertNull($input->default);
        $this->assertNull($input->selectorKey);
    }

    public function test_boolean_creates_correct_definition(): void
    {
        $input = Input::boolean('with_auth', 'Include Auth');

        $this->assertSame('boolean', $input->type);
        $this->assertFalse($input->default);
    }

    public function test_boolean_with_true_default(): void
    {
        $input = Input::boolean('with_auth', 'Include Auth', true);

        $this->assertTrue($input->default);
    }

    public function test_schema_selector_creates_correct_definition(): void
    {
        $input = Input::schemaSelector('schema', 'Schema');

        $this->assertSame('schemaSelector', $input->type);
        $this->assertTrue($input->selectColumns);
        $this->assertFalse($input->selectRelationships);
    }

    public function test_schema_selector_with_relationships(): void
    {
        $input = Input::schemaSelector('schema', 'Schema', selectColumns: true, selectRelationships: true);

        $this->assertTrue($input->selectColumns);
        $this->assertTrue($input->selectRelationships);
    }

    public function test_schema_selector_without_columns(): void
    {
        $input = Input::schemaSelector('schema', 'Schema', selectColumns: false);

        $this->assertFalse($input->selectColumns);
    }

    public function test_schema_column_defaults_to_schema_selector_key(): void
    {
        $input = Input::schemaColumn('value_col', 'Value Column');

        $this->assertSame('schemaColumn', $input->type);
        $this->assertSame('schema', $input->selectorKey);
    }

    public function test_schema_column_with_custom_selector_key(): void
    {
        $input = Input::schemaColumn('related_col', 'Related Column', 'relatedSchema');

        $this->assertSame('relatedSchema', $input->selectorKey);
    }

    public function test_schema_columns_defaults_to_schema_selector_key(): void
    {
        $input = Input::schemaColumns('selected_cols', 'Selected Columns');

        $this->assertSame('schemaColumns', $input->type);
        $this->assertSame('schema', $input->selectorKey);
    }

    public function test_schema_columns_with_custom_selector_key(): void
    {
        $input = Input::schemaColumns('related_cols', 'Related Columns', 'relatedSchema');

        $this->assertSame('relatedSchema', $input->selectorKey);
    }

    public function test_select_resource_directory_creates_correct_definition(): void
    {
        $input = Input::selectResourceDirectory('resource_dir', 'Resource Directory');

        $this->assertSame('selectResourceDirectory', $input->type);
        $this->assertSame('resource_dir', $input->key);
    }
}
