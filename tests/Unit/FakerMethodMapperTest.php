<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Generator\FakerMethodMapper;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Tests\Fixtures\Enums\PostStatus;

class FakerMethodMapperTest extends TestCase
{
    private FakerMethodMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new FakerMethodMapper;
    }

    // ─── Name-based heuristics ──────────────────────────────────

    public function test_email_column_maps_to_safe_email(): void
    {
        $column = new ColumnDefinition(name: 'email', columnType: 'string');

        $this->assertEquals('$faker->safeEmail()', $this->mapper->map($column));
    }

    public function test_unique_email_column_maps_to_unique_safe_email(): void
    {
        $column = new ColumnDefinition(name: 'email', columnType: 'string', unique: true);

        $this->assertEquals('$faker->unique()->safeEmail()', $this->mapper->map($column));
    }

    public function test_name_column_maps_to_name(): void
    {
        $column = new ColumnDefinition(name: 'name', columnType: 'string');

        $this->assertEquals('$faker->name()', $this->mapper->map($column));
    }

    public function test_first_name_column(): void
    {
        $column = new ColumnDefinition(name: 'first_name', columnType: 'string');

        $this->assertEquals('$faker->firstName()', $this->mapper->map($column));
    }

    public function test_last_name_column(): void
    {
        $column = new ColumnDefinition(name: 'last_name', columnType: 'string');

        $this->assertEquals('$faker->lastName()', $this->mapper->map($column));
    }

    public function test_phone_column(): void
    {
        $column = new ColumnDefinition(name: 'phone', columnType: 'string');

        $this->assertEquals('$faker->phoneNumber()', $this->mapper->map($column));
    }

    public function test_url_column(): void
    {
        $column = new ColumnDefinition(name: 'url', columnType: 'string');

        $this->assertEquals('$faker->url()', $this->mapper->map($column));
    }

    public function test_slug_column(): void
    {
        $column = new ColumnDefinition(name: 'slug', columnType: 'string');

        $this->assertEquals('$faker->slug()', $this->mapper->map($column));
    }

    public function test_unique_slug_column(): void
    {
        $column = new ColumnDefinition(name: 'slug', columnType: 'string', unique: true);

        $this->assertEquals('$faker->unique()->slug()', $this->mapper->map($column));
    }

    public function test_title_column_maps_to_sentence(): void
    {
        $column = new ColumnDefinition(name: 'title', columnType: 'string');

        $this->assertEquals('$faker->sentence()', $this->mapper->map($column));
    }

    public function test_description_column_maps_to_paragraph(): void
    {
        $column = new ColumnDefinition(name: 'description', columnType: 'string');

        $this->assertEquals('$faker->paragraph()', $this->mapper->map($column));
    }

    public function test_username_column(): void
    {
        $column = new ColumnDefinition(name: 'username', columnType: 'string');

        $this->assertEquals('$faker->userName()', $this->mapper->map($column));
    }

    public function test_unique_username_column(): void
    {
        $column = new ColumnDefinition(name: 'username', columnType: 'string', unique: true);

        $this->assertEquals('$faker->unique()->userName()', $this->mapper->map($column));
    }

    public function test_city_column(): void
    {
        $column = new ColumnDefinition(name: 'city', columnType: 'string');

        $this->assertEquals('$faker->city()', $this->mapper->map($column));
    }

    public function test_country_column(): void
    {
        $column = new ColumnDefinition(name: 'country', columnType: 'string');

        $this->assertEquals('$faker->country()', $this->mapper->map($column));
    }

    public function test_company_column(): void
    {
        $column = new ColumnDefinition(name: 'company', columnType: 'string');

        $this->assertEquals('$faker->company()', $this->mapper->map($column));
    }

    // ─── Type-based mappings ────────────────────────────────────

    public function test_short_string_column(): void
    {
        $column = new ColumnDefinition(name: 'code', columnType: 'string', length: 50);

        $this->assertEquals('$faker->word()', $this->mapper->map($column));
    }

    public function test_long_string_column(): void
    {
        $column = new ColumnDefinition(name: 'subtitle', columnType: 'string', length: 255);

        $this->assertEquals('$faker->sentence()', $this->mapper->map($column));
    }

    public function test_text_column(): void
    {
        $column = new ColumnDefinition(name: 'body', columnType: 'text');

        $this->assertEquals('$faker->paragraph()', $this->mapper->map($column));
    }

    public function test_medium_text_column(): void
    {
        $column = new ColumnDefinition(name: 'content', columnType: 'mediumText');

        $this->assertEquals('$faker->paragraph()', $this->mapper->map($column));
    }

    public function test_long_text_column(): void
    {
        $column = new ColumnDefinition(name: 'html', columnType: 'longText');

        $this->assertEquals('$faker->paragraph()', $this->mapper->map($column));
    }

    public function test_integer_column(): void
    {
        $column = new ColumnDefinition(name: 'count', columnType: 'integer');

        $this->assertEquals('$faker->randomNumber()', $this->mapper->map($column));
    }

    public function test_big_integer_column(): void
    {
        $column = new ColumnDefinition(name: 'total', columnType: 'bigInteger');

        $this->assertEquals('$faker->randomNumber()', $this->mapper->map($column));
    }

    public function test_unsigned_big_integer_column(): void
    {
        $column = new ColumnDefinition(name: 'views', columnType: 'unsignedBigInteger');

        $this->assertEquals('$faker->numberBetween(1, 10000)', $this->mapper->map($column));
    }

    public function test_unsigned_integer_column(): void
    {
        $column = new ColumnDefinition(name: 'rank', columnType: 'unsignedInteger');

        $this->assertEquals('$faker->numberBetween(1, 10000)', $this->mapper->map($column));
    }

    public function test_small_integer_column(): void
    {
        $column = new ColumnDefinition(name: 'level', columnType: 'smallInteger');

        $this->assertEquals('$faker->numberBetween(0, 100)', $this->mapper->map($column));
    }

    public function test_tiny_integer_column(): void
    {
        $column = new ColumnDefinition(name: 'priority', columnType: 'tinyInteger');

        $this->assertEquals('$faker->numberBetween(0, 10)', $this->mapper->map($column));
    }

    public function test_boolean_column(): void
    {
        $column = new ColumnDefinition(name: 'is_active', columnType: 'boolean');

        $this->assertEquals('$faker->boolean()', $this->mapper->map($column));
    }

    public function test_decimal_column_with_scale(): void
    {
        $column = new ColumnDefinition(name: 'price', columnType: 'decimal', scale: 2);

        $this->assertEquals('$faker->randomFloat(2, 0, 1000)', $this->mapper->map($column));
    }

    public function test_decimal_column_with_custom_scale(): void
    {
        $column = new ColumnDefinition(name: 'precision_value', columnType: 'decimal', scale: 6);

        $this->assertEquals('$faker->randomFloat(6, 0, 1000)', $this->mapper->map($column));
    }

    public function test_float_column(): void
    {
        $column = new ColumnDefinition(name: 'rating', columnType: 'float');

        $this->assertEquals('$faker->randomFloat(2, 0, 1000)', $this->mapper->map($column));
    }

    public function test_double_column(): void
    {
        $column = new ColumnDefinition(name: 'score', columnType: 'double');

        $this->assertEquals('$faker->randomFloat(2, 0, 1000)', $this->mapper->map($column));
    }

    public function test_date_column(): void
    {
        $column = new ColumnDefinition(name: 'birthday', columnType: 'date');

        $this->assertEquals('$faker->date()', $this->mapper->map($column));
    }

    public function test_timestamp_column(): void
    {
        $column = new ColumnDefinition(name: 'published_at', columnType: 'timestamp');

        $this->assertEquals('$faker->dateTime()', $this->mapper->map($column));
    }

    public function test_datetime_column(): void
    {
        $column = new ColumnDefinition(name: 'starts_at', columnType: 'dateTime');

        $this->assertEquals('$faker->dateTime()', $this->mapper->map($column));
    }

    public function test_time_column(): void
    {
        $column = new ColumnDefinition(name: 'starts_at', columnType: 'time');

        $this->assertEquals('$faker->time()', $this->mapper->map($column));
    }

    public function test_json_column(): void
    {
        $column = new ColumnDefinition(name: 'metadata', columnType: 'json');

        $this->assertEquals('[]', $this->mapper->map($column));
    }

    public function test_uuid_column(): void
    {
        $column = new ColumnDefinition(name: 'external_id', columnType: 'uuid');

        $this->assertEquals('$faker->uuid()', $this->mapper->map($column));
    }

    public function test_ulid_column(): void
    {
        $column = new ColumnDefinition(name: 'ref_id', columnType: 'ulid');

        $this->assertEquals('(string) \Illuminate\Support\Str::ulid()', $this->mapper->map($column));
    }

    public function test_year_column(): void
    {
        $column = new ColumnDefinition(name: 'graduation_year', columnType: 'year');

        $this->assertEquals('$faker->year()', $this->mapper->map($column));
    }

    // ─── Unique modifier on type-based columns ──────────────────

    public function test_unique_integer_column(): void
    {
        $column = new ColumnDefinition(name: 'code', columnType: 'integer', unique: true);

        $this->assertEquals('$faker->unique()->randomNumber()', $this->mapper->map($column));
    }

    public function test_unique_string_column_falls_back_to_type(): void
    {
        $column = new ColumnDefinition(name: 'sku', columnType: 'string', length: 50, unique: true);

        $this->assertEquals('$faker->unique()->word()', $this->mapper->map($column));
    }

    // ─── Enum cast ──────────────────────────────────────────────

    public function test_enum_cast_column(): void
    {
        $column = new ColumnDefinition(
            name: 'status',
            columnType: 'string',
            castType: PostStatus::class,
        );

        $this->assertEquals('$faker->randomElement(PostStatus::cases())', $this->mapper->map($column));
    }

    // ─── Name heuristic takes priority over type ────────────────

    public function test_name_heuristic_beats_type_mapping(): void
    {
        // 'email' column with string type should use name heuristic (safeEmail),
        // not type-based mapping (sentence for length > 100)
        $column = new ColumnDefinition(name: 'email', columnType: 'string', length: 255);

        $this->assertEquals('$faker->safeEmail()', $this->mapper->map($column));
    }
}
