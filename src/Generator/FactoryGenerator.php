<?php

namespace SchemaCraft\Generator;

use Illuminate\Support\Str;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Scanner\RelationshipDefinition;
use SchemaCraft\Scanner\TableDefinition;

/**
 * Generates Akceli-style static factory classes from a TableDefinition.
 *
 * Produces factories with makeDefault(), createDefault(), and createDefaults() methods
 * rather than Laravel's built-in Factory class pattern.
 */
class FactoryGenerator
{
    private const SKIP_COLUMNS = ['created_at', 'updated_at', 'deleted_at'];

    private FakerMethodMapper $fakerMapper;

    public function __construct(?FakerMethodMapper $fakerMapper = null)
    {
        $this->fakerMapper = $fakerMapper ?? new FakerMethodMapper;
    }

    /**
     * Generate the full factory file content for a model.
     */
    public function generate(
        TableDefinition $table,
        string $modelName,
        string $modelNamespace = 'App\\Models',
        string $factoryNamespace = 'Database\\Factories',
    ): string {
        $factoryClass = $modelName.'Factory';
        $modelVariable = Str::camel($modelName);

        $editableColumns = $this->getEditableColumns($table);
        $belongsToRelationships = $this->getBelongsToRelationships($table);

        $imports = $this->buildImports($modelName, $modelNamespace, $belongsToRelationships);
        $makeDefaultBody = $this->buildMakeDefaultBody($editableColumns, $modelVariable, $modelName);
        $createDefaultBody = $this->buildCreateDefaultBody($belongsToRelationships, $modelVariable);
        $createDefaultsBody = $this->buildCreateDefaultsBody($modelVariable);

        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = "namespace {$factoryNamespace};";
        $lines[] = '';

        foreach ($imports as $import) {
            $lines[] = "use {$import};";
        }

        $lines[] = '';
        $lines[] = "class {$factoryClass}";
        $lines[] = '{';

        // makeDefault method
        $lines[] = '    /**';
        $lines[] = '     * @param  array<string, mixed>  $data';
        $lines[] = '     */';
        $lines[] = "    public static function makeDefault(array \$data = []): {$modelName}";
        $lines[] = '    {';
        $lines[] = '        $faker = app(Faker::class);';
        $lines[] = '';
        $lines[] = "        \${$modelVariable} = new {$modelName};";

        foreach ($makeDefaultBody as $line) {
            $lines[] = $line;
        }

        $lines[] = '';
        $lines[] = "        \${$modelVariable}->forceFill(\$data);";
        $lines[] = '';
        $lines[] = "        return \${$modelVariable};";
        $lines[] = '    }';
        $lines[] = '';

        // createDefault method
        $lines[] = '    /**';
        $lines[] = '     * @param  array<string, mixed>  $data';
        $lines[] = '     */';
        $lines[] = "    public static function createDefault(array \$data = []): {$modelName}";
        $lines[] = '    {';
        $lines[] = "        \${$modelVariable} = self::makeDefault(\$data);";

        foreach ($createDefaultBody as $line) {
            $lines[] = $line;
        }

        $lines[] = '';
        $lines[] = "        \${$modelVariable}->save();";
        $lines[] = '';
        $lines[] = "        return \${$modelVariable};";
        $lines[] = '    }';
        $lines[] = '';

        // createDefaults method
        $lines[] = '    /**';
        $lines[] = '     * @param  array<string, mixed>  $data';
        $lines[] = '     */';
        $lines[] = '    public static function createDefaults(int $number, array $data = []): Collection';
        $lines[] = '    {';

        foreach ($createDefaultsBody as $line) {
            $lines[] = $line;
        }

        $lines[] = '    }';
        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Get columns that should be included in factory generation.
     * Excludes primary keys, auto-increments, timestamps, and soft-delete columns.
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

            // Skip FK columns — they are handled via relationship association
            if ($this->isForeignKeyColumn($column->name, $table)) {
                continue;
            }

            $columns[] = $column;
        }

        return $columns;
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

            $fkName = $rel->foreignColumn ?? Str::snake($rel->name).'_id';

            if ($fkName === $columnName) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get BelongsTo relationships for auto-association in createDefault().
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
     * Build the sorted list of use imports.
     *
     * @param  RelationshipDefinition[]  $belongsToRelationships
     * @return string[]
     */
    private function buildImports(
        string $modelName,
        string $modelNamespace,
        array $belongsToRelationships,
    ): array {
        $imports = [];

        // Model import
        $imports[] = "{$modelNamespace}\\{$modelName}";

        // Related model imports for BelongsTo associations
        $relatedFactories = [];

        foreach ($belongsToRelationships as $rel) {
            $relatedModelClass = class_basename($rel->relatedModel);
            $imports[] = $rel->relatedModel;
            $relatedFactories[] = $relatedModelClass.'Factory';
        }

        // Faker import
        $imports[] = 'Faker\\Generator as Faker';

        // Collection import
        $imports[] = 'Illuminate\\Support\\Collection';

        // Sort imports alphabetically
        sort($imports);

        return $imports;
    }

    /**
     * Build the makeDefault method body lines (column assignments).
     *
     * @param  ColumnDefinition[]  $columns
     * @return string[]
     */
    private function buildMakeDefaultBody(array $columns, string $modelVariable, string $modelName): array
    {
        $lines = [];

        foreach ($columns as $column) {
            $fakerExpression = $this->fakerMapper->map($column);
            $lines[] = "        \${$modelVariable}->{$column->name} = {$fakerExpression};";
        }

        return $lines;
    }

    /**
     * Build the createDefault method body lines (BelongsTo auto-association).
     *
     * @param  RelationshipDefinition[]  $relationships
     * @return string[]
     */
    private function buildCreateDefaultBody(array $relationships, string $modelVariable): array
    {
        if (empty($relationships)) {
            return [];
        }

        $lines = [];
        $lines[] = '';

        foreach ($relationships as $rel) {
            $relatedModelClass = class_basename($rel->relatedModel);
            $factoryClass = $relatedModelClass.'Factory';
            $relationName = $rel->name;

            if ($rel->nullable) {
                $lines[] = "        if (! \${$modelVariable}->{$relationName} && \${$modelVariable}->{$this->foreignKeyName($rel)} !== null) {";
                $lines[] = "            \${$modelVariable}->{$relationName}()->associate({$factoryClass}::createDefault());";
                $lines[] = '        }';
            } else {
                $lines[] = "        if (! \${$modelVariable}->{$relationName}) {";
                $lines[] = "            \${$modelVariable}->{$relationName}()->associate({$factoryClass}::createDefault());";
                $lines[] = '        }';
            }
        }

        return $lines;
    }

    /**
     * Build the createDefaults method body lines.
     *
     * @return string[]
     */
    private function buildCreateDefaultsBody(string $modelVariable): array
    {
        return [
            '        $items = new Collection;',
            '',
            '        for ($i = 0; $i < $number; $i++) {',
            '            $items->push(self::createDefault($data));',
            '        }',
            '',
            '        return $items;',
        ];
    }

    /**
     * Get the foreign key column name for a BelongsTo relationship.
     */
    private function foreignKeyName(RelationshipDefinition $rel): string
    {
        return $rel->foreignColumn ?? Str::snake($rel->name).'_id';
    }
}
