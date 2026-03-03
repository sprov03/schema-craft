<?php

namespace SchemaCraft\Traits;

use Carbon\CarbonInterface;

/**
 * Adds created_at and updated_at timestamp columns to the schema.
 *
 * Use this trait on your schema class to declare timestamp columns.
 * The model will automatically have $timestamps = true when this trait is present.
 */
trait TimestampsSchema
{
    public ?CarbonInterface $created_at;

    public ?CarbonInterface $updated_at;
}
