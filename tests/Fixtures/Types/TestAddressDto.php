<?php

namespace SchemaCraft\Tests\Fixtures\Types;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use SchemaCraft\Contracts\SchemaCraftType;
use SchemaCraft\DataSchema;

class TestAddressDto extends DataSchema implements CastsAttributes, SchemaCraftType
{
    public string $street;

    public ?string $line2;

    public string $city;

    public string $state;

    public int $zip;

    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        return $value === null ? null : json_decode($value, true);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value === null ? null : json_encode($value);
    }

    public static function schemaColumnType(): string
    {
        return 'json';
    }

    public static function schemaColumnModifiers(): array
    {
        return [];
    }

    public static function schemaValidationRules(): array
    {
        return static::validationRules();
    }
}
