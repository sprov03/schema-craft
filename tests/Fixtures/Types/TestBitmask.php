<?php

namespace SchemaCraft\Tests\Fixtures\Types;

use SchemaCraft\Primitives\MediumBitmask;

class TestBitmask extends MediumBitmask
{
    const READ = 1;
    const WRITE = 2;
    const EXECUTE = 4;

    protected static function flagMetadata(): array
    {
        return [
            'WRITE' => ['label' => 'Write access'],
            'EXECUTE' => ['label' => 'Execute', 'description' => 'Run scripts in this scope'],
        ];
    }
}
