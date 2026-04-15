<?php

namespace SchemaCraft\Generators;

use Illuminate\Contracts\View\Factory as ViewFactory;
use SchemaCraft\Generator\Api\GeneratedFile;

/**
 * Renders a generator's Blade templates and returns the resulting files.
 *
 * Supports chained dot-notation in output paths (e.g. [schema.model.plural.title]),
 * extra variables per template, and iteration over collections (e.g. relationships).
 */
class GeneratorRunner
{
    public function __construct(private readonly ViewFactory $view) {}

    /**
     * Render all templates for a generator run.
     *
     * @param  array<string, mixed>  $inputValues  Input values; schemaSelector values are GeneratorSchemaContext instances.
     * @return GeneratedFile[]
     */
    public function run(
        SchemaCraftGenerator $generator,
        array $inputValues,
    ): array {
        $files = [];

        // Build merged data: phpOpenTag + inputs + templateData
        $allData = array_merge(
            ['phpOpenTag' => '<?php'],
            $inputValues,
            $generator->templateData(),
        );

        foreach ($generator->templates() as $templateDef) {
            if ($templateDef->iterateOver !== null) {
                $iterable = $this->resolveChain($templateDef->iterateOver, $allData);

                if (! is_iterable($iterable)) {
                    continue;
                }

                $items = is_array($iterable) ? $iterable : iterator_to_array($iterable);

                if ($templateDef->iterateFilter !== null) {
                    $items = $this->applyFilter($items, $templateDef->iterateFilter);
                }

                foreach ($items as $item) {
                    $iterData = array_merge($allData, [$templateDef->iterateAs => $item]);
                    $extras = $this->resolveExtraVariables($templateDef->extraVariables, $iterData);
                    $iterData = array_merge($iterData, $extras);
                    $content = $this->view->make($templateDef->viewName, $iterData)->render();
                    $path = $this->resolveOutputPath($templateDef->outputPath, $iterData);
                    $files[] = new GeneratedFile(path: $path, content: $content);
                }
            } else {
                $extras = $this->resolveExtraVariables($templateDef->extraVariables, $allData);
                $data = array_merge($allData, $extras);
                $content = $this->view->make($templateDef->viewName, $data)->render();
                $path = $this->resolveOutputPath($templateDef->outputPath, $data);
                $files[] = new GeneratedFile(path: $path, content: $content);
            }
        }

        return $files;
    }

    /**
     * Substitute [dot.path] placeholders in an output path using chained resolution.
     *
     * Simple keys like [class_name] resolve directly from the data array.
     * Chained keys like [schema.model.plural.title] traverse objects via __get().
     * String values are automatically wrapped in NameChain when chaining is needed,
     * so [model_name.plural.title] works even if model_name is a plain string.
     */
    private function resolveOutputPath(string $path, array $allData): string
    {
        return preg_replace_callback('/\[([^\]]+)\]/', function ($match) use ($allData) {
            $resolved = $this->resolveChain($match[1], $allData);

            return $resolved !== null ? (string) $resolved : $match[0];
        }, $path);
    }

    /**
     * Resolve a dot-separated path through the data array.
     *
     * e.g. 'schema.model.plural.title' → $allData['schema']->model->plural->title
     *
     * Plain strings are auto-wrapped in NameChain when chaining is needed,
     * so 'model_name.plural.title' works even if model_name is a string input.
     */
    private function resolveChain(string $dotPath, array $data): mixed
    {
        $segments = explode('.', $dotPath);
        $value = $data[array_shift($segments)] ?? null;

        // If there are more segments and value is a string, wrap in NameChain for chaining
        if (! empty($segments) && is_string($value)) {
            $value = new NameChain($value);
        }

        foreach ($segments as $segment) {
            if ($value === null) {
                return null;
            }

            if (is_object($value) && method_exists($value, '__get')) {
                $value = $value->{$segment};
            } elseif (is_object($value) && property_exists($value, $segment)) {
                $value = $value->{$segment};
            } elseif (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return null;
            }
        }

        return $value;
    }

    /**
     * Resolve extra variables — string values get [bracket] placeholders substituted.
     */
    private function resolveExtraVariables(array $extraVars, array $allData): array
    {
        $resolved = [];

        foreach ($extraVars as $key => $value) {
            if (is_string($value)) {
                $resolved[$key] = $this->resolveOutputPath($value, $allData);
            } else {
                $resolved[$key] = $value;
            }
        }

        return $resolved;
    }

    /**
     * Apply a named filter to an iterable of items.
     */
    private function applyFilter(array $items, string $filter): array
    {
        return match ($filter) {
            'collection' => array_filter($items, fn ($r) => method_exists($r, 'isCollection') && $r->isCollection()),
            'singular' => array_filter($items, fn ($r) => method_exists($r, 'isSingular') && $r->isSingular()),
            default => $items,
        };
    }
}
