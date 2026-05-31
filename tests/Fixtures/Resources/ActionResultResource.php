<?php

namespace SchemaCraft\Tests\Fixtures\Resources;

use SchemaCraft\Attributes\Resources\ResourceSchema;
use SchemaCraft\SchemaCraftResource;
use SchemaCraft\Tests\Fixtures\Schemas\ActionResultSchema;

/**
 * Structured result resource for VOID actions (delete, archive).
 *
 * Why void actions point at this instead of staying bare: an endpoint with no documented
 * response is now FILTERED from the SDK (and warned about) by SdkContextBuilder. Declaring
 * #[ApiResponse(ActionResultResource::class)] on delete()/archive() documents them with a
 * tiny success/message DTO, so the SDK emits a typed ActionResultData return rather than void.
 *
 * It is backed by ActionResultSchema (#[ResourceSchema]) only so the generator can scan a
 * table and emit ActionResultData — there is no ActionResult API surface (no controller/routes).
 */
#[ResourceSchema(ActionResultSchema::class)]
class ActionResultResource extends SchemaCraftResource
{
    public bool $success;

    public string $message;
}
