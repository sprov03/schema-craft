<?php

namespace SchemaCraft\Tests\Unit\Generators;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Generator\FakerMethodMapper;
use SchemaCraft\Generators\GeneratorColumn;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Tests\Fixtures\Casts\RenderableCast;
use SchemaCraft\Tests\Fixtures\Enums\PostStatus;

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

    public function test_is_fk_returns_true_for_id_suffix_no_cast(): void
    {
        // No castType — naming convention alone is enough.
        $col = new GeneratorColumn(new ColumnDefinition(name: 'owner_id', columnType: 'unsignedBigInteger'));

        $this->assertTrue($col->isFK());
    }

    public function test_is_fk_returns_true_for_id_suffix_with_integer_cast(): void
    {
        // Explicit integer cast is consistent with FK semantics — still a FK.
        $col = new GeneratorColumn(new ColumnDefinition(name: 'owner_id', columnType: 'unsignedBigInteger', castType: 'integer'));

        $this->assertTrue($col->isFK());
    }

    public function test_is_fk_returns_false_when_schema_documents_enum_cast(): void
    {
        // Explicit enum cast overrides the _id naming convention — NOT a FK.
        // The schema documentation is the source of truth.
        $col = new GeneratorColumn(new ColumnDefinition(name: 'loan_type_id', columnType: 'string', castType: 'App\Enums\LoanTypeEnum'));

        $this->assertFalse($col->isFK());
    }

    public function test_is_fk_returns_false_when_schema_documents_custom_type_cast(): void
    {
        // Any FQCN cast (DTO, custom type) also overrides the _id naming convention.
        $col = new GeneratorColumn(new ColumnDefinition(name: 'record_id', columnType: 'string', castType: 'App\Casts\CustomDto'));

        $this->assertFalse($col->isFK());
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

    // ─── isPrimary / isTimestamp / isSoftDelete ───────────────────

    public function test_is_primary_returns_true_for_primary_column(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'id', columnType: 'unsignedBigInteger', primary: true));

        $this->assertTrue($col->isPrimary());
    }

    public function test_is_primary_returns_false_for_non_primary_column(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'name', columnType: 'string'));

        $this->assertFalse($col->isPrimary());
    }

    public function test_is_timestamp_returns_true_for_created_at(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'created_at', columnType: 'timestamp'));

        $this->assertTrue($col->isTimestamp());
    }

    public function test_is_timestamp_returns_true_for_updated_at(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'updated_at', columnType: 'timestamp'));

        $this->assertTrue($col->isTimestamp());
    }

    public function test_is_timestamp_returns_false_for_other_columns(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'published_at', columnType: 'timestamp'));

        $this->assertFalse($col->isTimestamp());
    }

    public function test_is_timestamp_returns_false_for_deleted_at(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'deleted_at', columnType: 'timestamp'));

        $this->assertFalse($col->isTimestamp());
    }

    public function test_is_soft_delete_returns_true_for_deleted_at(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'deleted_at', columnType: 'timestamp'));

        $this->assertTrue($col->isSoftDelete());
    }

    public function test_is_soft_delete_returns_false_for_other_columns(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'created_at', columnType: 'timestamp'));

        $this->assertFalse($col->isSoftDelete());
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

    // ─── Enum detection ───────────────────────────────────────────

    public function test_is_enum_true_for_backed_enum_cast(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(
            name: 'status',
            columnType: 'string',
            castType: PostStatus::class,
        ));

        $this->assertTrue($col->isEnum());
        $this->assertSame(PostStatus::class, $col->enumClass());
    }

    public function test_is_enum_false_for_null_cast(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'status', columnType: 'string'));

        $this->assertFalse($col->isEnum());
        $this->assertNull($col->enumClass());
    }

    public function test_is_enum_false_for_builtin_cast(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(
            name: 'data',
            columnType: 'json',
            castType: 'array',
        ));

        $this->assertFalse($col->isEnum());
        $this->assertNull($col->enumClass());
    }

    public function test_is_enum_false_for_non_enum_cast_class(): void
    {
        // RenderableCast is a real class but not a BackedEnum.
        $col = new GeneratorColumn(new ColumnDefinition(
            name: 'custom',
            columnType: 'json',
            castType: RenderableCast::class,
        ));

        $this->assertFalse($col->isEnum());
    }

    // ─── FilamentRenderable dispatch ──────────────────────────────

    public function test_as_filament_column_delegates_to_filament_renderable_cast(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(
            name: 'widgets',
            columnType: 'json',
            castType: RenderableCast::class,
        ));

        $this->assertSame("CustomColumn::make('widgets')->customised()", $col->asFilamentColumn());
    }

    public function test_as_filament_entry_delegates_to_filament_renderable_cast(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(
            name: 'widgets',
            columnType: 'json',
            castType: RenderableCast::class,
        ));

        $this->assertSame("CustomEntry::make('widgets')->customised()", $col->asFilamentEntry());
    }

    public function test_as_filament_field_delegates_to_filament_renderable_cast(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(
            name: 'widgets',
            columnType: 'json',
            castType: RenderableCast::class,
        ));

        $this->assertSame("CustomField::make('widgets')->customised()", $col->asFilamentField());
    }

    // ─── asFilamentEntry fallback to mapper ───────────────────────

    public function test_as_filament_entry_returns_non_empty_string(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'name', columnType: 'string'));

        $result = $col->asFilamentEntry();

        $this->assertNotEmpty($result);
        $this->assertStringContainsString('make(', $result);
    }

    public function test_as_filament_entry_has_no_leading_whitespace(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'name', columnType: 'string'));

        $result = $col->asFilamentEntry();

        $this->assertSame(ltrim($result), $result);
    }

    public function test_as_filament_entry_uses_text_entry_namespace(): void
    {
        $col = new GeneratorColumn(new ColumnDefinition(name: 'name', columnType: 'string'));

        $this->assertStringContainsString('Infolists\\Components\\TextEntry', $col->asFilamentEntry());
    }
}
