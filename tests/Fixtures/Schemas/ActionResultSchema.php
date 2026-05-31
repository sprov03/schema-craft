<?php

namespace SchemaCraft\Tests\Fixtures\Schemas;

use SchemaCraft\Schema;

/**
 * Minimal backing schema for ActionResultResource.
 *
 * Why it exists: the SDK only generates a Data DTO for a model that appears in the schema set,
 * and the only way the builder can scan a table for ActionResult is through a #[ResourceSchema].
 * It has no API surface of its own (no controller/routes) — it exists purely so void actions like
 * delete()/archive() can return a structured ActionResultData DTO instead of being filtered out.
 *
 * Intentionally has NO #[Primary]/#[AutoIncrement]: this is a transient response shape, not a table.
 */
class ActionResultSchema extends Schema
{
    public bool $success;

    public string $message;
}
