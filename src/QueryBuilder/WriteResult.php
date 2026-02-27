<?php

namespace SchemaCraft\QueryBuilder;

class WriteResult
{
    public function __construct(
        public bool $success,
        public string $message,
        public ?string $filePath = null,
    ) {}

    /**
     * @return array{success: bool, message: string, filePath: string|null}
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'filePath' => $this->filePath,
        ];
    }
}
