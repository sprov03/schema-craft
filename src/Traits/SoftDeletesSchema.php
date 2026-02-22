<?php

namespace SchemaCraft\Traits;

use Illuminate\Support\Carbon;

/**
 * Adds a deleted_at timestamp column to the schema for soft deletes.
 *
 * Use this trait on your schema class to declare the soft delete column.
 * Remember to also add `use SoftDeletes` on your model class for runtime behavior.
 */
trait SoftDeletesSchema
{
    public ?Carbon $deleted_at;
}
