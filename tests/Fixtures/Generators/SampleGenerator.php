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

    public function inputs(): array
    {
        return [
            Input::text('class_name', 'Class Name'),
        ];
    }

    public function templates(): array
    {
        return [
            Template::file('generators.sample', 'app/[class_name].php'),
        ];
    }
}
