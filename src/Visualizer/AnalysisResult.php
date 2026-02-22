<?php

namespace SchemaCraft\Visualizer;

class AnalysisResult
{
    /**
     * @param  array<string, SchemaInfo>  $schemas
     * @param  HealthIssue[]  $issues
     */
    public function __construct(
        public array $schemas,
        public array $issues,
        public int $modelCount,
        public int $relationshipCount,
        public int $issueCount,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'summary' => [
                'modelCount' => $this->modelCount,
                'relationshipCount' => $this->relationshipCount,
                'issueCount' => $this->issueCount,
            ],
            'schemas' => array_map(
                fn (SchemaInfo $s) => $s->toArray(),
                $this->schemas,
            ),
            'issues' => array_map(
                fn (HealthIssue $i) => $i->toArray(),
                $this->issues,
            ),
        ];
    }
}
