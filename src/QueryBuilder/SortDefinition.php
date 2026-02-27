<?php

namespace SchemaCraft\QueryBuilder;

class SortDefinition
{
    public function __construct(
        public string $column,
        public string $direction = 'asc',
        public bool $parameter = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            column: $data['column'],
            direction: $data['direction'] ?? 'asc',
            parameter: $data['parameter'] ?? false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'column' => $this->column,
            'direction' => $this->direction,
            'parameter' => $this->parameter,
        ];
    }
}
