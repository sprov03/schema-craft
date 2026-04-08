<?php

namespace SchemaCraft\Tests\Fixtures\Schemas;

use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Attributes\Title;
use SchemaCraft\Schema;

class TitlePropertySchema extends Schema
{
    #[Primary]
    #[AutoIncrement]
    public int $id;

    #[Title]
    public string $companyName;

    public string $taxId;
}
