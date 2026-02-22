<?php

namespace SchemaCraft\Writer;

use Illuminate\Support\Str;
use InvalidArgumentException;

class RelationshipParser
{
    private const VALID_TYPES = [
        'belongsTo',
        'hasMany',
        'hasOne',
        'belongsToMany',
        'morphTo',
        'morphOne',
        'morphMany',
        'morphToMany',
    ];

    /**
     * Parse a relationship definition string into instructions.
     *
     * Supported formats:
     *   User->belongsTo(Account)
     *   User->BelongsTo(Account)->HasMany(User)
     *   User->$owner:belongsTo(Account)->$owners:hasMany(User)
     *
     * @return RelationshipInstruction[]
     */
    public function parse(string $input): array
    {
        $segments = explode('->', $input);

        if (count($segments) < 2) {
            throw new InvalidArgumentException(
                "Invalid format. Expected: Model->relationType(RelatedModel). Got: {$input}"
            );
        }

        $primarySchema = trim(array_shift($segments));

        if (! preg_match('/^\w+$/', $primarySchema)) {
            throw new InvalidArgumentException(
                "Invalid schema name: {$primarySchema}. Expected a simple name like 'User' or 'Dog'."
            );
        }

        $instructions = [];
        $currentSchema = $primarySchema;

        foreach ($segments as $index => $segment) {
            $parsed = $this->parseSegment(trim($segment));

            $instructions[] = new RelationshipInstruction(
                schemaName: $currentSchema,
                relationshipType: $parsed['type'],
                relatedModelName: $parsed['model'],
                propertyName: $parsed['property'],
                morphName: $parsed['morphName'],
            );

            $currentSchema = $parsed['model'];
        }

        return $instructions;
    }

    /**
     * @return array{type: string, model: string, property: ?string, morphName: ?string}
     */
    private function parseSegment(string $segment): array
    {
        $pattern = '/^(?:\$(\w+):)?(\w+)\((\w+)(?:,\s*\'?(\w+)\'?)?\)$/';

        if (! preg_match($pattern, $segment, $matches)) {
            throw new InvalidArgumentException(
                "Invalid relationship segment: {$segment}. Expected format: relationType(Model) or \$prop:relationType(Model)"
            );
        }

        $property = $matches[1] !== '' ? $matches[1] : null;
        $type = Str::camel($matches[2]);
        $model = $matches[3];
        $morphName = isset($matches[4]) && $matches[4] !== '' ? $matches[4] : null;

        if (! in_array($type, self::VALID_TYPES)) {
            throw new InvalidArgumentException(
                "Unknown relationship type: {$matches[2]}. Valid types: ".implode(', ', self::VALID_TYPES)
            );
        }

        return [
            'type' => $type,
            'model' => $model,
            'property' => $property,
            'morphName' => $morphName,
        ];
    }
}
