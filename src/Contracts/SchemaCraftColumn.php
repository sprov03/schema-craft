<?php

namespace SchemaCraft\Contracts;

/**
 * The single interface all custom SchemaCraft column types must implement.
 *
 * Composing all seven sub-interfaces here ensures that any class used as a
 * property type in a Schema can fulfill every concern the framework touches:
 *   - DB schema declaration (SchemaCraftType)
 *   - Hydration/dehydration (CastsDataSchemaProperty)
 *   - API output shape (FormatsApiOutput)
 *   - API input parsing (ParsesApiInput)
 *   - Faker factory generation (GeneratesFakerValue)
 *   - SDK DTO type hints (GeneratesSdkType)
 *   - Filament rendering — form field, table column, infolist entry (FilamentRenderable)
 *
 * There is NO fallback. If a class exists as a castType but does not implement
 * this interface, SchemaCraft will throw a RuntimeException at generation time
 * rather than silently using a generic placeholder. Implement or fail.
 *
 * First-party abstract base classes are provided in SchemaCraft\Types\:
 *   - AbstractBitmaskType
 *   - AbstractJsonDtoType
 *   - AbstractCollectionType
 *
 * Extend one of those rather than implementing this interface directly.
 */
interface SchemaCraftColumn extends CastsDataSchemaProperty, FilamentRenderable, FormatsApiOutput, GeneratesFakerValue, GeneratesSdkType, ParsesApiInput, SchemaCraftType {}
