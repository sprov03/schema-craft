<?php

namespace SchemaCraft;

use Illuminate\Database\Eloquent\Model;

/**
 * Base model class for SchemaCraft models.
 *
 * Extend this class and set `protected static string $schema` to your schema class. At boot
 * time the schema is read and casts, relationships, and attribute defaults are auto-registered
 * on the Eloquent model.
 *
 * The schema-reading engine lives in {@see SchemaTrait} so that models which cannot extend this
 * base (e.g. an auth model that must extend Authenticatable) can opt in via `use SchemaTrait;`.
 * This base is simply the greenfield entry point — a single implementation, no drift.
 */
abstract class SchemaModel extends Model
{
    use SchemaTrait;

    /**
     * The schema class that defines this model's database structure.
     *
     * Declared on the base class (not in SchemaTrait) so subclasses set a default via normal
     * inheritance — a trait-declared property would conflict with `$schema = FooSchema::class`.
     * Models that use SchemaTrait without extending this base declare their own $schema the same way.
     *
     * @var class-string<Schema>
     */
    protected static string $schema;
}
