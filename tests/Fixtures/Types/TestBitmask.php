<?php

namespace SchemaCraft\Tests\Fixtures\Types;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use SchemaCraft\Contracts\SchemaCraftType;

class TestBitmask implements CastsAttributes, SchemaCraftType
{
    protected int $value;

    public function __construct(int $value = 0)
    {
        $this->value = $value;
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): ?static
    {
        return $value === null ? null : new static((int) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value instanceof static ? $value->value : $value;
    }

    public static function schemaColumnType(): string
    {
        return 'mediumInteger';
    }

    public static function schemaColumnModifiers(): array
    {
        return ['unsigned' => true];
    }

    public static function schemaValidationRules(): array
    {
        return ['integer', 'min:0'];
    }
}
