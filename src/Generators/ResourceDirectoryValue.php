<?php

namespace SchemaCraft\Generators;

use Stringable;

/**
 * Value object representing a Filament resource directory choice.
 *
 * Wraps a relative filesystem path (e.g. 'app/Filament/Admin/Resources')
 * alongside its derived PSR-4 namespace (e.g. 'App\Filament\Admin\Resources').
 *
 * Blade templates access the namespace directly on the object:
 *
 *     namespace {!! $resource_directory->namespace !!}\{!! $schema->model->plural->title !!};
 *
 * Output path interpolation such as `[resource_directory]` calls __toString()
 * under the hood and receives the raw path, so existing output-path templates
 * keep working unchanged. You can also reach the inner properties from output
 * paths via dot-notation, e.g. `[resource_directory.path]`.
 */
final class ResourceDirectoryValue implements Stringable
{
    /** Relative filesystem path, e.g. 'app/Filament/Admin/Resources'. */
    public readonly string $path;

    /** PSR-4 namespace derived from $path, e.g. 'App\Filament\Admin\Resources'. */
    public readonly string $namespace;

    public function __construct(string $path)
    {
        $this->path = rtrim($path, '/');
        $this->namespace = self::deriveNamespace($this->path);
    }

    public function __toString(): string
    {
        return $this->path;
    }

    /**
     * Derive a PSR-4 namespace from a relative filesystem path.
     *
     * Follows the Laravel convention where the first segment is the root
     * namespace indicator (e.g. 'app' → 'App'), and subsequent segments are
     * assumed to already be PascalCase per Filament directory convention
     * (e.g. 'Filament/Admin/Resources').
     */
    public static function deriveNamespace(string $path): string
    {
        $trimmed = trim($path, '/');

        if ($trimmed === '') {
            return '';
        }

        $parts = explode('/', $trimmed);
        $parts[0] = ucfirst($parts[0]);

        return implode('\\', $parts);
    }
}
