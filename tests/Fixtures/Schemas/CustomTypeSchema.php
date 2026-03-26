<?php

namespace SchemaCraft\Tests\Fixtures\Schemas;

use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\DefaultValue;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Schema;
use SchemaCraft\Tests\Fixtures\Types\TestAddressDto;
use SchemaCraft\Tests\Fixtures\Types\TestBitmask;
use SchemaCraft\Tests\Fixtures\Types\TestJsonDto;

class CustomTypeSchema extends Schema
{
    #[Primary]
    #[AutoIncrement]
    public int $id;

    public string $name;

    public TestBitmask $permissions;

    #[DefaultValue(0)]
    public TestBitmask $features;

    public ?TestBitmask $flags;

    public TestJsonDto $metadata;

    public TestAddressDto $address;

    public ?TestAddressDto $shippingAddress;
}
