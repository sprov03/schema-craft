<?php

namespace SchemaCraft\Tests\Unit\Generators;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Generator\FakerMethodMapper;
use SchemaCraft\Generators\GeneratorColumn;
use SchemaCraft\Scanner\ColumnDefinition;

class GeneratorColumnTest extends TestCase
{
    // ─── __get proxy ─────────────────────────────────────────────

    public function test_property_access_proxies_to_definition(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'email', columnType: 'string', nullable: true));

        $this->assertSame('email', $col->name);
        $this->assertSame('string', $col->columnType);
        $this->assertTrue($col->nullable);
    }

    // ─── phpType ─────────────────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\DataProvider('phpTypeProvider')]
    public function test_php_type_mapping(string $columnType, string $expectedPhpType): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'col', columnType: $columnType));

        $this->assertSame($expectedPhpType, $col->phpType());
    }

    public static function phpTypeProvider(): array
    {
        return [
            'boolean' => ['boolean', 'bool'],
            'integer' => ['integer', 'int'],
            'bigInteger' => ['bigInteger', 'int'],
            'smallInteger' => ['smallInteger', 'int'],
            'tinyInteger' => ['tinyInteger', 'int'],
            'unsignedBigInteger' => ['unsignedBigInteger', 'int'],
            'unsignedInteger' => ['unsignedInteger', 'int'],
            'unsignedSmallInteger' => ['unsignedSmallInteger', 'int'],
            'unsignedTinyInteger' => ['unsignedTinyInteger', 'int'],
            'decimal' => ['decimal', 'float'],
            'float' => ['float', 'float'],
            'double' => ['double', 'float'],
            'json' => ['json', 'array'],
            'date' => ['date', 'CarbonInterface'],
            'timestamp' => ['timestamp', 'CarbonInterface'],
            'dateTime' => ['dateTime', 'CarbonInterface'],
            'dateTimeTz' => ['dateTimeTz', 'CarbonInterface'],
            'string' => ['string', 'string'],
            'text' => ['text', 'string'],
            'uuid' => ['uuid', 'string'],
        ];
    }

    public function test_php_type_nullable_adds_question_mark(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'name', columnType: 'string', nullable: true));

        $this->assertSame('?string', $col->phpTypeNullable());
    }

    public function test_php_type_nullable_no_prefix_when_not_nullable(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'name', columnType: 'string', nullable: false));

        $this->assertSame('string', $col->phpTypeNullable());
    }

    // ─── Name helpers ─────────────────────────────────────────────

    public function test_camel_name(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'first_name', columnType: 'string'));

        $this->assertSame('firstName', $col->camelName());
    }

    public function test_studly_name(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'first_name', columnType: 'string'));

        $this->assertSame('FirstName', $col->studlyName());
    }

    public function test_human_name(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'first_name', columnType: 'string'));

        $this->assertSame('First Name', $col->humanName());
    }

    // ─── asMethodParam ────────────────────────────────────────────

    public function test_as_method_param_required(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'first_name', columnType: 'string', nullable: false));

        $this->assertSame('string $firstName', $col->asMethodParam());
    }

    public function test_as_method_param_nullable(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'first_name', columnType: 'string', nullable: true));

        $this->assertSame('?string $firstName = null', $col->asMethodParam());
    }

    public function test_as_method_param_int_required(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'age', columnType: 'integer', nullable: false));

        $this->assertSame('int $age', $col->asMethodParam());
    }

    // ─── isFK / relationshipName ──────────────────────────────────

    public function test_is_fk_returns_true_for_id_suffix(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'owner_id', columnType: 'unsignedBigInteger'));

        $this->assertTrue($col->isFK());
    }

    public function test_is_fk_returns_false_without_id_suffix(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'name', columnType: 'string'));

        $this->assertFalse($col->isFK());
    }

    public function test_is_fk_returns_false_for_just_id(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger'));

        $this->assertFalse($col->isFK());
    }

    public function test_relationship_name_strips_id_and_camel_cases(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'owner_id', columnType: 'unsignedBigInteger'));

        $this->assertSame('owner', $col->relationshipName());
    }

    public function test_relationship_name_compound_fk(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'created_by_user_id', columnType: 'unsignedBigInteger'));

        $this->assertSame('createdByUser', $col->relationshipName());
    }

    // ─── asAssignment ─────────────────────────────────────────────

    public function test_as_assignment_regular_column(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'first_name', columnType: 'string'));

        $this->assertSame('$model->first_name = $firstName;', $col->asAssignment());
    }

    public function test_as_assignment_custom_model_var(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'title', columnType: 'string'));

        $this->assertSame('$post->title = $title;', $col->asAssignment('$post'));
    }

    public function test_as_assignment_fk_column_required(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'owner_id', columnType: 'unsignedBigInteger', nullable: false));

        $this->assertSame('$model->owner()->associate($owner);', $col->asAssignment());
    }

    public function test_as_assignment_fk_column_nullable(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'owner_id', columnType: 'unsignedBigInteger', nullable: true));

        $result = $col->asAssignment();

        $this->assertStringContainsString('associate($owner)', $result);
        $this->assertStringContainsString('dissociate()', $result);
    }

    // ─── Mapper delegation ────────────────────────────────────────

    public function test_faker_value_delegates_to_faker_mapper(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'email', columnType: 'string'));
        $expected = (new FakerMethodMapper)->map($col->definition);

        $this->assertSame($expected, $col->fakerValue());
    }

    public function test_faker_value_for_generic_string(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'title', columnType: 'string'));

        $result = $col->fakerValue();

        $this->assertStringContainsString('$faker->', $result);
    }

    public function test_as_filament_field_returns_non_empty_string(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'name', columnType: 'string'));

        $result = $col->asFilamentField();

        $this->assertNotEmpty($result);
        $this->assertStringContainsString('make(', $result);
    }

    public function test_as_filament_column_returns_non_empty_string(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'name', columnType: 'string'));

        $result = $col->asFilamentColumn();

        $this->assertNotEmpty($result);
        $this->assertStringContainsString('make(', $result);
    }

    public function test_as_filament_field_has_no_leading_whitespace(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'status', columnType: 'boolean'));

        $result = $col->asFilamentField();

        $this->assertSame(ltrim($result), $result);
    }

    public function test_as_filament_column_has_no_leading_whitespace(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'status', columnType: 'boolean'));

        $result = $col->asFilamentColumn();

        $this->assertSame(ltrim($result), $result);
    }
}
