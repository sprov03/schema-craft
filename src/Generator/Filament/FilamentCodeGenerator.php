<?php

namespace SchemaCraft\Generator\Filament;

use Illuminate\Support\Str;
use SchemaCraft\Generator\Api\GeneratedFile;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Scanner\RelationshipDefinition;
use SchemaCraft\Scanner\SchemaScanner;
use SchemaCraft\Scanner\TableDefinition;

/**
 * Generates Filament v5 resource files from a TableDefinition.
 * Mirrors ApiCodeGenerator's architecture: scan schema → build context → render stubs.
 */
class FilamentCodeGenerator
{
    private const SKIP_COLUMNS = ['id', 'created_at', 'updated_at', 'deleted_at'];

    private const TIMESTAMP_COLUMNS = ['created_at', 'updated_at'];

    private const SOFT_DELETE_COLUMNS = ['deleted_at'];

    private FilamentFieldMapper $fieldMapper;

    private FilamentColumnMapper $columnMapper;

    /** @var array<string, string> */
    private array $titleColumnCache = [];

    public function __construct(
        private string $stubsPath,
    ) {
        $this->fieldMapper = new FilamentFieldMapper;
        $this->columnMapper = new FilamentColumnMapper;
    }

    /**
     * Generate all Filament files for the given schema.
     *
     * @return array<string, GeneratedFile>
     */
    public function generate(
        TableDefinition $table,
        string $modelName,
        string $modelNamespace = 'App\\Models',
        string $resourceNamespace = 'App\\Filament\\Resources',
    ): array {
        $context = $this->buildContext($table, $modelName, $modelNamespace, $resourceNamespace);

        $files = [];

        $files['resource'] = new GeneratedFile(
            path: $this->resourcePath($context),
            content: $this->renderResource($context),
        );

        // Page files
        $files['list_page'] = new GeneratedFile(
            path: $this->pagePath($context, 'List'.Str::plural($modelName)),
            content: $this->renderListPage($context),
        );

        $files['create_page'] = new GeneratedFile(
            path: $this->pagePath($context, 'Create'.$modelName),
            content: $this->renderCreatePage($context),
        );

        $files['edit_page'] = new GeneratedFile(
            path: $this->pagePath($context, 'Edit'.$modelName),
            content: $this->renderEditPage($context),
        );

        // Relation managers for HasMany and BelongsToMany
        foreach ($table->relationships as $relationship) {
            if (in_array($relationship->type, ['hasMany', 'belongsToMany'])) {
                $rmClass = Str::studly($relationship->name).'RelationManager';
                $files['relation_manager_'.$relationship->name] = new GeneratedFile(
                    path: $this->relationManagerPath($context, $rmClass),
                    content: $this->renderRelationManager($context, $relationship, $rmClass),
                );
            }
        }

        return $files;
    }

    /**
     * Build shared context used across all stub renderings.
     *
     * @return array<string, mixed>
     */
    private function buildContext(
        TableDefinition $table,
        string $modelName,
        string $modelNamespace,
        string $resourceNamespace,
    ): array {
        $modelVariable = lcfirst($modelName);
        $resourceClass = $modelName.'Resource';
        $pluralModelName = Str::plural($modelName);

        $editableColumns = $this->getEditableColumns($table);

        // Separate BelongsTo relationships (for form Select fields)
        $belongsToRelationships = array_filter(
            $table->relationships,
            fn (RelationshipDefinition $r) => $r->type === 'belongsTo',
        );

        // HasMany/BelongsToMany relationships (for relation managers)
        $relationManagerRelationships = array_filter(
            $table->relationships,
            fn (RelationshipDefinition $r) => in_array($r->type, ['hasMany', 'belongsToMany']),
        );

        return [
            'table' => $table,
            'modelName' => $modelName,
            'modelVariable' => $modelVariable,
            'modelFqcn' => $modelNamespace.'\\'.$modelName,
            'modelClass' => $modelName,
            'modelNamespace' => $modelNamespace,
            'pluralModelName' => $pluralModelName,
            'resourceNamespace' => $resourceNamespace,
            'resourceClass' => $resourceClass,
            'resourceFqcn' => $resourceNamespace.'\\'.$resourceClass,
            'pageNamespace' => $resourceNamespace.'\\'.$resourceClass.'\\Pages',
            'relationManagerNamespace' => $resourceNamespace.'\\'.$resourceClass.'\\RelationManagers',
            'editableColumns' => $editableColumns,
            'belongsToRelationships' => $belongsToRelationships,
            'relationManagerRelationships' => $relationManagerRelationships,
        ];
    }

