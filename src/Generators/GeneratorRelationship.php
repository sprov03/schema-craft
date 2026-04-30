<?php

namespace SchemaCraft\Generators;

use Illuminate\Support\Str;
use SchemaCraft\Scanner\RelationshipDefinition;
use SchemaCraft\Scanner\SchemaResolver;
use SchemaCraft\Scanner\SchemaScanner;

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
 *     {!! $relationship->relatedModelClass !!}    // "App\\Models\\User" (FQCN)
 *     $relationship->isCollection()               // true for hasMany, belongsToMany, etc.
 *     $relationship->type                         // "belongsTo" (delegates to definition)
 */
class GeneratorRelationship
{
    /** The relationship name as a NameChain (e.g. 'author' → chain). */
    public readonly NameChain $name;

    /** The related model name as a NameChain (extracted from FQCN). */
    public readonly NameChain $relatedModel;

    /** The full FQCN of the related model, e.g. 'App\Models\User'. */
    public readonly string $relatedModelClass;

    public function __construct(public readonly RelationshipDefinition $definition)
    {
        $this->name = new NameChain($definition->name);
        $this->relatedModelClass = $definition->relatedModel;
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

    /**
     * Resolve the display column of the related schema using its #[Title] attribute.
     *
     * Used in Filament dot-notation column/entry generation, e.g.:
     *     TextColumn::make('asteriskServer.name')  // 'name' comes from AsteriskServerSchema's #[Title]
     *
     * Returns null when the related schema cannot be found, letting the template
     * fall back to a sensible default (typically 'name').
     */
    public function relatedTitleColumn(): ?string
    {
        $schemaClass = SchemaResolver::findByModel($this->relatedModelClass);

        if ($schemaClass === null || ! class_exists($schemaClass)) {
            return null;
        }

        $table = (new SchemaScanner($schemaClass))->scan();

        return $table->titleColumns[0] ?? null;
    }
}
