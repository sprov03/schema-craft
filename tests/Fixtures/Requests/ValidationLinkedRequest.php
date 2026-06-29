<?php

namespace SchemaCraft\Tests\Fixtures\Requests;

use SchemaCraft\Request;
use SchemaCraft\Tests\Fixtures\Schemas\ValidationTestSchema;

/**
 * Request linked to a schema. The schema's custom #[Rules] enrich ONLY the
 * columns present on both sides; nullability stays the request's own decision.
 *
 * - title: overlaps ValidationTestSchema::title (which carries #[Rules('min:3')]).
 *          Declared nullable here even though the schema column is required —
 *          the request's nullability must win.
 * - note:  request-only; the schema knows nothing about it.
 */
class ValidationLinkedRequest extends Request
{
    protected static string $schema = ValidationTestSchema::class;

    public ?string $title;

    public ?string $note;
}
