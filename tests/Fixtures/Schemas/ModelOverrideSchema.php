<?php

namespace SchemaCraft\Tests\Fixtures\Schemas;

use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Schema;

/**
 * Fixture used exclusively by GeneratorSchemaContextTest to verify that
 * `modelClass` resolution reads an explicit `public static string $model`
 * property via reflection when one is declared.
 */
class ModelOverrideSchema extends Schema
{
    /**
     * Explicit model FQCN. The resolver should return this string without
     * falling back to the naming convention.
     */
    public static string $model = 'Custom\\Namespace\\OverriddenModel';

    #[Primary]
    #[AutoIncrement]
    public int $id;

    public string $name;
}
