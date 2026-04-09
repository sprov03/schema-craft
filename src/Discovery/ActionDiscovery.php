<?php

namespace SchemaCraft\Discovery;

use SchemaCraft\Action;
use SchemaCraft\Config\ConnectionConfig;
use SchemaCraft\Scanner\ActionScanner;

/**
 * Discovers Action classes for a given model by scanning conventional directories.
 *
 * Used by both the visualizer and SDK generation to provide unified discovery.
 */
class ActionDiscovery
{
    /**
     * Discover Action classes for a model by scanning its action directory.
     *
     * @return array<int, array{class: string, name: string, httpMethod: string, label: string|null}>
     */
    public function discoverForModel(string $modelName, ConnectionConfig $connectionConfig): array
    {
        $actions = [];
        $actionsNamespace = $connectionConfig->actionNamespaceForModel($modelName);
        $actionsDir = base_path(ConnectionConfig::namespaceToDirectory($actionsNamespace));

        if (! is_dir($actionsDir)) {
            return $actions;
        }

        $files = glob($actionsDir.'/*Action.php');

        foreach ($files as $file) {
            $className = pathinfo($file, PATHINFO_FILENAME);
            $fqcn = $actionsNamespace.'\\'.$className;

            if (! class_exists($fqcn)) {
                continue;
            }

            if (! is_subclass_of($fqcn, Action::class)) {
                continue;
            }

            try {
                $scanner = new ActionScanner($fqcn);
                $definition = $scanner->scan();

                $actions[] = [
                    'class' => $fqcn,
                    'name' => $definition->name,
                    'httpMethod' => $definition->httpMethod,
                    'label' => $definition->label,
                ];
            } catch (\Throwable) {
                // Skip classes that can't be scanned
            }
        }

        return $actions;
    }
}
