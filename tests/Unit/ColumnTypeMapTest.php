<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Migration\ColumnTypeMap;

class ColumnTypeMapTest extends TestCase
{
    public function testNormalizesVarcharTypesToString(): void
    {
        $this->assertSame('string', ColumnTypeMap::normalize('varchar'));
        $this->assertSame('string', ColumnTypeMap::normalize('character varying'));
        $this->assertSame('string', ColumnTypeMap::normalize('VARCHAR'));
        $this->assertSame('string', ColumnTypeMap::normalize('varchar(255)'));
    }

    public function testNormalizesIntegerTypes(): void
    {
        $this->assertSame('integer', ColumnTypeMap::normalize('integer'));
        $this->assertSame('integer', ColumnTypeMap::normalize('int'));
        $this->assertSame('integer', ColumnTypeMap::normalize('int4'));
        $this->assertSame('bigInteger', ColumnTypeMap::normalize('bigint'));
        $this->assertSame('bigInteger', ColumnTypeMap::normalize('int8'));
        $this->assertSame('smallInteger', ColumnTypeMap::normalize('smallint'));
        $this->assertSame('smallInteger', ColumnTypeMap::normalize('int2'));
        $this->assertSame('tinyInteger', ColumnTypeMap::normalize('tinyint'));
    }

    public function testNormalizesBooleanTypes(): void
    {
        $this->assertSame('boolean', ColumnTypeMap::normalize('boolean'));
        $this->assertSame('boolean', ColumnTypeMap::normalize('bool'));
        $this->assertSame('boolean', ColumnTypeMap::normalize('tinyint(1)'));
    }

    public function testNormalizesTextTypes(): void
    {
        $this->assertSame('text', ColumnTypeMap::normalize('text'));
        $this->assertSame('mediumText', ColumnTypeMap::normalize('mediumtext'));
        $this->assertSame('longText', ColumnTypeMap::normalize('longtext'));
    }

    public function testNormalizesFloatAndDecimalTypes(): void
    {
        $this->assertSame('double', ColumnTypeMap::normalize('double'));
        $this->assertSame('double', ColumnTypeMap::normalize('double precision'));
        $this->assertSame('double', ColumnTypeMap::normalize('float8'));
        $this->assertSame('float', ColumnTypeMap::normalize('float'));
        $this->assertSame('float', ColumnTypeMap::normalize('real'));
        $this->assertSame('decimal', ColumnTypeMap::normalize('decimal'));
        $this->assertSame('decimal', ColumnTypeMap::normalize('numeric'));
    }

    public function testNormalizesJsonTypes(): void
    {
        $this->assertSame('json', ColumnTypeMap::normalize('json'));
        $this->assertSame('json', ColumnTypeMap::normalize('jsonb'));
    }

    public function testNormalizesTimestampAndDatetimeTypes(): void
    {
        $this->assertSame('timestamp', ColumnTypeMap::normalize('timestamp'));
        $this->assertSame('timestamp', ColumnTypeMap::normalize('datetime'));
        $this->assertSame('timestamp', ColumnTypeMap::normalize('timestamp without time zone'));
        $this->assertSame('date', ColumnTypeMap::normalize('date'));
        $this->assertSame('time', ColumnTypeMap::normalize('time'));
        $this->assertSame('year', ColumnTypeMap::normalize('year'));
    }

    public function testNormalizesUuidAndUlidTypes(): void
    {
        $this->assertSame('uuid', ColumnTypeMap::normalize('uuid'));
        $this->assertSame('ulid', ColumnTypeMap::normalize('ulid'));
    }

    public function testMapsCanonicalTypesToBlueprintMethods(): void
    {
        $this->assertSame('string', ColumnTypeMap::toBlueprintMethod('string'));
        $this->assertSame('integer', ColumnTypeMap::toBlueprintMethod('integer'));
        $this->assertSame('boolean', ColumnTypeMap::toBlueprintMethod('boolean'));
        $this->assertSame('text', ColumnTypeMap::toBlueprintMethod('text'));
        $this->assertSame('json', ColumnTypeMap::toBlueprintMethod('json'));
        $this->assertSame('timestamp', ColumnTypeMap::toBlueprintMethod('timestamp'));
        $this->assertSame('decimal', ColumnTypeMap::toBlueprintMethod('decimal'));
        $this->assertSame('unsignedBigInteger', ColumnTypeMap::toBlueprintMethod('unsignedBigInteger'));
    }

    public function testParsesTypeParamsForLength(): void
    {
        $this->assertSame(['length' => 100], ColumnTypeMap::parseTypeParams('varchar(100)'));
        $this->assertSame(['length' => 255], ColumnTypeMap::parseTypeParams('varchar(255)'));
    }

    public function testParsesTypeParamsForPrecisionAndScale(): void
    {
        $this->assertSame(['precision' => 10, 'scale' => 2], ColumnTypeMap::parseTypeParams('decimal(10,2)'));
        $this->assertSame(['precision' => 8, 'scale' => 4], ColumnTypeMap::parseTypeParams('decimal(8, 4)'));
    }

    public function testReturnsEmptyArrayForTypesWithoutParams(): void
    {
        $this->assertSame([], ColumnTypeMap::parseTypeParams('integer'));
        $this->assertSame([], ColumnTypeMap::parseTypeParams('text'));
        $this->assertSame([], ColumnTypeMap::parseTypeParams('boolean'));
    }

    public function testDetectsUnsignedTypes(): void
    {
        $this->assertTrue(ColumnTypeMap::isUnsigned('unsigned bigint'));
        $this->assertTrue(ColumnTypeMap::isUnsigned('UNSIGNED INTEGER'));
        $this->assertFalse(ColumnTypeMap::isUnsigned('integer'));
        $this->assertFalse(ColumnTypeMap::isUnsigned('bigint'));
    }

    public function testHandlesCaseInsensitivity(): void
    {
        $this->assertSame('string', ColumnTypeMap::normalize('VARCHAR'));
        $this->assertSame('integer', ColumnTypeMap::normalize('INTEGER'));
        $this->assertSame('boolean', ColumnTypeMap::normalize('BOOLEAN'));
        $this->assertSame('json', ColumnTypeMap::normalize('JSON'));
    }
}
