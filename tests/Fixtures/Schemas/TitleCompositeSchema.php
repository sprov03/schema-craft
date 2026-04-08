<?php

namespace SchemaCraft\Tests\Fixtures\Schemas;

use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Attributes\Title;
use SchemaCraft\Schema;

#[Title(['first_name', 'last_name'])]
class TitleCompositeSchema extends Schema
{
    #[Primary]
    #[AutoIncrement]
    public int $id;

    public string $firstName;

    public string $lastName;

    public string $email;
}
