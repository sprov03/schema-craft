<?php

namespace SchemaCraft\Generators;

/**
 * Base class for custom SchemaCraft generators.
 *
 * Extend this class to define a generator that can be discovered by the
 * SchemaCraft visualizer. Implement name() and templates(); override
 * schemas() and inputs() as needed.
 *
 * Example:
 *
 *   class MyGenerator extends SchemaCraftGenerator
 *   {
 *       public function name(): string { return 'My Generator'; }
 *
 *       public function inputs(): array
 *       {
 *           return [
 *               Input::text('class_name', 'Class Name'),
 *               Input::select('type', 'Type', ['a' => 'Type A', 'b' => 'Type B']),
 *           ];
 *       }
 *
 *       public function templates(): array
 *       {
 *           return [
 *               Template::file('generators.my-template', 'app/[class_name].php'),
 *           ];
 *       }
 *   }
 *
 * In your Blade template, use {!! $phpOpenTag !!} to output the PHP opening tag:
 *
 *   {!! $phpOpenTag !!}
 *
 *   namespace App;
 *
 *   class {!! $class_name !!}
 *   {
 *
 *   @foreach ($schema->columns as $column)
 *       public {!! $column->phpType() !!} ${!! $column->name !!};
 *
 *   @endforeach
 *   }
 */
abstract class SchemaCraftGenerator
{
    /**
     * Human-readable name shown in the visualizer dropdown.
     */
    abstract public function name(): string;

    /**
     * Named schema slots required by this generator.
     *
     * Keys become Blade variable names ($schema, $relatedSchema, etc.).
     * Return [] for generators that don't need schema context.
     *
     * @return string[]
     */
    public function schemas(): array
    {
        return ['schema'];
    }

    /**
     * Input fields shown in the visualizer form.
     *
     * @return InputDefinition[]
     */
    public function inputs(): array
    {
        return [];
    }

    /**
     * Blade templates to render and write.
     *
     * @return TemplateDefinition[]
     */
    abstract public function templates(): array;
}
