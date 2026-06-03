<?php

namespace SchemaCraft\Contracts;

use Illuminate\Contracts\Database\Eloquent\Castable;

/**
 * The single interface all schema-craft column-type primitives implement.
 *
 * Implementers can serve as the castType of a Schema column property: they declare
 * the DB column shape, supply Eloquent cast behavior (via Castable), and answer the
 * generator-dispatch surface for migration / faker / Filament / validation / SDK.
 *
 *   - Castable                  (Laravel's cast-from-class entry point)
 *   - SchemaCraftType           (DB column type + modifiers + validation rules)
 *   - FormatsApiOutput          (toApiRepresentation)
 *   - ParsesApiInput            (fromApiInput)
 *   - GeneratesFakerValue       (fakerExpression for factory generation)
 *   - GeneratesSdkType          (flat PHP type hint for SDK)
 *   - FilamentRenderable        (form field / table column / infolist entry)
 *
 * Note: CastsDataSchemaProperty (fromRaw/toRaw) is NOT part of this contract.
 * It's a DataSchema-internal concern — describing how a typed property hydrates
 * inside another DataSchema. DataSchema implements it directly; SchemaCraftColumn
 * primitives don't need it.
 *
 * There is NO fallback. If a class exists as a castType but doesn't implement this
 * interface, the framework throws at generation time rather than silently using a
 * generic placeholder. Implement or fail.
 *
 * First-party column-type primitives:
 *   - SchemaCraft\Primitives\JsonColumn       — typed JSON object column (extends DataSchema)
 *   - SchemaCraft\Primitives\BitmaskColumn    — closed bit-flag enumeration
 *   - SchemaCraft\Primitives\CollectionColumn — typed JSON array of DataSchema items
 *   - plus native PHP enums (BackedEnum / UnitEnum) — handled by reflection fallback
 *
 * Extend one of those rather than implementing this interface directly.
 */
interface SchemaCraftColumn extends Castable, FilamentRenderable, FormatsApiOutput, GeneratesFakerValue, GeneratesSdkType, ParsesApiInput, SchemaCraftType {}
