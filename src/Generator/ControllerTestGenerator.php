<?php

namespace SchemaCraft\Generator;

use Illuminate\Support\Str;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Scanner\RelationshipDefinition;
use SchemaCraft\Scanner\TableDefinition;

/**
 * Generates PHPUnit controller CRUD tests from a TableDefinition.
 *
 * Produces tests for getCollection, get, create, update, and delete endpoints
 * using Akceli-style static factories and Sanctum authentication.
 */
class ControllerTestGenerator
{
    private const SKIP_COLUMNS = ['created_at', 'updated_at', 'deleted_at'];

    private FakerMethodMapper $fakerMapper;

    public function __construct(?FakerMethodMapper $fakerMapper = null)
    {
        $this->fakerMapper = $fakerMapper ?? new FakerMethodMapper;
    }

    /**
     * Generate the full controller test file content.
     */
    public function generate(
        TableDefinition $table,
        string $modelName,
        string $modelNamespace = 'App\\Models',
        string $factoryNamespace = 'Database\\Factories',
        string $routePrefix = 'api',
    ): string {
        $testClass = $modelName.'ControllerTest';
        $factoryClass = $modelName.'Factory';
        $modelVariable = Str::camel($modelName);
        $routeSegment = Str::snake(Str::pluralStudly($modelName), '-');
        $fullRoutePrefix = rtrim($routePrefix, '/').'/'.$routeSegment;

        $editableColumns = $this->getEditableColumns($table);
        $visibleColumns = $this->getVisibleColumns($table);
        $belongsToRelationships = $this->getBelongsToRelationships($table);

        $imports = $this->buildImports($modelName, $modelNamespace, $factoryNamespace, $factoryClass, $belongsToRelationships);

        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = 'namespace Tests\Feature\Controllers;';
        $lines[] = '';

        foreach ($imports as $import) {
            $lines[] = "use {$import};";
        }

        $lines[] = '';
        $lines[] = "class {$testClass} extends TestCase";
        $lines[] = '{';
        $lines[] = '    use RefreshDatabase;';
        $lines[] = '';

        // testCanGetCollection
        $lines = array_merge($lines, $this->buildGetCollectionTest(
            $factoryClass,
            $fullRoutePrefix,
            $visibleColumns,
        ));

        $lines[] = '';

        // testCanGet
        $lines = array_merge($lines, $this->buildGetTest(
            $modelName,
            $factoryClass,
            $modelVariable,
            $fullRoutePrefix,
            $visibleColumns,
        ));

        $lines[] = '';

        // testCanCreate
        $lines = array_merge($lines, $this->buildCreateTest(
            $modelName,
            $fullRoutePrefix,
            $editableColumns,
            $belongsToRelationships,
            $table->tableName,
        ));

        $lines[] = '';

        // testCanUpdate
        $lines = array_merge($lines, $this->buildUpdateTest(
            $modelName,
            $factoryClass,
            $modelVariable,
            $fullRoutePrefix,
            $editableColumns,
            $belongsToRelationships,
        ));

        $lines[] = '';

        // testCanDelete
        $lines = array_merge($lines, $this->buildDeleteTest(
            $factoryClass,
            $modelVariable,
            $fullRoutePrefix,
        ));

        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @return string[]
     */
    private function buildGetCollectionTest(
        string $factoryClass,
        string $fullRoutePrefix,
        array $visibleColumns,
    ): array {
        $columnKeys = $this->columnKeysArray($visibleColumns);

        return [
            '    public function test_can_get_collection(): void',
            '    {',
            '        $user = UserFactory::createDefault();',
            "        \$this->actingAs(\$user, 'sanctum');",
            '',
            "        {$factoryClass}::createDefaults(2);",
            '',
            "        \$response = \$this->getJson('/{$fullRoutePrefix}');",
            '',
            '        $response->assertOk();',
            '        $response->assertJsonStructure([',
            "            'data' => [",
            "                '*' => {$columnKeys},",
            '            ],',
            '        ]);',
            '    }',
        ];
    }

    /**
     * @return string[]
     */
    private function buildGetTest(
        string $modelName,
        string $factoryClass,
        string $modelVariable,
        string $fullRoutePrefix,
        array $visibleColumns,
    ): array {
        $columnKeys = $this->columnKeysArray($visibleColumns);

        return [
            '    public function test_can_get_single(): void',
            '    {',
            '        $user = UserFactory::createDefault();',
            "        \$this->actingAs(\$user, 'sanctum');",
            '',
            "        \${$modelVariable} = {$factoryClass}::createDefault();",
            '',
            "        \$response = \$this->getJson('/{$fullRoutePrefix}/' . \${$modelVariable}->id);",
            '',
            '        $response->assertOk();',
            '        $response->assertJsonStructure([',
            "            'data' => {$columnKeys},",
            '        ]);',
            '    }',
        ];
    }

    /**
     * @return string[]
     */
    private function buildCreateTest(
        string $modelName,
        string $fullRoutePrefix,
        array $editableColumns,
        array $belongsToRelationships,
        string $tableName,
    ): array {
        $lines = [];
        $lines[] = '    public function test_can_create(): void';
        $lines[] = '    {';
        $lines[] = '        $user = UserFactory::createDefault();';
        $lines[] = "        \$this->actingAs(\$user, 'sanctum');";
        $lines[] = '';

        // Create related models for FK columns
        foreach ($belongsToRelationships as $rel) {
            $relatedClass = class_basename($rel->relatedModel);
            $relatedFactory = $relatedClass.'Factory';
            $relatedVar = Str::camel($rel->name);
            $lines[] = "        \${$relatedVar} = {$relatedFactory}::createDefault();";
        }

        if (! empty($belongsToRelationships)) {
            $lines[] = '';
        }

        $lines[] = '        $request = [';

        foreach ($editableColumns as $column) {
            $value = $this->testValue($column);
            $lines[] = "            '{$column->name}' => {$value},";
        }

        // Add FK columns from relationships
        foreach ($belongsToRelationships as $rel) {
            $fkName = $this->foreignKeyName($rel);
            $relatedVar = Str::camel($rel->name);
            $lines[] = "            '{$fkName}' => \${$relatedVar}->id,";
        }

        $lines[] = '        ];';
        $lines[] = '';
        $lines[] = "        \$response = \$this->postJson('/{$fullRoutePrefix}', \$request);";
        $lines[] = '';
        $lines[] = '        $response->assertCreated();';
        $lines[] = "        \$this->assertDatabaseHas('{$tableName}', [";

        // Assert a subset of the request data is in the DB
        $firstEditable = $editableColumns[0] ?? null;
        if ($firstEditable) {
            $lines[] = "            '{$firstEditable->name}' => \$request['{$firstEditable->name}'],";
        }

        $lines[] = '        ]);';
        $lines[] = '    }';

        return $lines;
    }

    /**
     * @return string[]
     */
    private function buildUpdateTest(
        string $modelName,
        string $factoryClass,
        string $modelVariable,
        string $fullRoutePrefix,
        array $editableColumns,
        array $belongsToRelationships,
    ): array {
        $lines = [];
        $lines[] = '    public function test_can_update(): void';
        $lines[] = '    {';
        $lines[] = '        $user = UserFactory::createDefault();';
        $lines[] = "        \$this->actingAs(\$user, 'sanctum');";
        $lines[] = '';
        $lines[] = "        \${$modelVariable} = {$factoryClass}::createDefault();";
        $lines[] = '';
        $lines[] = '        $request = [';

        foreach ($editableColumns as $column) {
            $value = $this->testValue($column);
            $lines[] = "            '{$column->name}' => {$value},";
        }

        // Add FK columns from relationships
        foreach ($belongsToRelationships as $rel) {
            $fkName = $this->foreignKeyName($rel);
            $lines[] = "            '{$fkName}' => \${$modelVariable}->{$fkName},";
        }

        $lines[] = '        ];';
        $lines[] = '';
        $lines[] = "        \$response = \$this->putJson('/{$fullRoutePrefix}/' . \${$modelVariable}->id, \$request);";
        $lines[] = '';
        $lines[] = '        $response->assertOk();';
        $lines[] = '    }';

        return $lines;
    }

    /**
     * @return string[]
     */
    private function buildDeleteTest(
        string $factoryClass,
        string $modelVariable,
        string $fullRoutePrefix,
    ): array {
        return [
            '    public function test_can_delete(): void',
            '    {',
            '        $user = UserFactory::createDefault();',
            "        \$this->actingAs(\$user, 'sanctum');",
            '',
            "        \${$modelVariable} = {$factoryClass}::createDefault();",
            '',
            "        \$response = \$this->deleteJson('/{$fullRoutePrefix}/' . \${$modelVariable}->id);",
            '',
            '        $response->assertNoContent();',
            '    }',
        ];
    }

    /**
     * Get columns that should be included in create/update test data.
     *
     * @return ColumnDefinition[]
     */
    private function getEditableColumns(TableDefinition $table): array
    {
        $skipSet = array_flip(self::SKIP_COLUMNS);

        if ($table->hasTimestamps) {
            $skipSet['created_at'] = true;
            $skipSet['updated_at'] = true;
        }

        if ($table->hasSoftDeletes) {
            $skipSet['deleted_at'] = true;
        }

        $columns = [];

        foreach ($table->columns as $column) {
            if ($column->primary || $column->autoIncrement) {
                continue;
            }

            if (isset($skipSet[$column->name])) {
                continue;
            }

            // Skip FK columns — they are handled separately via relationships
            if ($this->isForeignKeyColumn($column->name, $table)) {
                continue;
            }

            $columns[] = $column;
        }

        return $columns;
    }

    /**
     * Get columns that are visible in JSON responses (not hidden).
     *
     * @return ColumnDefinition[]
     */
    private function getVisibleColumns(TableDefinition $table): array
    {
        $hiddenSet = array_flip($table->hidden);

        $columns = [];

        foreach ($table->columns as $column) {
            if (isset($hiddenSet[$column->name])) {
                continue;
            }

            // Skip timestamp/soft delete columns (usually not in resource responses)
            if (in_array($column->name, self::SKIP_COLUMNS) && ($table->hasTimestamps || $table->hasSoftDeletes)) {
                continue;
            }

            $columns[] = $column;
        }

        return $columns;
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
     * Check if a column name is a foreign key managed by a BelongsTo relationship.
     */
    private function isForeignKeyColumn(string $columnName, TableDefinition $table): bool
    {
        foreach ($table->relationships as $rel) {
            if ($rel->type !== 'belongsTo') {
                continue;
            }

            $fkName = $this->foreignKeyName($rel);

            if ($fkName === $columnName) {
                return true;
            }
        }

        return false;
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

        // Factory import
        $imports[] = "{$factoryNamespace}\\{$factoryClass}";

        // UserFactory always needed for auth
        if ($factoryClass !== 'UserFactory') {
            $imports[] = "{$factoryNamespace}\\UserFactory";
        }

        // Related model factory imports for FK setup in create test
        foreach ($belongsToRelationships as $rel) {
            $relatedClass = class_basename($rel->relatedModel);
            $relatedFactory = "{$factoryNamespace}\\{$relatedClass}Factory";
            if (! in_array($relatedFactory, $imports)) {
                $imports[] = $relatedFactory;
            }
        }

        // Test framework
        $imports[] = 'Illuminate\\Foundation\\Testing\\RefreshDatabase';
        $imports[] = 'Tests\\TestCase';

        sort($imports);

        return $imports;
    }

    /**
     * Build a JSON structure assertion array string from columns.
     *
     * @param  ColumnDefinition[]  $columns
     */
    private function columnKeysArray(array $columns): string
    {
        $keys = array_map(fn (ColumnDefinition $col) => "'{$col->name}'", $columns);

        if (count($keys) <= 5) {
            return '['.implode(', ', $keys).']';
        }

        $indented = array_map(fn (string $key) => "                    {$key}", $keys);

        return "[\n".implode(",\n", $indented).",\n                ]";
    }

    /**
     * Generate a PHP test value for a column.
     */
    private function testValue(ColumnDefinition $column): string
    {
        return match ($column->columnType) {
            'string' => "'{$column->name}_test'",
            'text', 'mediumText', 'longText' => "'Test {$column->name} content'",
            'integer', 'bigInteger', 'unsignedBigInteger', 'unsignedInteger' => '1',
            'smallInteger', 'unsignedSmallInteger' => '1',
            'tinyInteger', 'unsignedTinyInteger' => '1',
            'boolean' => 'true',
            'decimal', 'float', 'double' => '9.99',
            'date' => "'2025-01-15'",
            'timestamp', 'dateTime', 'dateTimeTz' => "'2025-01-15 12:00:00'",
            'time', 'timeTz' => "'12:00:00'",
            'json' => '[]',
            'uuid' => "'".'550e8400-e29b-41d4-a716-446655440000'."'",
            'ulid' => "'".'01ARZ3NDEKTSV4RRFFQ69G5FAV'."'",
            'year' => '2025',
            default => "'test_value'",
        };
    }

    /**
     * Get the foreign key column name for a BelongsTo relationship.
     */
    private function foreignKeyName(RelationshipDefinition $rel): string
    {
        return $rel->foreignColumn ?? Str::snake($rel->name).'_id';
    }
}
