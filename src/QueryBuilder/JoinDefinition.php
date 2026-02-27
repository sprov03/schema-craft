<?php

namespace SchemaCraft\QueryBuilder;

class JoinDefinition
{
    public function __construct(
        public string $type,
        public string $table,
        public string $localColumn,
        public string $foreignColumn,
        public ?string $schema = null,
        public ?string $model = null,
        public ?string $alias = null,
        public ?string $relationshipName = null,
        public bool $indexed = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: $data['type'] ?? 'inner',
            table: $data['table'],
            localColumn: $data['localColumn'],
            foreignColumn: $data['foreignColumn'],
            schema: $data['schema'] ?? null,
            model: $data['model'] ?? null,
            alias: $data['alias'] ?? null,
            relationshipName: $data['relationshipName'] ?? null,
            indexed: $data['indexed'] ?? false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'table' => $this->table,
            'localColumn' => $this->localColumn,
            'foreignColumn' => $this->foreignColumn,
            'schema' => $this->schema,
            'model' => $this->model,
            'alias' => $this->alias,
            'relationshipName' => $this->relationshipName,
            'indexed' => $this->indexed,
        ];
    }

    /**
     * Get the Eloquent join method name for this join type.
     */
    public function joinMethod(): string
    {
        return match ($this->type) {
            'left' => 'leftJoin',
            'right' => 'rightJoin',
            default => 'join',
        };
    }
}
