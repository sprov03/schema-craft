<?php

namespace SchemaCraft\Visualizer;

class HealthIssue
{
    /**
     * @param  'error'|'warning'|'info'  $severity
     * @param  'missing_inverse'|'orphaned_model'|'fk_without_relationship'  $check
     * @param  string[]  $affectedSchemas
     */
    /**
     * @param  ?array<string, mixed>  $applyData
     */
    public function __construct(
        public string $severity,
        public string $check,
        public string $message,
        public array $affectedSchemas,
        public ?string $suggestedFix = null,
        public ?array $applyData = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity,
            'check' => $this->check,
            'message' => $this->message,
            'affectedSchemas' => $this->affectedSchemas,
            'suggestedFix' => $this->suggestedFix,
            'applyData' => $this->applyData,
        ];
    }
}
