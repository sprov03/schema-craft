<?php

namespace SchemaCraft\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Attributes\CollectionOf;
use SchemaCraft\Scanner\ResourceScanner;
use SchemaCraft\SchemaCraftResource;

/**
 * ResourceScanner now delegates property detection to the shared TypedPropertyReflector.
 * These pin the response-side behavior: the column/relationship split, the new
 * inherit-from-parents policy, and the bare-Collection guard (a response-only overlay).
 */
class ResourceScannerTest extends TestCase
{
    private function names(array $rows): array
    {
        return array_map(fn ($r) => $r['name'], $rows);
    }

    public function test_inherits_properties_from_a_base_resource(): void
    {
        $def = (new ResourceScanner)->scanClass(ScannerChildResource::class);

        // Inherited from the base Resource (new policy) PLUS the concrete class's own field.
        $this->assertContains('id', $this->names($def->properties));
        $this->assertContains('created_at', $this->names($def->properties));
        $this->assertContains('title', $this->names($def->properties));

        // JsonResource framework internals (e.g. $resource) must NOT leak into the shape.
        $this->assertNotContains('resource', $this->names($def->properties));
    }

    public function test_splits_singular_and_collection_relationships_from_columns(): void
    {
        $def = (new ResourceScanner)->scanClass(ScannerRelResource::class);

        $this->assertSame(['id'], $this->names($def->properties));

        $byName = [];
        foreach ($def->relationships as $r) {
            $byName[$r['name']] = $r;
        }
        $this->assertSame('singular', $byName['primary']['type']);
        $this->assertFalse($byName['primary']['isCollection']);
        $this->assertSame('collection', $byName['variants']['type']);
        $this->assertTrue($byName['variants']['isCollection']);
        $this->assertSame(ScannerVariantResource::class, $byName['variants']['resource']);
    }

    public function test_bare_collection_without_attribute_still_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ResourceScanner)->scanClass(ScannerBadResource::class);
    }
}

class ScannerVariantResource extends SchemaCraftResource
{
    public int $id;
}

abstract class ScannerBaseResource extends SchemaCraftResource
{
    public int $id;

    public ?string $created_at;
}

class ScannerChildResource extends ScannerBaseResource
{
    public string $title;
}

class ScannerRelResource extends SchemaCraftResource
{
    public int $id;

    public ?ScannerVariantResource $primary;

    #[CollectionOf(ScannerVariantResource::class)]
    public \Illuminate\Support\Collection $variants;
}

class ScannerBadResource extends SchemaCraftResource
{
    public \Illuminate\Support\Collection $items;
}
