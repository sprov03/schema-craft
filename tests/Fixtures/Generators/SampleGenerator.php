<?php

namespace SchemaCraft\Tests\Fixtures\Generators;

use SchemaCraft\Generators\Input;
use SchemaCraft\Generators\SchemaCraftGenerator;
use SchemaCraft\Generators\Template;

class SampleGenerator extends SchemaCraftGenerator
{
    public function name(): string
    {
        return 'Sample Generator';
    }

    public function data(): array
    {
        return [
            'schema' => fn ($data) => Input::schemaSelector('Schema'),
            'class_name' => fn ($data) => Input::text('Class Name'),
        ];
    }

    public function templates(): array
    {
        return [
            Template::file('app/[class_name].php', 'generators.sample'),
        ];
    }
}
