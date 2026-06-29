<?php

namespace SchemaCraft\Tests\Fixtures\Requests;

use SchemaCraft\Attributes\Length;
use SchemaCraft\Attributes\Rules;
use SchemaCraft\Request;

/**
 * Standalone request (no $schema link) — stands entirely on its own primitives.
 */
class SearchPostsRequest extends Request
{
    #[Length(50)]
    public ?string $search;

    public int $page = 1;

    // Request-specific custom rule, declared with the same #[Rules] attribute schemas use.
    #[Rules('in:active,inactive')]
    public ?string $status;
}
