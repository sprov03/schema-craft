<?php

namespace SchemaCraft\Generators;

use Illuminate\Support\Str;

/**
 * Discovers Filament panel resource directories from PanelProvider files and config.
 *
 * Scans `app/Providers/Filament/*PanelProvider.php` for `->discoverResources(in: ...)`
 * calls, and also reads `config('schema-craft.resource_directories')` for manual entries.
 *
 * Returns an associative array of label → relative directory path.
 */
class FilamentPanelDiscovery
{
    /**
     * Discover all resource directories.
     *
     * @return array<string, string> label => relative path (e.g. 'Admin' => 'app/Filament/Admin/Resources')
     */
    public function discover(): array
    {
        $directories = [];

        // Scan PanelProvider files
        $providerPath = base_path('app/Providers/Filament');

        if (is_dir($providerPath)) {
            foreach (glob($providerPath.'/*PanelProvider.php') as $file) {
                $contents = file_get_contents($file);

                // Match ->discoverResources(in: app_path('...'), ...)
                if (preg_match('/->discoverResources\(\s*in:\s*app_path\([\'"](.+?)[\'"]\)/', $contents, $match)) {
                    $relativePath = 'app/'.$match[1];

                    // Derive label from panel name: AdminPanelProvider → Admin
                    $filename = basename($file, '.php');
                    $label = Str::beforeLast($filename, 'PanelProvider');

                    $directories[$label] = $relativePath;
                }
            }
        }

        // Merge config-defined directories
        $configDirs = config('schema-craft.resource_directories', []);

        foreach ($configDirs as $label => $path) {
            $directories[$label] = $path;
        }

        return $directories;
    }
}
