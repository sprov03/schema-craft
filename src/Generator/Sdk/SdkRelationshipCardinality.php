<?php

namespace SchemaCraft\Generator\Sdk;

/**
 * Single source of truth for how a relationship type maps to SDK DTO cardinality.
 *
 * Why this exists: DB-level relationship mechanics (belongsToMany / hasManyThrough /
 * morphMany / etc.) collapse to the Resource layer's binary "collection of X" vs
 * "singular X" — what actually matters for SDK shape. Centralizing the classification
 * here means EndpointEnricher's resource-walked dep registration and any future
 * cardinality-aware code share one decision. Anything not classified here (e.g.
 * morphTo) is treated as `mixed`.
 */
final class SdkRelationshipCardinality
{
    /** Relationship types that serialize to a list of related records. */
    public const COLLECTION = ['hasMany', 'belongsToMany', 'morphMany', 'morphToMany', 'hasManyThrough'];

    /** Relationship types that serialize to a single related record. */
    public const SINGULAR = ['hasOne', 'morphOne', 'hasOneThrough'];

    public static function isCollection(string $type): bool
    {
        return in_array($type, self::COLLECTION, true);
    }

    public static function isSingular(string $type): bool
    {
        return in_array($type, self::SINGULAR, true);
    }
}
