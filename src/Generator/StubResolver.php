<?php

namespace SchemaCraft\Generator;

class StubResolver
{
    private static string $packageStubsPath = __DIR__.'/../Console/stubs';

    /**
     * Resolve a stub file path, preferring published stubs over package defaults.
     */
    public static function resolve(string $filename): string
    {
        $publishedPath = static::publishedPath("stubs/schema-craft/{$filename}");

        if ($publishedPath !== null && file_exists($publishedPath)) {
            return $publishedPath;
        }

        return static::$packageStubsPath."/{$filename}";
    }

    /**
     * Load a stub and replace placeholders.
     *
     * @param  array<string, string>  $replacements  Map of '{{ key }}' => value
     */
    public static function render(string $filename, array $replacements): string
    {
        $stub = file_get_contents(static::resolve($filename));

        $content = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $stub,
        );

        // Clean up triple+ newlines from empty placeholders
        return preg_replace('/\n{3,}/', "\n\n", $content);
    }

    /**
     * Resolve the base stubs directory path (for generators needing the directory).
     */
    public static function basePath(): string
    {
        $publishedPath = static::publishedPath('stubs/schema-craft');

        if ($publishedPath !== null && is_dir($publishedPath)) {
            return $publishedPath;
        }

        return static::$packageStubsPath;
    }

    /**
     * Get a published path using base_path(), returning null if the app isn't booted.
     */
    private static function publishedPath(string $relativePath): ?string
    {
        if (! function_exists('base_path') || ! method_exists(app(), 'basePath')) {
            return null;
        }

        return base_path($relativePath);
    }
}
