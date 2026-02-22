<?php

namespace SchemaCraft\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SchemaCraft\Writer\RelationshipParser;

class RelationshipParserTest extends TestCase
{
    private RelationshipParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new RelationshipParser;
    }

    // ── Single relationship ─────────────────────────────

    public function test_parses_single_belongs_to(): void
    {
        $instructions = $this->parser->parse('User->belongsTo(Account)');

        $this->assertCount(1, $instructions);
        $this->assertEquals('User', $instructions[0]->schemaName);
        $this->assertEquals('belongsTo', $instructions[0]->relationshipType);
        $this->assertEquals('Account', $instructions[0]->relatedModelName);
        $this->assertNull($instructions[0]->propertyName);
        $this->assertNull($instructions[0]->morphName);
    }

    // ── Relationship with inverse ───────────────────────

    public function test_parses_relationship_with_inverse(): void
    {
        $instructions = $this->parser->parse('User->belongsTo(Account)->hasMany(User)');

        $this->assertCount(2, $instructions);

        $this->assertEquals('User', $instructions[0]->schemaName);
        $this->assertEquals('belongsTo', $instructions[0]->relationshipType);
        $this->assertEquals('Account', $instructions[0]->relatedModelName);

        $this->assertEquals('Account', $instructions[1]->schemaName);
        $this->assertEquals('hasMany', $instructions[1]->relationshipType);
        $this->assertEquals('User', $instructions[1]->relatedModelName);
    }

    // ── StudlyCase types ────────────────────────────────

    public function test_parses_studly_case_types(): void
    {
        $instructions = $this->parser->parse('User->BelongsTo(Account)->HasMany(User)');

        $this->assertCount(2, $instructions);
        $this->assertEquals('belongsTo', $instructions[0]->relationshipType);
        $this->assertEquals('hasMany', $instructions[1]->relationshipType);
    }

    // ── With property name prefix ───────────────────────

    public function test_parses_with_property_name(): void
    {
        $instructions = $this->parser->parse('User->$owner:belongsTo(Account)->$owners:hasMany(User)');

        $this->assertCount(2, $instructions);

        $this->assertEquals('owner', $instructions[0]->propertyName);
        $this->assertEquals('User', $instructions[0]->schemaName);
        $this->assertEquals('belongsTo', $instructions[0]->relationshipType);

        $this->assertEquals('owners', $instructions[1]->propertyName);
        $this->assertEquals('Account', $instructions[1]->schemaName);
        $this->assertEquals('hasMany', $instructions[1]->relationshipType);
    }

    // ── Morph with morph name ───────────────────────────

    public function test_parses_morph_with_morph_name(): void
    {
        $instructions = $this->parser->parse("Post->morphMany(Comment,'commentable')");

        $this->assertCount(1, $instructions);
        $this->assertEquals('Post', $instructions[0]->schemaName);
        $this->assertEquals('morphMany', $instructions[0]->relationshipType);
        $this->assertEquals('Comment', $instructions[0]->relatedModelName);
        $this->assertEquals('commentable', $instructions[0]->morphName);
    }

    // ── All relationship types ──────────────────────────

    public function test_parses_all_relationship_types(): void
    {
        $types = [
            'belongsTo', 'hasMany', 'hasOne', 'belongsToMany',
            'morphTo', 'morphOne', 'morphMany', 'morphToMany',
        ];

        foreach ($types as $type) {
            $instructions = $this->parser->parse("User->{$type}(Account)");
            $this->assertEquals($type, $instructions[0]->relationshipType, "Failed for type: {$type}");
        }
    }

    // ── StudlyCase for all types ────────────────────────

    public function test_parses_studly_case_for_all_types(): void
    {
        $mappings = [
            'BelongsTo' => 'belongsTo',
            'HasMany' => 'hasMany',
            'HasOne' => 'hasOne',
            'BelongsToMany' => 'belongsToMany',
            'MorphTo' => 'morphTo',
            'MorphOne' => 'morphOne',
            'MorphMany' => 'morphMany',
            'MorphToMany' => 'morphToMany',
        ];

        foreach ($mappings as $studly => $camel) {
            $instructions = $this->parser->parse("User->{$studly}(Account)");
            $this->assertEquals($camel, $instructions[0]->relationshipType, "Failed for StudlyCase: {$studly}");
        }
    }

    // ── Invalid format ──────────────────────────────────

    public function test_throws_on_invalid_format(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->parser->parse('invalid');
    }

    // ── Unknown relationship type ───────────────────────

    public function test_throws_on_unknown_relationship_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown relationship type');

        $this->parser->parse('User->wrongType(Dog)');
    }

    // ── Invalid segment format ──────────────────────────

    public function test_throws_on_invalid_segment_format(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid relationship segment');

        $this->parser->parse('User->belongsTo');
    }

    // ── Property name with StudlyCase ───────────────────

    public function test_parses_property_with_studly_case_type(): void
    {
        $instructions = $this->parser->parse('User->$owner:BelongsTo(Account)');

        $this->assertCount(1, $instructions);
        $this->assertEquals('owner', $instructions[0]->propertyName);
        $this->assertEquals('belongsTo', $instructions[0]->relationshipType);
    }
}
