<?php

namespace SchemaCraft\Tests\Fixtures\Enums;

/**
 * Int-backed enum fixture for the SDK golden test.
 *
 * Why int-backed: the SDK generator resolves a backed enum's PHP type by
 * reflecting its backing type (SdkDataGenerator::resourcePhpType /
 * phpType -> ReflectionEnum::getBackingType()). PostStatus already covers the
 * string-backed case; this covers the int-backed case so the SDK DTO emits
 * `int` for an enum column rather than `string`.
 */
enum CatalogTier: int
{
    case Standard = 1;
    case Premium = 2;
    case Enterprise = 3;
}
