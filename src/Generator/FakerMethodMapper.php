<?php

namespace SchemaCraft\Generator;

use BackedEnum;
use SchemaCraft\Scanner\ColumnDefinition;

/**
 * Maps ColumnDefinition properties to Faker method call strings for factory generation.
 */
class FakerMethodMapper
{
    /**
     * Map a column to a faker expression string.
     *
     * Returns a PHP expression string like `$faker->sentence()` or `$faker->unique()->safeEmail()`.
     * The caller is responsible for the `$faker` variable being in scope.
     */
    public function map(ColumnDefinition $column): string
    {
        // Enum casts get special handling
        if ($column->castType !== null && $this->isEnumClass($column->castType)) {
            return $this->enumExpression($column->castType);
        }

        // Name-based heuristics first (more specific)
        $nameResult = $this->mapByName($column);
        if ($nameResult !== null) {
            return $nameResult;
        }

        // Type-based fallback
        return $this->mapByType($column);
    }

    /**
     * Map column based on name heuristics.
     */
    private function mapByName(ColumnDefinition $column): ?string
    {
        $name = strtolower($column->name);
        $unique = $column->unique;

        // Exact matches
        return match ($name) {
            'email', 'email_address' => $unique ? '$faker->unique()->safeEmail()' : '$faker->safeEmail()',
            'name', 'full_name', 'fullname' => '$faker->name()',
            'first_name', 'firstname' => '$faker->firstName()',
            'last_name', 'lastname' => '$faker->lastName()',
            'phone', 'phone_number', 'telephone' => '$faker->phoneNumber()',
            'address', 'street_address' => '$faker->address()',
            'city' => '$faker->city()',
            'state', 'province' => '$faker->state()',
            'country' => '$faker->country()',
            'zip', 'zip_code', 'postal_code', 'postcode' => '$faker->postcode()',
            'url', 'website', 'homepage', 'link' => '$faker->url()',
            'slug' => $unique ? '$faker->unique()->slug()' : '$faker->slug()',
            'title' => '$faker->sentence()',
            'description', 'summary', 'excerpt', 'bio', 'about' => '$faker->paragraph()',
            'username', 'user_name' => $unique ? '$faker->unique()->userName()' : '$faker->userName()',
            'password' => '$faker->password()',
            'ip', 'ip_address' => '$faker->ipv4()',
            'latitude', 'lat' => '$faker->latitude()',
            'longitude', 'lng', 'lon' => '$faker->longitude()',
            'company', 'company_name' => '$faker->company()',
            'color', 'colour' => '$faker->hexColor()',
            'locale', 'language' => '$faker->locale()',
            'currency', 'currency_code' => '$faker->currencyCode()',
            'timezone', 'time_zone' => '$faker->timezone()',
            default => null,
        };
    }

    /**
     * Map column based on column type.
     */
    private function mapByType(ColumnDefinition $column): string
    {
        $unique = $column->unique ? '->unique()' : '';

        return match ($column->columnType) {
            'string' => $this->stringExpression($column, $unique),
            'text', 'mediumText', 'longText' => '$faker->paragraph()',
            'integer', 'bigInteger' => "\$faker{$unique}->randomNumber()",
            'unsignedBigInteger', 'unsignedInteger' => "\$faker{$unique}->numberBetween(1, 10000)",
            'smallInteger', 'unsignedSmallInteger' => "\$faker{$unique}->numberBetween(0, 100)",
            'tinyInteger', 'unsignedTinyInteger' => "\$faker{$unique}->numberBetween(0, 10)",
            'boolean' => '$faker->boolean()',
            'decimal', 'float', 'double' => $this->numericExpression($column),
            'date' => '$faker->date()',
            'timestamp', 'dateTime', 'dateTimeTz' => '$faker->dateTime()',
            'time', 'timeTz' => '$faker->time()',
            'json' => '[]',
            'uuid' => '$faker->uuid()',
            'ulid' => '(string) \Illuminate\Support\Str::ulid()',
            'year' => '$faker->year()',
            default => "\$faker{$unique}->word()",
        };
    }

    private function stringExpression(ColumnDefinition $column, string $unique): string
    {
        $length = $column->length ?? 255;

        if ($length > 100) {
            return "\$faker{$unique}->sentence()";
        }

        return "\$faker{$unique}->word()";
    }

    private function numericExpression(ColumnDefinition $column): string
    {
        $scale = $column->scale ?? 2;

        return "\$faker->randomFloat({$scale}, 0, 1000)";
    }

    private function enumExpression(string $castType): string
    {
        $shortClass = class_basename($castType);

        return "\$faker->randomElement({$shortClass}::cases())";
    }

    private function isEnumClass(string $castType): bool
    {
        return class_exists($castType) && is_subclass_of($castType, BackedEnum::class);
    }
}
