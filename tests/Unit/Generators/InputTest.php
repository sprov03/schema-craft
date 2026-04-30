<?php

namespace SchemaCraft\Tests\Unit\Generators;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Generators\Input;
use SchemaCraft\Generators\InputDefinition;

class InputTest extends TestCase
{
    public function test_text_creates_correct_definition(): void
    {
        $input = Input::text('Class Name');

        $this->assertInstanceOf(InputDefinition::class, $input);
        $this->assertSame('Class Name', $input->label);
        $this->assertSame('text', $input->type);
        $this->assertNull($input->default);
        $this->assertSame([], $input->options);
        $this->assertNull($input->selectorKey);
    }

    public function test_text_fluent_default(): void
    {
        $input = Input::text('Class Name')->default('PostService');

        $this->assertSame('PostService', $input->default);
    }

    public function test_text_fluent_description(): void
    {
        $input = Input::text('Class Name')->description('Used as the file name.');

        $this->assertSame('Used as the file name.', $input->description);
    }

    public function test_text_fluent_help(): void
    {
        $input = Input::text('Class Name')->help('Some help text.');

        $this->assertSame('Some help text.', $input->help);
    }

    public function test_select_creates_correct_definition(): void
    {
        $options = ['line' => 'Line', 'bar' => 'Bar'];
        $input = Input::select('Chart Type', $options);

        $this->assertSame('select', $input->type);
        $this->assertSame('Chart Type', $input->label);
        $this->assertSame($options, $input->options);
        $this->assertNull($input->default);
        $this->assertNull($input->selectorKey);
    }

    public function test_boolean_creates_correct_definition(): void
    {
        $input = Input::boolean('Include Auth');

        $this->assertSame('boolean', $input->type);
        $this->assertFalse($input->default);
    }

    public function test_boolean_with_true_default(): void
    {
        $input = Input::boolean('Include Auth', true);

        $this->assertTrue($input->default);
    }

    public function test_schema_selector_creates_correct_definition(): void
    {
        $input = Input::schemaSelector('Schema');

        $this->assertSame('schemaSelector', $input->type);
        $this->assertSame('Schema', $input->label);
        $this->assertFalse($input->selectColumns);
        $this->assertFalse($input->selectRelationships);
    }

    public function test_schema_selector_with_columns(): void
    {
        $input = Input::schemaSelector('Schema', selectColumns: true);

        $this->assertTrue($input->selectColumns);
        $this->assertFalse($input->selectRelationships);
    }

    public function test_schema_selector_with_relationships(): void
    {
        $input = Input::schemaSelector('Schema', selectColumns: true, selectRelationships: true);

        $this->assertTrue($input->selectColumns);
        $this->assertTrue($input->selectRelationships);
    }

    public function test_schema_column_defaults_to_schema_selector_key(): void
    {
        $input = Input::schemaColumn('Value Column');

        $this->assertSame('schemaColumn', $input->type);
        $this->assertSame('schema', $input->selectorKey);
    }

    public function test_schema_column_with_custom_selector_key(): void
    {
        $input = Input::schemaColumn('Related Column', 'relatedSchema');

        $this->assertSame('relatedSchema', $input->selectorKey);
    }

    public function test_schema_columns_defaults_to_schema_selector_key(): void
    {
        $input = Input::schemaColumns('Selected Columns');

        $this->assertSame('schemaColumns', $input->type);
        $this->assertSame('schema', $input->selectorKey);
    }

    public function test_schema_columns_with_custom_selector_key(): void
    {
        $input = Input::schemaColumns('Related Columns', 'relatedSchema');

        $this->assertSame('relatedSchema', $input->selectorKey);
    }

    public function test_schema_field_picker_creates_correct_definition(): void
    {
        $input = Input::schemaFieldPicker('Fields');

        $this->assertSame('schemaFieldPicker', $input->type);
        $this->assertSame('Fields', $input->label);
        $this->assertSame('schema', $input->selectorKey);
    }

    public function test_schema_field_picker_with_custom_selector_key(): void
    {
        $input = Input::schemaFieldPicker('Fields', 'relatedSchema');

        $this->assertSame('relatedSchema', $input->selectorKey);
    }

    public function test_select_resource_directory_creates_correct_definition(): void
    {
        $input = Input::selectResourceDirectory('Resource Directory');

        $this->assertSame('selectResourceDirectory', $input->type);
        $this->assertSame('Resource Directory', $input->label);
    }

    public function test_filament_placements_creates_correct_definition(): void
    {
        $input = Input::filamentPlacements('Wire Up To');

        $this->assertSame('filamentPlacements', $input->type);
        $this->assertSame('Wire Up To', $input->label);
    }

    public function test_computed_creates_correct_definition(): void
    {
        $input = Input::computed('app/Actions/Post');

        $this->assertSame('computed', $input->type);
        $this->assertSame('app/Actions/Post', $input->computedValue);
        $this->assertSame('', $input->label);
    }

    public function test_computed_with_label_and_description(): void
    {
        $input = Input::computed('app/Actions/Post')
            ->description('Where the file will be written.');

        $this->assertSame('Where the file will be written.', $input->description);
    }

    public function test_custom_creates_correct_definition(): void
    {
        $input = Input::custom('myType', 'My Label', extra: ['foo' => 'bar'], default: 'baz');

        $this->assertSame('myType', $input->type);
        $this->assertSame('My Label', $input->label);
        $this->assertSame(['foo' => 'bar'], $input->extra);
        $this->assertSame('baz', $input->default);
    }
}
