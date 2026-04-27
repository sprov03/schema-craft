<?php

namespace SchemaCraft\Generators\InputTypes;

use SchemaCraft\Generators\FilamentPanelDiscovery;
use SchemaCraft\Generators\InputDefinition;

/**
 * Input type that lets the user pick one or more Filament page action slots
 * (e.g. List page header actions, View page header actions) as insertion targets.
 *
 * Renders as a generic collection of grouped selects — no custom frontend logic
 * required. Each item in the collection encodes a single file+slot pair.
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
            function (mixed $item) {
                if (! is_array($item) || empty($item['placement'])) {
                    return null;
                }

                $decoded = is_string($item['placement'])
                    ? json_decode($item['placement'], true)
                    : $item['placement'];

                if (! is_array($decoded) || empty($decoded['file']) || empty($decoded['slot'])) {
                    return null;
                }

                return $this->resolvePlacement($decoded);
            },
            $rawValue,
        )));
    }

    public function toFrontend(InputDefinition $definition): array
    {
        $groups = [];

        foreach ($this->discoverPanelTree() as $panel) {
            foreach ($panel['resources'] as $resource) {
                foreach ($resource['pages'] as $page) {
                    $slotOptions = [];

                    foreach ($page['slots'] as $slot) {
                        $slotOptions[] = [
                            'label' => $slot['label'],
                            'value' => json_encode(['file' => $page['file'], 'slot' => $slot['key']]),
                        ];
                    }

                    if (! empty($slotOptions)) {
                        $groups[] = [
                            'label' => $resource['name'].' › '.$page['name'],
                            'options' => $slotOptions,
                        ];
                    }
                }
            }
        }

        return [
            'renderAs' => 'collection',
            'addLabel' => 'Add Location',
            'fields' => [
                [
                    'key' => 'placement',
                    'label' => 'Location',
                    'type' => 'groupedSelect',
                    'groups' => $groups,
                ],
            ],
        ];
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

    private function resolvePlacement(array $placement): ?array
    {
        if (empty($placement['file']) || empty($placement['slot'])) {
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
