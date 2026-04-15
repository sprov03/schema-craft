<?php

namespace SchemaCraft\Tests\Unit\Generators;

use PHPUnit\Framework\TestCase;
use SchemaCraft\Generators\Template;
use SchemaCraft\Generators\TemplateDefinition;

class TemplateTest extends TestCase
{
    public function test_file_creates_correct_definition(): void
    {
        $template = Template::file('app/[class_name].php', 'generators.my-template');

        $this->assertInstanceOf(TemplateDefinition::class, $template);
        $this->assertSame('app/[class_name].php', $template->outputPath);
        $this->assertSame('generators.my-template', $template->viewName);
        $this->assertSame([], $template->extraVariables);
        $this->assertNull($template->iterateOver);
        $this->assertNull($template->iterateAs);
        $this->assertNull($template->iterateFilter);
    }

    public function test_file_with_extra_variables(): void
    {
        $template = Template::file('app/output.php', 'generators.template', [
            'ClassName' => '[schema.model.title]Resource',
        ]);

        $this->assertSame(['ClassName' => '[schema.model.title]Resource'], $template->extraVariables);
    }

    public function test_for_each_returns_template_definitions_with_iteration(): void
    {
        $templates = Template::forEach('schema.relationships', 'rel', [
            Template::file('app/[rel.name.title].php', 'generators.relation'),
        ]);

        $this->assertCount(1, $templates);
        $this->assertInstanceOf(TemplateDefinition::class, $templates[0]);
        $this->assertSame('schema.relationships', $templates[0]->iterateOver);
        $this->assertSame('rel', $templates[0]->iterateAs);
        $this->assertNull($templates[0]->iterateFilter);
    }

    public function test_for_each_with_filter(): void
    {
        $templates = Template::forEach('schema.relationships', 'rel', [
            Template::file('app/[rel.name.title].php', 'generators.relation'),
        ], 'collection');

        $this->assertSame('collection', $templates[0]->iterateFilter);
    }

    public function test_for_each_relationship_is_sugar_for_for_each(): void
    {
        $templates = Template::forEachRelationship('schema', 'relationship', [
            Template::file('app/[relationship.name.title].php', 'generators.relation'),
        ], 'collection');

        $this->assertCount(1, $templates);
        $this->assertSame('schema.relationships', $templates[0]->iterateOver);
        $this->assertSame('relationship', $templates[0]->iterateAs);
        $this->assertSame('collection', $templates[0]->iterateFilter);
    }

    public function test_for_each_with_multiple_templates(): void
    {
        $templates = Template::forEach('schema.relationships', 'rel', [
            Template::file('app/[rel.name.title].php', 'generators.one'),
            Template::file('tests/[rel.name.title]Test.php', 'generators.two'),
        ]);

        $this->assertCount(2, $templates);
        $this->assertSame('schema.relationships', $templates[0]->iterateOver);
        $this->assertSame('schema.relationships', $templates[1]->iterateOver);
    }
}
