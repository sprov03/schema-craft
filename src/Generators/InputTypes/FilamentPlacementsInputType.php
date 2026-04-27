<?php

namespace SchemaCraft\Generators\InputTypes;

use SchemaCraft\Generators\FilamentPanelDiscovery;
use SchemaCraft\Generators\InputDefinition;

/**
 * Input type that lets the user pick one or more Filament page action slots
 * (e.g. List page header actions, View page header actions) as insertion targets.
 *
 * Frontend receives a full panel → resource → page → slot tree.
 * Resolution converts the user's selections into an array of placement targets,
 * each containing the file path and insertion anchor for the runner.
 *
 * Usage in a generator:
 *
 *     Input::filamentPlacements('placements', 'Wire Up To')
 *
 * Available in templates / inlineTemplates() as $placements:
 *
 *     [
 *         ['file' => 'app/Filament/.../Pages/ListPosts.php', 'anchor' => 'getHeaderActions(): array', 'searchPattern' => 'return ['],
 *         ...
 *     ]
 */
class FilamentPlacementsInputType implements InputType
{
    public function resolutionPass(): int
    {
        return 2;
    }

    public function resolve(mixed $rawValue, InputDefinition $definition, array $resolved): mixed
    {
        if (! is_array($rawValue)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $placement) => $this->resolvePlacement($placement),
            $rawValue,
        )));
    }

    public function toFrontend(InputDefinition $definition): array
    {
        return ['panels' => $this->discoverPanelTree()];
    }

    // ─── Panel tree discovery ─────────────────────────────────────

    private function discoverPanelTree(): array
    {
        $panels = (new FilamentPanelDiscovery)->discover();
        $tree = [];

        foreach ($panels as $label => $path) {
            $tree[] = [
                'label' => $label,
                'path' => $path,
                'resources' => $this->discoverResources($path),
            ];
        }

        return $tree;
    }

    private function discoverResources(string $panelPath): array
    {
        $fullPath = base_path($panelPath);

        if (! is_dir($fullPath)) {
            return [];
        }

        $resources = [];

        foreach (glob($fullPath.'/*Resource.php') as $file) {
            $resourceName = basename($file, '.php');
            $relPagesPath = $panelPath.'/'.$resourceName.'/Pages';
            $absPagesPath = base_path($relPagesPath);

            $resources[] = [
                'name' => $resourceName,
                'pages' => $this->discoverPages($absPagesPath, $relPagesPath),
            ];
        }

        return $resources;
    }

    private function discoverPages(string $absPath, string $relPath): array
    {
        if (! is_dir($absPath)) {
            return [];
        }

        $pages = [];

        foreach (glob($absPath.'/*.php') as $file) {
            $pageName = basename($file, '.php');
            $slots = $this->detectSlots($file);

            if (empty($slots)) {
                continue;
            }

            $pages[] = [
                'name' => $pageName,
                'file' => $relPath.'/'.$pageName.'.php',
                'slots' => $slots,
            ];
        }

        return $pages;
    }

    private function detectSlots(string $filePath): array
    {
        $content = file_get_contents($filePath);
        $slots = [];

        if (str_contains($content, 'getHeaderActions()')) {
            $slots[] = ['key' => 'getHeaderActions', 'label' => 'Header Actions'];
        }

        if (str_contains($content, 'getTableActions()')) {
            $slots[] = ['key' => 'getTableActions', 'label' => 'Table Actions'];
        }

        if (str_contains($content, 'getTableRecordActions()')) {
            $slots[] = ['key' => 'getTableRecordActions', 'label' => 'Table Row Actions'];
        }

        return $slots;
    }

    // ─── Placement resolution ─────────────────────────────────────

    private function resolvePlacement(mixed $placement): ?array
    {
        if (! is_array($placement)
            || empty($placement['file'])
            || empty($placement['slot'])
        ) {
            return null;
        }

        return [
            'file' => $placement['file'],
            'anchor' => $this->slotAnchor($placement['slot']),
            'searchPattern' => 'return [',
        ];
    }

    private function slotAnchor(string $slot): string
    {
        return match ($slot) {
            'getHeaderActions' => 'getHeaderActions(): array',
            'getTableActions' => 'getTableActions(): array',
            'getTableRecordActions' => 'getTableRecordActions(): array',
            default => $slot,
        };
    }
}
