<?php

namespace SchemaCraft\Visualizer;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class DocsController
{
    public function index(): JsonResponse
    {
        $documents = [];

        // 1. Package README — always first
        $readmePath = realpath(__DIR__.'/../../README.md');
        if ($readmePath && file_exists($readmePath)) {
            $documents[] = $this->parseDocument($readmePath, 'SchemaCraft Guide');
        }

        // 2. Project-level docs from configured directory
        $docsPath = base_path(config('schema-craft.visualizer.docs_path', 'docs'));

        if (is_dir($docsPath)) {
            $files = glob($docsPath.'/*.md');
            sort($files);

            foreach ($files as $file) {
                $documents[] = $this->parseDocument($file);
            }
        }

        if (empty($documents)) {
            return new JsonResponse([
                'error' => 'No documentation files found.',
                'documents' => [],
            ], 404);
        }

        return new JsonResponse(['documents' => $documents]);
    }

    /**
     * Parse a markdown file into a document with sections.
     *
     * @return array{name: string, slug: string, sections: array}
     */
    private function parseDocument(string $filePath, ?string $fallbackName = null): array
    {
        $content = file_get_contents($filePath);
        $name = $this->extractDocName($content, $filePath, $fallbackName);
        $sections = $this->splitByH2($content);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'sections' => $sections,
        ];
    }

    /**
     * Extract the document name from the first H1, or fall back to filename.
     */
    private function extractDocName(string $content, string $filePath, ?string $fallbackName): string
    {
        if (preg_match('/^# (.+)$/m', $content, $matches)) {
            return trim($matches[1]);
        }

        if ($fallbackName !== null) {
            return $fallbackName;
        }

        // Humanize filename: "01-getting-started.md" → "Getting Started"
        $basename = pathinfo($filePath, PATHINFO_FILENAME);
        $basename = preg_replace('/^\d+[-_]/', '', $basename);

        return Str::headline($basename);
    }

    /**
     * Split markdown content into sections by H2 headers.
     *
     * @return array<int, array{title: string, slug: string, content: string}>
     */
    private function splitByH2(string $markdown): array
    {
        // Split on H2 headers, keeping the delimiter
        $parts = preg_split('/^(## .+)$/m', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE);

        $sections = [];

        // Everything before the first H2 is the introduction
        $intro = trim($parts[0] ?? '');
        if ($intro !== '') {
            $sections[] = [
                'title' => 'Introduction',
                'slug' => 'introduction',
                'content' => $intro,
            ];
        }

        // Pair up H2 headers with their content
        for ($i = 1, $count = count($parts); $i < $count; $i += 2) {
            $title = trim(str_replace('## ', '', $parts[$i]));
            $body = trim($parts[$i + 1] ?? '');
            $fullContent = $parts[$i]."\n\n".$body;

            $sections[] = [
                'title' => $title,
                'slug' => Str::slug($title),
                'content' => $fullContent,
            ];
        }

        return $sections;
    }
}
