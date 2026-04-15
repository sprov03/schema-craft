<?php

namespace SchemaCraft\Tests\Fixtures\Casts;

use SchemaCraft\Contracts\FilamentRenderable;
use SchemaCraft\Generators\GeneratorColumn;

/**
 * Fixture cast implementing FilamentRenderable so tests can verify that
 * GeneratorColumn dispatches to custom static renderers when the cast class
 * opts in.
 */
class RenderableCast implements FilamentRenderable
{
    public static function asFilamentColumn(GeneratorColumn $column): string
    {
        return "CustomColumn::make('{$column->name}')->customised()";
    }

    public static function asFilamentEntry(GeneratorColumn $column): string
    {
        return "CustomEntry::make('{$column->name}')->customised()";
    }

    public static function asFilamentField(GeneratorColumn $column): string
    {
        return "CustomField::make('{$column->name}')->customised()";
    }
}
