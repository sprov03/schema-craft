<?php

namespace SchemaCraft\Tests\Fixtures\BrokenApi;

use SchemaCraft\Attributes\AutoIncrement;
use SchemaCraft\Attributes\Primary;
use SchemaCraft\Schema;

/**
 * Slim, isolated schema for the referential-integrity (dangling-reference) test. Lives in its
 * own BrokenApi fixture set so the broken scenario is the ONLY thing in that build — nothing
 * else can preempt or contradict the dangling-pointer failure the test is asserting.
 */
class DanglingSchema extends Schema
{
    #[Primary]
    #[AutoIncrement]
    public int $id;

    public string $name;
}
