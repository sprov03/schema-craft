<?php

namespace SchemaCraft\Tests\Fixtures\Schemas;

use Carbon\CarbonInterface;
use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Schema;
use SchemaCraft\Traits\SoftDeletesSchema;
use SchemaCraft\Traits\TimestampsSchema;

/**
 * Reached as a hasOne dependency of CatalogSchema. Carries timestamps + soft
 * deletes so its schema-driven DTO is the fixture that demonstrates the managed
 * created_at/updated_at/deleted_at columns (all string|null) in the matrix.
 */
class CatalogShipmentSchema extends Schema
{
    use SoftDeletesSchema;
    use TimestampsSchema;

    #[Primary]
    #[AutoIncrement]
    public int $id;

    public string $carrier;

    public ?CarbonInterface $shipped_at;
}
