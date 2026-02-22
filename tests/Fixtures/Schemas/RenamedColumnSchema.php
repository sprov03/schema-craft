<?php

namespace SchemaCraft\Tests\Fixtures\Schemas;

use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\Fillable;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Attributes\RenamedFrom;
use SchemaCraft\Attributes\Text;
use SchemaCraft\Schema;
use SchemaCraft\Traits\TimestampsSchema;

class RenamedColumnSchema extends Schema
{
    use TimestampsSchema;

    #[Primary]
    #[AutoIncrement]
    public int $id;

    #[Fillable]
    #[RenamedFrom('old_title')]
    public string $title;

    #[Fillable]
    #[RenamedFrom('body_text')]
    #[Text]
    public ?string $content;

    #[Fillable]
    public string $slug;
}
