<?php

namespace SchemaCraft\Generators\InputTypes;

use SchemaCraft\Generators\FilamentPanelDiscovery;
use SchemaCraft\Generators\GeneratorSchemaContext;
use SchemaCraft\Generators\InputDefinition;

/**
 * Input type that lets the user pick one or more Filament page action slots
 * (e.g. List page header actions, View page header actions) as insertion targets.
 *
 * Renders as a generic collection of grouped selects — no custom frontend logic
 * required. Each item in the collection encodes a single file+slot pair.
 *
 * Optional filtering:
 *   - schemaKey: restricts resources to those matching the selected schema's model basename.
 *   - requiresInstanceKey: when true shows only instance slots (view/edit header actions,
 *     table row actions); when false shows only non-instance slots (list header actions,
 *     table actions).
 *
 * Usage in a generator:
 *
 *     'placements' => fn($data) => Input::filamentPlacements('Wire Up To', 'schema', 'requires_instance')
 *
 * Available in templates / inlineTemplates() as $placements:
 *
 *     [
 *         ['file' => 'app/Filament/.../Pages/ListPosts.php', 'anchor' => 'getHeaderActions(): array'],
 *         ...
 *     ]
 */
class FilamentPlacementsInputType implements InputType
{
    /**
     * Slots that require an existing model instance (non-POST actions).
     * getHeaderActions on instance pages (View, Edit) and table row actions.
     */
    private const INSTANCE_SLOTS = ['getHeaderActions_instance', 'getTableRecordActions'];

    /**
     * Slots that do NOT require a model instance (POST / create actions).
     * getHeaderActions on list pages and table-level actions.
     */
    private const NON_INSTANCE_SLOTS = ['getHeaderActions_list', 'getTableActions'];

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

