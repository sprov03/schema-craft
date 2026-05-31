<?php

namespace SchemaCraft\Generator\Sdk;

/**
 * Single source of truth for the Resource → Model → Data naming chain.
 *
 * Why this exists: pre-refactor, the strip-and-reappend dance was duplicated across three sites
 * (EndpointEnricher emitted a stripped `relatedModel`; the SDK appended "Data" to derive DTO names;
 * the visualizer re-appended "Resource" for display). All three assumed the `<ModelName>Resource`
 * suffix convention with no documentation of the coupling. Centralizing the transform here means
 * the convention is load-bearing in exactly one place — any future change to Resource naming
 * (namespacing, custom suffixes, FQCN-only contracts) updates one module instead of three sites
 * silently drifting.
 *
 * The unified API docs payload carries canonical Resource FQCNs directly; the SDK derives DTO
 * names via this utility; visualizer never needs to know about the transform — it uses what's served.
 */
final class SdkResourceNaming
{
    /**
     * Derive the bare model name from a Resource FQCN.
     *
     * "App\\Resources\\CatalogBrandResource" → "CatalogBrand"
     */
    public static function modelNameFromResourceFqcn(string $fqcn): string
    {
        return str_replace('Resource', '', class_basename($fqcn));
    }

    /**
     * Derive the SDK DTO class name from a Resource FQCN.
     *
     * "App\\Resources\\CatalogBrandResource" → "CatalogBrandData"
     */
    public static function dtoNameFromResourceFqcn(string $fqcn): string
    {
        return self::modelNameFromResourceFqcn($fqcn).'Data';
    }
}
