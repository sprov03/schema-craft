<?php

namespace SchemaCraft\Generator;

use Illuminate\Support\Str;
use SchemaCraft\Scanner\RelationshipDefinition;
use SchemaCraft\Scanner\TableDefinition;

/**
 * Generates PHPUnit model relationship tests from a TableDefinition.
 *
 * Produces tests that verify BelongsTo relationships return the correct model class
 * using Akceli-style static factories for model creation.
 */
class ModelTestGenerator
{
    /**
     * Generate the full model test file content.
     */
    public function generate(
        TableDefinition $table,
        string $modelName,
        string $modelNamespace = 'App\\Models',
        string $factoryNamespace = 'Database\\Factories',
        string $testNamespace = 'Tests\\Unit',
    ): string {
        $testClass = $modelName.'ModelTest';
        $factoryClass = $modelName.'Factory';
        $modelVariable = Str::camel($modelName);
        $belongsToRelationships = $this->getBelongsToRelationships($table);

        $imports = $this->buildImports(
            $modelName,
            $modelNamespace,
            $factoryNamespace,
            $factoryClass,
            $belongsToRelationships,
        );

        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = "namespace {$testNamespace};";
        $lines[] = '';

        foreach ($imports as $import) {
            $lines[] = "use {$import};";
        }

        $lines[] = '';
        $lines[] = "class {$testClass} extends TestCase";
        $lines[] = '{';
        $lines[] = '    use RefreshDatabase;';
        $lines[] = '';

        // Test: model can be created
        $lines[] = '    public function test_can_create_model(): void';
        $lines[] = '    {';
        $lines[] = "        \${$modelVariable} = {$factoryClass}::createDefault();";
        $lines[] = '';
        $lines[] = "        \$this->assertInstanceOf({$modelName}::class, \${$modelVariable});";
        $lines[] = "        \$this->assertTrue(\${$modelVariable}->exists);";
        $lines[] = '    }';

        // Test: each BelongsTo relationship
        foreach ($belongsToRelationships as $rel) {
            $relatedModelClass = class_basename($rel->relatedModel);
            $relationName = $rel->name;

            $lines[] = '';

            if ($rel->nullable) {
                $lines[] = "    public function test_{$relationName}_relationship_returns_correct_model_or_null(): void";
                $lines[] = '    {';
                $lines[] = "        \${$modelVariable} = {$factoryClass}::createDefault();";
                $lines[] = '';
                $lines[] = '        $this->assertTrue(';
                $lines[] = "            \${$modelVariable}->{$relationName} === null || \${$modelVariable}->{$relationName} instanceof {$relatedModelClass}";
                $lines[] = '        );';
                $lines[] = '    }';
            } else {
                $lines[] = "    public function test_{$relationName}_relationship_returns_correct_model(): void";
                $lines[] = '    {';
                $lines[] = "        \${$modelVariable} = {$factoryClass}::createDefault();";
                $lines[] = '';
                $lines[] = "        \$this->assertInstanceOf({$relatedModelClass}::class, \${$modelVariable}->{$relationName});";
                $lines[] = '    }';
            }
        }

        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Get BelongsTo relationships.
     *
     * @return RelationshipDefinition[]
     */
    private function getBelongsToRelationships(TableDefinition $table): array
    {
        return array_values(array_filter(
            $table->relationships,
            fn (RelationshipDefinition $rel) => $rel->type === 'belongsTo',
        ));
    }

    /**
     * Build sorted import list.
     *
     * @param  RelationshipDefinition[]  $belongsToRelationships
     * @return string[]
     */
    private function buildImports(
        string $modelName,
        string $modelNamespace,
        string $factoryNamespace,
        string $factoryClass,
        array $belongsToRelationships,
    ): array {
        $imports = [];

        // Model import
        $imports[] = "{$modelNamespace}\\{$modelName}";

        // Factory import
        $imports[] = "{$factoryNamespace}\\{$factoryClass}";

        // Related model imports
        foreach ($belongsToRelationships as $rel) {
            $imports[] = $rel->relatedModel;
        }

        // Test framework imports
        $imports[] = 'Illuminate\\Foundation\\Testing\\RefreshDatabase';
        $imports[] = 'Tests\\TestCase';

        sort($imports);

        return $imports;
    }
}
