<?php

namespace SchemaCraft\Tests\Unit\Generators;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Generators\ResourceDirectoryValue;

class ResourceDirectoryValueTest extends TestCase
{
    public function test_path_is_preserved_as_given(): void
    {
        $value = new ResourceDirectoryValue('app/Filament/Admin/Resources');

        $this->assertSame('app/Filament/Admin/Resources', $value->path);
    }

    public function test_namespace_is_derived_from_path(): void
    {
        $value = new ResourceDirectoryValue('app/Filament/Admin/Resources');

        $this->assertSame('App\\Filament\\Admin\\Resources', $value->namespace);
    }

    public function test_first_segment_is_capitalized(): void
    {
        $value = new ResourceDirectoryValue('app/Resources');

        $this->assertSame('App\\Resources', $value->namespace);
    }

    public function test_subsequent_segments_preserve_case(): void
    {
        // Filament directory segments are already PascalCase; we must not
        // lowercase or otherwise transform them.
        $value = new ResourceDirectoryValue('app/Filament/Admin/Resources');

        $this->assertStringContainsString('Filament', $value->namespace);
        $this->assertStringContainsString('Admin', $value->namespace);
    }

    public function test_trailing_slash_is_stripped_from_path(): void
    {
        $value = new ResourceDirectoryValue('app/Filament/Admin/Resources/');

        $this->assertSame('app/Filament/Admin/Resources', $value->path);
        $this->assertSame('App\\Filament\\Admin\\Resources', $value->namespace);
    }

    public function test_leading_slash_is_handled(): void
    {
        $value = new ResourceDirectoryValue('/app/Filament/Admin/Resources');

        // Leading slash is preserved in path but trimmed for namespace derivation.
        $this->assertSame('App\\Filament\\Admin\\Resources', $value->namespace);
    }

    public function test_empty_path_yields_empty_namespace(): void
    {
        $value = new ResourceDirectoryValue('');

        $this->assertSame('', $value->path);
        $this->assertSame('', $value->namespace);
    }

    public function test_stringifies_to_path(): void
    {
        $value = new ResourceDirectoryValue('app/Filament/Admin/Resources');

        // Output-path interpolation ([resource_directory]) relies on __toString
        // returning the raw path so existing templates keep working.
        $this->assertSame('app/Filament/Admin/Resources', (string) $value);
    }

    public function test_single_segment_path(): void
    {
        $value = new ResourceDirectoryValue('app');

        $this->assertSame('App', $value->namespace);
    }

    public function test_custom_non_app_root(): void
    {
        // A config-defined custom directory that doesn't start with 'app'.
        $value = new ResourceDirectoryValue('packages/foo/src/Resources');

        $this->assertSame('Packages\\foo\\src\\Resources', $value->namespace);
    }

    public function test_derive_namespace_static_helper(): void
    {
        $this->assertSame(
            'App\\Filament\\Admin\\Resources',
            ResourceDirectoryValue::deriveNamespace('app/Filament/Admin/Resources'),
        );
    }
}