    /**
     * Get columns that should appear in forms (exclude PKs, timestamps, soft deletes).
     *
     * @return ColumnDefinition[]
     */
    private function getEditableColumns(TableDefinition $table): array
    {
        $skipSet = array_flip(self::SKIP_COLUMNS);

        if ($table->hasTimestamps) {
            foreach (self::TIMESTAMP_COLUMNS as $col) {
                $skipSet[$col] = true;
            }
        }

        if ($table->hasSoftDeletes) {
            foreach (self::SOFT_DELETE_COLUMNS as $col) {
                $skipSet[$col] = true;
            }
        }

        $columns = [];
        foreach ($table->columns as $column) {
            if ($column->primary || $column->autoIncrement) {
                continue;
            }

            if (isset($skipSet[$column->name])) {
                continue;
            }

            // Skip FK columns that will be handled as BelongsTo relationships
            if ($this->isBelongsToForeignKey($column, $table)) {
                continue;
            }

            $columns[] = $column;
        }

        return $columns;
    }

    /**
     * Check if a column is a BelongsTo foreign key (handled by relationship Select instead).
     */
    private function isBelongsToForeignKey(ColumnDefinition $column, TableDefinition $table): bool
    {
        foreach ($table->relationships as $relationship) {
            if ($relationship->type !== 'belongsTo') {
                continue;
            }

            $fkColumn = $relationship->foreignColumn ?? ($relationship->name.'_id');

            if ($column->name === $fkColumn) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function renderResource(array $context): string
    {
        $stub = file_get_contents($this->stubsPath.'/filament/resource.stub');

        $formFields = $this->buildFormFields($context);
        $tableColumns = $this->buildTableColumns($context);
        $tableFilters = $this->buildTableFilters($context);
        $relationManagers = $this->buildRelationManagerList($context);
        $formImports = $this->buildFormImports($context);

        return $this->cleanOutput(str_replace(
            [
                '{{ resourceNamespace }}',
                '{{ modelFqcn }}',
                '{{ formImports }}',
                '{{ resourceClass }}',
                '{{ modelClass }}',
                '{{ formFields }}',
                '{{ tableColumns }}',
                '{{ tableFilters }}',
                '{{ relationManagers }}',
                '{{ pluralModelName }}',
                '{{ modelName }}',
            ],
            [
                $context['resourceNamespace'],
                $context['modelFqcn'],
                $formImports,
                $context['resourceClass'],
                $context['modelClass'],
                $formFields,
                $tableColumns,
                $tableFilters,
                $relationManagers,
                $context['pluralModelName'],
                $context['modelName'],
            ],
            $stub,
        ));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function renderListPage(array $context): string
    {
        $stub = file_get_contents($this->stubsPath.'/filament/list-page.stub');

        return $this->cleanOutput(str_replace(
            [
                '{{ pageNamespace }}',
                '{{ resourceFqcn }}',
                '{{ resourceClass }}',
                '{{ pluralModelName }}',
            ],
            [
                $context['pageNamespace'],
                $context['resourceFqcn'],
                $context['resourceClass'],
                $context['pluralModelName'],
            ],
            $stub,
        ));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function renderCreatePage(array $context): string
    {
        $stub = file_get_contents($this->stubsPath.'/filament/create-page.stub');

        return $this->cleanOutput(str_replace(
            [
                '{{ pageNamespace }}',
                '{{ resourceFqcn }}',
                '{{ resourceClass }}',
                '{{ modelName }}',
            ],
            [
                $context['pageNamespace'],
                $context['resourceFqcn'],
                $context['resourceClass'],
                $context['modelName'],
            ],
            $stub,
        ));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function renderEditPage(array $context): string
    {
        $stub = file_get_contents($this->stubsPath.'/filament/edit-page.stub');

        return $this->cleanOutput(str_replace(
            [
                '{{ pageNamespace }}',
                '{{ resourceFqcn }}',
                '{{ resourceClass }}',
                '{{ modelName }}',
            ],
            [
                $context['pageNamespace'],
                $context['resourceFqcn'],
                $context['resourceClass'],
                $context['modelName'],
            ],
            $stub,
        ));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function renderRelationManager(array $context, RelationshipDefinition $relationship, string $rmClass): string
    {
        $stub = file_get_contents($this->stubsPath.'/filament/relation-manager.stub');

        // Try to resolve the related schema to get fields
        $relatedFormFields = '                //';
        $relatedTableColumns = '                //';

        $relatedSchemaClass = $this->resolveRelatedSchemaClass($relationship->relatedModel);
        if ($relatedSchemaClass !== null) {
            $scanner = new SchemaScanner($relatedSchemaClass);
            $relatedTable = $scanner->scan();
            $relatedContext = $this->buildContext(
                $relatedTable,
                class_basename($relationship->relatedModel),
                $this->resolveModelNamespace($relationship->relatedModel),
                $context['resourceNamespace'],
            );
            $relatedFormFields = $this->buildFormFields($relatedContext);
            $relatedTableColumns = $this->buildTableColumns($relatedContext);
        }

        return $this->cleanOutput(str_replace(
            [
                '{{ relationManagerNamespace }}',
                '{{ relationManagerClass }}',
                '{{ relationshipName }}',
                '{{ formFields }}',
                '{{ tableColumns }}',
            ],
            [
                $context['relationManagerNamespace'],
                $rmClass,
                $relationship->name,
                $relatedFormFields,
                $relatedTableColumns,
            ],
            $stub,
        ));
    }

    /**
     * Build form field code strings for all editable columns + BelongsTo relationships.
     *
     * @param  array<string, mixed>  $context
     */
    private function buildFormFields(array $context): string
    {
        $lines = [];

        // BelongsTo relationships as Select fields
        foreach ($context['belongsToRelationships'] as $relationship) {
            $titleColumn = $this->resolveTitleColumn($relationship->relatedModel);
            $lines[] = $this->fieldMapper->mapBelongsTo($relationship, titleColumn: $titleColumn).',';
        }

        // Regular editable columns
        foreach ($context['editableColumns'] as $column) {
            $lines[] = $this->fieldMapper->map($column).',';
        }

        if (empty($lines)) {
            return '                //';
        }

        return implode("\n", $lines);
    }

    /**
     * Build table column code strings for display columns.
     *
     * @param  array<string, mixed>  $context
     */
    private function buildTableColumns(array $context): string
    {
        $lines = [];
        /** @var TableDefinition $table */
        $table = $context['table'];

        // BelongsTo relationship columns
        foreach ($context['belongsToRelationships'] as $relationship) {
            $titleColumn = $this->resolveTitleColumn($relationship->relatedModel);
            $lines[] = $this->columnMapper->mapBelongsTo($relationship, titleColumn: $titleColumn).',';
        }

        // Regular columns (including all non-PK columns)
        foreach ($table->columns as $column) {
            if ($column->primary || $column->autoIncrement) {
                continue;
            }

            // Skip FK columns handled by BelongsTo
            if ($this->isBelongsToForeignKey($column, $table)) {
                continue;
            }

            // Skip timestamps — we add them at the end
            if (in_array($column->name, self::TIMESTAMP_COLUMNS) && $table->hasTimestamps) {
                continue;
            }

            // Skip soft delete column
            if (in_array($column->name, self::SOFT_DELETE_COLUMNS) && $table->hasSoftDeletes) {
                continue;
            }

            // Skip json from table (too noisy)
            if ($column->columnType === 'json') {
                continue;
            }

            $lines[] = $this->columnMapper->map($column).',';
        }

        // Add timestamp columns at the end (hidden by default)
        if ($table->hasTimestamps) {
            $lines[] = $this->columnMapper->mapTimestamp('created_at').',';
            $lines[] = $this->columnMapper->mapTimestamp('updated_at').',';
        }

        if (empty($lines)) {
            return '                //';
        }

        return implode("\n", $lines);
    }

    /**
     * Build table filter code strings.
     *
     * @param  array<string, mixed>  $context
     */
    private function buildTableFilters(array $context): string
    {
        $lines = [];

        /** @var TableDefinition $table */
        $table = $context['table'];

        // Add trashed filter if soft deletes enabled
        if ($table->hasSoftDeletes) {
            $lines[] = '                Tables\\Filters\\TrashedFilter::make(),';
        }

        if (empty($lines)) {
            return '                //';
        }

        return implode("\n", $lines);
    }

    /**
     * Build the relation manager class list for getRelations().
     *
     * @param  array<string, mixed>  $context
     */
    private function buildRelationManagerList(array $context): string
    {
        $lines = [];

        $resourceClass = $context['resourceClass'];

        foreach ($context['relationManagerRelationships'] as $relationship) {
            $rmClass = Str::studly($relationship->name).'RelationManager';
            $lines[] = "            {$resourceClass}\\RelationManagers\\{$rmClass}::class,";
        }

        if (empty($lines)) {
            return '            //';
        }

        return implode("\n", $lines);
    }

    /**
     * Build form-specific imports.
     *
     * @param  array<string, mixed>  $context
     */
    private function buildFormImports(array $context): string
    {
        $imports = ['use Filament\\Forms\\Components;'];

        return implode("\n", $imports);
    }

    /**
     * Resolve a model FQCN to its corresponding schema class.
     */
    private function resolveRelatedSchemaClass(string $modelFqcn): ?string
    {
        $parts = explode('\\', $modelFqcn);
        $modelBaseName = array_pop($parts);
        $namespace = implode('\\', $parts);
        $schemaNamespace = preg_replace('/\\\\Models(\\\\|$)/', '\\Schemas$1', $namespace, 1);
        $schemaClass = $schemaNamespace.'\\'.$modelBaseName.'Schema';

        if (class_exists($schemaClass) && is_subclass_of($schemaClass, \SchemaCraft\Schema::class)) {
            return $schemaClass;
        }

        return null;
    }

    /**
     * Extract the namespace from a FQCN.
     */
    private function resolveModelNamespace(string $modelFqcn): string
    {
        return Str::beforeLast($modelFqcn, '\\');
    }

    /**
     * Resolve the best display/title column for a related model.
     *
     * Priority: name → title → label → first non-PK string column → id
     */
    private function resolveTitleColumn(string $modelFqcn): string
    {
        if (isset($this->titleColumnCache[$modelFqcn])) {
            return $this->titleColumnCache[$modelFqcn];
        }

        $titleColumn = 'name';

        $schemaClass = $this->resolveRelatedSchemaClass($modelFqcn);
        if ($schemaClass !== null) {
            $scanner = new SchemaScanner($schemaClass);
            $relatedTable = $scanner->scan();
            $titleColumn = $this->pickTitleColumn($relatedTable);
        }

        $this->titleColumnCache[$modelFqcn] = $titleColumn;

        return $titleColumn;
    }

    /**
     * Pick the best title column from a TableDefinition using a priority chain.
     */
    private function pickTitleColumn(TableDefinition $table): string
    {
        $columnNameSet = [];
        foreach ($table->columns as $column) {
            $columnNameSet[$column->name] = true;
        }

        foreach (['name', 'title', 'label'] as $candidate) {
            if (isset($columnNameSet[$candidate])) {
                return $candidate;
            }
        }

        // Fall back to first non-PK string column
        foreach ($table->columns as $column) {
            if ($column->primary || $column->autoIncrement) {
                continue;
            }

            if ($column->columnType === 'string') {
                return $column->name;
            }
        }

        return 'id';
    }

    private function resourcePath(array $context): string
    {
        return $this->namespaceToPath($context['resourceNamespace'], $context['resourceClass']);
    }

    private function pagePath(array $context, string $pageClass): string
    {
        return $this->namespaceToPath($context['pageNamespace'], $pageClass);
    }

    private function relationManagerPath(array $context, string $rmClass): string
    {
        return $this->namespaceToPath($context['relationManagerNamespace'], $rmClass);
    }

    private function namespaceToPath(string $namespace, string $className): string
    {
        $relativePath = str_replace('\\', '/', $namespace);

        if (str_starts_with($relativePath, 'App/')) {
            $relativePath = 'app/'.substr($relativePath, 4);
        }

        return $relativePath.'/'.$className.'.php';
    }

    private function cleanOutput(string $content): string
    {
        return preg_replace('/\n{3,}/', "\n\n", $content);
    }
}
