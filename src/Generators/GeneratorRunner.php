<?php

namespace SchemaCraft\Generators;

use Illuminate\Contracts\View\Factory as ViewFactory;
use SchemaCraft\Generator\Api\GeneratedFile;

/**
 * Renders a generator's Blade templates and returns the resulting files.
 */
class GeneratorRunner
{
    public function __construct(private readonly ViewFactory $view) {}

    /**
     * Render all templates for a generator run.
     *
     * @param  array<string, GeneratorSchemaContext>  $schemaContexts  Keyed by schema slot name.
     * @param  array<string, mixed>  $inputValues  Input values; schemaColumn values are GeneratorColumn instances.
     * @return GeneratedFile[]
     */
    public function run(
        SchemaCraftGenerator $generator,
        array $schemaContexts,
        array $inputValues,
    ): array {
        $files = [];

        // Provide $phpOpenTag so templates can output PHP opening tags without
        // confusing the Blade compiler: {!! $phpOpenTag !!}
        $data = array_merge(['phpOpenTag' => '<?php'], $schemaContexts, $inputValues);

        foreach ($generator->templates() as $templateDef) {
            $content = $this->view->make($templateDef->viewName, $data)->render();
            $path = $this->resolveOutputPath($templateDef->outputPath, $inputValues);
            $files[] = new GeneratedFile(path: $path, content: $content);
        }

        return $files;
    }

    /**
     * Substitute [key] placeholders in an output path with string input values.
     *
     * e.g. 'app/[class_name].php' + ['class_name' => 'FooService'] → 'app/FooService.php'
     */
    private function resolveOutputPath(string $path, array $inputValues): string
    {
        foreach ($inputValues as $key => $value) {
            if (is_string($value)) {
                $path = str_replace('['.$key.']', $value, $path);
            }
        }

        return $path;
    }
}
