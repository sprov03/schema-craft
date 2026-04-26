<?php

namespace SchemaCraft\Contracts;

use SchemaCraft\Scanner\ColumnDefinition;

/**
 * Implement on SchemaCraft column types to control the Faker expression
 * used when generating model factories for this column.
 *
 * Without this interface the factory generator falls through to
 * FakerMethodMapper's name/type heuristics, which cannot know
 * the semantics of a custom type. Implementing this gives custom
 * types full control over what realistic test data looks like.
 */
interface GeneratesFakerValue
{
    /**
     * Return a PHP expression string that produces a valid fake value.
     *
     * The expression is injected verbatim into the factory definition,
     * with $faker in scope. Example return values:
     *   '$faker->numberBetween(0, 7)'
     *   '$faker->randomElement(Status::cases())'
     *   '[]'
     */
    public static function fakerExpression(ColumnDefinition $column): string;
}
