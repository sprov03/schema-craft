<?php

namespace SchemaCraft\Tests\Unit\Generators;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Generators\Template;
use SchemaCraft\Generators\TemplateDefinition;

class TemplateTest extends TestCase
{
    public function test_file_creates_correct_definition(): void
    {
        $template = Template::file('generators.my-template', 'app/[class_name].php');

        $this->assertInstanceOf(TemplateDefinition::class, $template);
        $this->assertSame('generators.my-template', $template->viewName);
        $this->assertSame('app/[class_name].php', $template->outputPath);
    }
}
