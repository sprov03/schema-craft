<?php

namespace SchemaCraft\Tests\Fixtures\Types;

use SchemaCraft\Types\AbstractJsonDtoType;

class TestJsonDto extends AbstractJsonDtoType
{
    private array $data;

    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    public static function fromArray(array $data): static
    {
        return new static($data);
    }

    public function toArray(): array
    {
        return $this->data;
    }
}
