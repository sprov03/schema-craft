<?php

namespace SchemaCraft\Tests\Fixtures\Casts;

use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

class AddressData implements Castable
{
    public function __construct(
        public string $street = '',
        public string $city = '',
        public string $zip = '',
    ) {}

    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class implements CastsAttributes
        {
            public function get(Model $model, string $key, mixed $value, array $attributes): ?AddressData
            {
                if ($value === null) {
                    return null;
                }

                $data = json_decode($value, true);

                return new AddressData(
                    street: $data['street'] ?? '',
                    city: $data['city'] ?? '',
                    zip: $data['zip'] ?? '',
                );
            }

            public function set(Model $model, string $key, mixed $value, array $attributes): ?string
            {
                if ($value === null) {
                    return null;
                }

                return json_encode([
                    'street' => $value->street,
                    'city' => $value->city,
                    'zip' => $value->zip,
                ]);
            }
        };
    }
}
