<?php

namespace SchemaCraft\Tests\Fixtures\Schemas;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\ColumnType;
use SchemaCraft\Attributes\DefaultExpression;
use SchemaCraft\Attributes\Index;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Attributes\Relations\BelongsTo;
use SchemaCraft\Attributes\Relations\MorphTo;
use SchemaCraft\Schema;
use SchemaCraft\Tests\Fixtures\Models\User;
use SchemaCraft\Traits\TimestampsSchema;

class NonStandardFkSchema extends Schema
{
    use TimestampsSchema;

    #[Primary]
    #[AutoIncrement]
    public int $id;

    public string $name;

    #[BelongsTo(User::class)]
    #[ColumnType('unsignedInteger')]
    #[Index]
    public User $legacyUser;

    #[MorphTo('taggable')]
    #[ColumnType('unsignedInteger')]
    #[Index]
    public Model $taggable;

    #[DefaultExpression('CURRENT_TIMESTAMP')]
    public ?CarbonInterface $verified_at;
}
