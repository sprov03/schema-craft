<?php

namespace SchemaCraft\Visualizer;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class DocsController
{
    public function index(): JsonResponse
    {
        $path = realpath(__DIR__.'/../../README.md');

        if (! $path || ! file_exists($path)) {
            return new JsonResponse([
                'error' => 'Documentation file not found.',
                'sections' => [],
            ], 404);
        }

        $content = file_get_contents($path);
        $sections = $this->splitByH2($content);

        return new JsonResponse(['sections' => $sections]);
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
