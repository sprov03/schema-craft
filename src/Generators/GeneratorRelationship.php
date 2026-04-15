<?php

namespace SchemaCraft\Generators;

use Illuminate\Support\Str;
use SchemaCraft\Scanner\RelationshipDefinition;

/**
 * Wraps a RelationshipDefinition with NameChain helpers for use in generator templates.
 *
 * Provides `$name` and `$relatedModel` as NameChain instances, plus convenience
 * methods for determining cardinality. All other RelationshipDefinition properties
 * are accessible via __get() delegation.
 *
 * ## Usage in templates
 *
 *     {!! $relationship->name->title !!}          // "Author"
 *     {!! $relationship->relatedModel->plural->title !!} // "Users"
 *     $relationship->isCollection()               // true for hasMany, belongsToMany, etc.
 *     $relationship->type                         // "belongsTo" (delegates to definition)
 */
class GeneratorRelationship
{
    /** The relationship name as a NameChain (e.g. 'author' → chain). */
    public readonly NameChain $name;

    /** The related model name as a NameChain (extracted from FQCN). */
    public readonly NameChain $relatedModel;

    public function __construct(public readonly RelationshipDefinition $definition)
    {
        $this->name = new NameChain($definition->name);
        $this->relatedModel = new NameChain(
            Str::snake(class_basename($definition->relatedModel))
        );
    }

    /**
     * Delegate property reads to the wrapped RelationshipDefinition.
     */
    public function __get(string $name): mixed
    {
        return $this->definition->$name;
    }

    public function __isset(string $name): bool
    {
        return isset($this->definition->$name);
    }

    /**
     * Whether this relationship represents a collection (hasMany, belongsToMany, etc.).
     */
    public function isCollection(): bool
    {
        return in_array($this->definition->type, [
            'hasMany',
            'belongsToMany',
            'morphMany',
            'morphToMany',
            'morphedByMany',
            'hasManyThrough',
        ]);
    }

    /**
     * Whether this relationship represents a single model (belongsTo, hasOne, etc.).
     */
    public function isSingular(): bool
    {
        return in_array($this->definition->type, [
            'hasOne',
            'belongsTo',
            'morphTo',
            'morphOne',
            'hasOneThrough',
        ]);
    }
}
