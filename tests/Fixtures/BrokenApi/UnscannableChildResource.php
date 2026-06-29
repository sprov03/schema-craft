<?php

namespace SchemaCraft\Tests\Fixtures\BrokenApi;

use Illuminate\Support\Collection;
use SchemaCraft\SchemaCraftResource;

/**
 * Deliberately unscannable: a bare Collection property without #[CollectionOf] makes
 * ResourceScanner::scanClass() throw, so the SDK dependency walker (collectDepResources)
 * silently skips it and it never gets emitted. A parent Resource that references it therefore
 * carries a nested-shape pointer to a Resource that isn't in the SDK — the exact dangling
 * state the referential-integrity check must catch.
 */
class UnscannableChildResource extends SchemaCraftResource
{
    public int $id;

    public Collection $items;
}