    public function toFrontend(InputDefinition $definition, array $resolved = []): array
    {
        $modelBasename = $this->resolveModelBasename($definition, $resolved);
        $requiresInstance = $this->resolveRequiresInstance($definition, $resolved);

        $groups = [];
        $defaultItems = [];

        foreach ($this->discoverPanelTree() as $panel) {
            foreach ($panel['resources'] as $resource) {
                // Filter by schema model when a schema key is configured
                if ($modelBasename !== null && ! $this->resourceMatchesModel($resource['name'], $modelBasename)) {
                    continue;
                }

                foreach ($resource['pages'] as $page) {
                    $slotOptions = [];

                    foreach ($page['slots'] as $slot) {
                        // Filter slots based on whether the action needs a model instance
                        if ($requiresInstance !== null && ! $this->slotMatchesInstanceRequirement($slot, $requiresInstance)) {
                            continue;
                        }

                        $value = json_encode(['file' => $page['file'], 'slot' => $slot['key']]);

                        $slotOptions[] = [
                            'label' => $resource['name'].' › '.$page['name'].' › '.$slot['label'],
                            'value' => $value,
                        ];

                        $defaultItems[] = ['placement' => $value];
                    }

                    if (! empty($slotOptions)) {
                        $groups[] = [
                            'label' => $resource['name'],
                            'options' => $slotOptions,
                        ];
                    }
                }
            }

            foreach ($panel['relManagers'] as $rm) {
                if ($modelBasename !== null) {
                    // Prefer $relatedResource declaration over class-name prefix — the declaration
                    // is Filament's own source of truth and handles semantic aliases correctly
                    // (e.g. KitRelationManager with $relatedResource = RabbitResource::class).
                    $basename = $rm['relatedResourceBasename'] ?? null;
                    $matchesResource = $basename !== null && str_starts_with($basename, $modelBasename);
                    $matchesName = str_starts_with($rm['name'], $modelBasename);

                    if (! $matchesResource && ! $matchesName) {
                        continue;
                    }
                }

                $slotOptions = [];

                foreach ($rm['slots'] as $slot) {
                    if ($requiresInstance !== null && ! $this->slotMatchesInstanceRequirement($slot, $requiresInstance)) {
                        continue;
                    }

                    $value = json_encode([
                        'file' => $rm['file'],
                        'slot' => $slot['key'],
                        'isRelationManager' => true,
                    ]);

                    $slotOptions[] = [
                        'label' => $rm['parentResource'].' › '.$rm['name'].' › '.$slot['label'],
                        'value' => $value,
                    ];

                    $defaultItems[] = ['placement' => $value];
                }

                if (! empty($slotOptions)) {
                    $groups[] = [
                        'label' => $rm['parentResource'],
                        'options' => $slotOptions,
                    ];
                }
            }
        }

        return [
            'renderAs' => 'collection',
            'addLabel' => 'Add Location',
            'defaultItems' => $defaultItems,
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
                'relManagers' => $this->discoverRelationManagers($path),
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

        // Resources may be directly in the panel path OR nested one level deep
        // in a subdirectory (e.g. Resources/Records/RecordResource.php).
        $files = array_merge(
            glob($fullPath.'/*Resource.php') ?: [],
            glob($fullPath.'/*/*Resource.php') ?: [],
        );

        foreach ($files as $file) {
            $resourceName = basename($file, '.php');
            // Pages always live beside the resource file in a Pages/ sibling directory
            $absResourceDir = dirname($file);
            $absPagesPath = $absResourceDir.'/Pages';
            $relPagesPath = ltrim(str_replace(base_path(), '', $absPagesPath), '/\\');

            $resources[] = [
                'name' => $resourceName,
                'pages' => $this->discoverPages($absPagesPath, $relPagesPath),
            ];
        }

        return $resources;
    }

    private function discoverRelationManagers(string $panelPath): array
    {
        $fullPath = base_path($panelPath);

        if (! is_dir($fullPath)) {
            return [];
        }

        $relManagers = [];
        $files = glob($fullPath.'/*/RelationManagers/*RelationManager.php') ?: [];

        foreach ($files as $file) {
            $content = file_get_contents($file);

            // Only include RMs that have a headerActions slot to wire into
            if (! str_contains($content, '->headerActions(')) {
                continue;
            }

            $rmName = basename($file, '.php');
            $parentDir = dirname(dirname($file));

            // Find parent resource file (e.g. AccountResource.php in Accounts/)
            $parentResourceFiles = glob($parentDir.'/*Resource.php') ?: [];
            $parentResourceName = ! empty($parentResourceFiles)
                ? basename($parentResourceFiles[0], '.php')
                : basename($parentDir).'Resource';

            $relPath = ltrim(str_replace(base_path(), '', $file), '/\\');

            // Extract the related resource class basename from the $relatedResource declaration,
            // if present. More reliable than matching on the RM class name, which may be a
            // semantic alias (e.g. KitRelationManager targeting RabbitResource).
            $relatedResourceBasename = null;
            if (preg_match('/\$relatedResource\s*=\s*([A-Za-z]+)::class/', $content, $m)) {
                $relatedResourceBasename = $m[1];
            }

            $relManagers[] = [
                'name' => $rmName,
                'file' => $relPath,
                'parentResource' => $parentResourceName,
                'relatedResourceBasename' => $relatedResourceBasename,
                'slots' => [
                    [
                        'key' => 'headerActions',
                        // Relation manager header actions are always list-level (no model instance)
                        'filterKey' => 'getHeaderActions_list',
                        'label' => 'Header Actions',
                        'requiresInstance' => false,
                    ],
                ],
            ];
        }

        return $relManagers;
    }

    private function discoverPages(string $absPath, string $relPath): array
    {
        if (! is_dir($absPath)) {
            return [];
        }

        $pages = [];

        foreach (glob($absPath.'/*.php') as $file) {
            $pageName = basename($file, '.php');
            $pageType = $this->classifyPage($pageName);
            $slots = $this->detectSlots($file, $pageType);

            if (empty($slots)) {
                continue;
            }

            $pages[] = [
                'name' => $pageName,
                'file' => $relPath.'/'.$pageName.'.php',
                'pageType' => $pageType,
                'slots' => $slots,
            ];
        }

        return $pages;
    }

    /**
     * Classify a page by its name prefix.
     *
     * Returns 'list' for list pages, 'instance' for view/edit pages, 'other' for everything else.
     */
    private function classifyPage(string $pageName): string
    {
        if (str_starts_with($pageName, 'List') || str_starts_with($pageName, 'Manage')) {
            return 'list';
        }

        if (str_starts_with($pageName, 'View') || str_starts_with($pageName, 'Edit')) {
            return 'instance';
        }

        return 'other';
    }

    /**
     * Detect action slots in the page file and tag each with its instance requirement.
     *
     * Slot keys are namespaced with the page type for getHeaderActions so the
     * instance filter can distinguish list-header from view-header.
     *
     * @return array<int, array{key: string, label: string, requiresInstance: bool}>
     */
    private function detectSlots(string $filePath, string $pageType): array
    {
        $content = file_get_contents($filePath);
        $slots = [];

        if (str_contains($content, 'getHeaderActions()')) {
            $isInstance = $pageType === 'instance';
            $slots[] = [
                'key' => 'getHeaderActions',
                // Namespaced key used only for instance-filter matching; the resolved file+slot pair
                // always stores the plain 'getHeaderActions' key that maps to the real PHP method.
                'filterKey' => $isInstance ? 'getHeaderActions_instance' : 'getHeaderActions_list',
                'label' => 'Header Actions',
                'requiresInstance' => $isInstance,
            ];
        }

        if (str_contains($content, 'getTableActions()')) {
            $slots[] = [
                'key' => 'getTableActions',
                'filterKey' => 'getTableActions',
                'label' => 'Table Actions',
                'requiresInstance' => false,
            ];
        }

        if (str_contains($content, 'getTableRecordActions()')) {
            $slots[] = [
                'key' => 'getTableRecordActions',
                'filterKey' => 'getTableRecordActions',
                'label' => 'Table Row Actions',
                'requiresInstance' => true,
            ];
        }

        return $slots;
    }

    // ─── Filters ──────────────────────────────────────────────────

    /**
     * True when the resource class name corresponds to the selected schema's model.
     * e.g. model = 'Record', resource = 'RecordResource' → true
     */
    private function resourceMatchesModel(string $resourceName, string $modelBasename): bool
    {
        return str_starts_with($resourceName, $modelBasename);
    }

    /**
     * True when the slot should be shown given the instance requirement.
     */
    private function slotMatchesInstanceRequirement(array $slot, bool $requiresInstance): bool
    {
        return $slot['requiresInstance'] === $requiresInstance;
    }

    // ─── Resolved helpers ─────────────────────────────────────────

    private function resolveModelBasename(InputDefinition $definition, array $resolved): ?string
    {
        if ($definition->schemaKey === null) {
            return null;
        }

        $context = $resolved[$definition->schemaKey] ?? null;

        if (! $context instanceof GeneratorSchemaContext) {
            return null;
        }

        return class_basename($context->modelClass);
    }

    private function resolveRequiresInstance(InputDefinition $definition, array $resolved): ?bool
    {
        if ($definition->requiresInstanceKey === null) {
            return null;
        }

        $value = $resolved[$definition->requiresInstanceKey] ?? null;

        return is_bool($value) ? $value : null;
    }

    // ─── Placement resolution ─────────────────────────────────────

    private function resolvePlacement(array $placement): ?array
    {
        if (empty($placement['file']) || empty($placement['slot'])) {
            return null;
        }

        if (! empty($placement['isRelationManager'])) {
            return [
                'file' => $placement['file'],
                'anchor' => null,
                'searchPattern' => "->headerActions([\n",
                'isRelationManager' => true,
            ];
        }

        return [
            'file' => $placement['file'],
            'anchor' => $this->slotAnchor($placement['slot']),
            'searchPattern' => 'return [',
            'isRelationManager' => false,
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
