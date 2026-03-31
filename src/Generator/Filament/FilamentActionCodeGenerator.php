<?php

namespace SchemaCraft\Generator\Filament;

use Illuminate\Support\Str;
use SchemaCraft\Scanner\ActionDefinition;
use SchemaCraft\Scanner\ActionParameter;
use SchemaCraft\Scanner\NestedFieldDefinition;
use SchemaCraft\Scanner\NestedRelationshipParameter;

/**
 * Generates explicit filamentAction() method code for a given ActionDefinition.
 *
 * Produces a fully spelled-out override that can be pasted into an Action class,
 * with every form component visible and editable — no base class helpers.
 */
class FilamentActionCodeGenerator
{
    /**
     * Generate the full filamentAction() method code as a string.
     */
    public function generate(ActionDefinition $definition): string
    {
        $meta = $this->resolveMeta($definition);
        $actionName = Str::camel($definition->serviceMethod);
        $label = $meta['label'] ?? Str::headline($definition->serviceMethod);

        $lines = [];
        $lines[] = '    public function filamentAction(?Model $record = null): FilamentAction';
        $lines[] = '    {';

        // Build the action chain
        $lines[] = "        return FilamentAction::make('{$actionName}')";
        $lines[] = "            ->label('{$label}')";

        if ($meta['description'] !== null) {
            $escapedDesc = str_replace("'", "\\'", $meta['description']);
            $lines[] = "            ->modalDescription('{$escapedDesc}')";
        }

        // Schema
        $schemaComponents = $this->buildSchemaLines($definition, '                ');
        if (! empty($schemaComponents)) {
            $lines[] = '            ->schema([';
            foreach ($schemaComponents as $componentLines) {
                $lines[] = $componentLines;
            }
            $lines[] = '            ])';
        }

        // Fill form — delegates to the precedence chain (PHP defaults → fromRecord → withDefaults)
        $lines[] = '            ->fillForm(fn () => $this->toFillArray())';

        // Action closure
        $lines[] = '            ->action(function (array $data) use ($record): void {';
        $lines[] = '                $this->execute($record, $data);';
        $lines[] = '            });';

        $lines[] = '    }';

        return implode("\n", $lines);
    }

    /**
     * Generate the required use/import statements for the filamentAction() method.
     *
     * @return string[]
     */
    public function requiredImports(ActionDefinition $definition): array
    {
        $imports = [
            'Filament\\Actions\\Action as FilamentAction',
            'Illuminate\\Database\\Eloquent\\Model',
        ];

        $hasTextInput = false;
        $hasCheckbox = false;
        $hasSelect = false;
        $hasRepeater = false;
        $hasFieldset = false;

        foreach ($definition->parameters as $param) {
            if ($param->isNestedRelationship && $param->nestedRelationship !== null) {
                $this->detectNestedImports($param->nestedRelationship, $hasTextInput, $hasCheckbox, $hasSelect, $hasRepeater, $hasFieldset);
            } elseif ($param->isModel) {
                $hasSelect = true;
            } elseif ($param->type === 'bool') {
                $hasCheckbox = true;
            } else {
                $hasTextInput = true;
            }
        }

        if ($hasTextInput) {
            $imports[] = 'Filament\\Forms\\Components\\TextInput';
        }
        if ($hasCheckbox) {
            $imports[] = 'Filament\\Forms\\Components\\Checkbox';
        }
        if ($hasSelect) {
            $imports[] = 'Filament\\Forms\\Components\\Select';
        }
        if ($hasRepeater) {
            $imports[] = 'Filament\\Forms\\Components\\Repeater';
        }
        if ($hasFieldset) {
            $imports[] = 'Filament\\Schemas\\Components\\Fieldset';
        }

        sort($imports);

        return $imports;
    }

    /**
     * Build schema component lines for all parameters.
     *
     * @return string[]
     */
    private function buildSchemaLines(ActionDefinition $definition, string $indent): array
    {
        $lines = [];

        foreach ($definition->parameters as $param) {
            if ($param->isNestedRelationship && $param->nestedRelationship !== null) {
                $lines = array_merge($lines, $this->buildNestedComponentLines($param->nestedRelationship, $indent));
            } elseif ($param->isModel && $param->modelClass !== null) {
                $lines = array_merge($lines, $this->buildBelongsToLines($param, $indent));
            } else {
                $lines = array_merge($lines, $this->buildScalarLines($param, $indent));
            }
        }

        return $lines;
    }

    /**
     * Build lines for a scalar parameter.
     *
     * @return string[]
     */
    private function buildScalarLines(ActionParameter $param, string $indent): array
    {
        $columnName = $param->columnName ?? $param->name;
        $label = Str::headline($param->name);

        if ($param->type === 'bool') {
            $line = "{$indent}Checkbox::make('{$columnName}')";
            $line .= "\n{$indent}    ->label('{$label}')";
            if ($param->hasDefault) {
                $defaultVal = $param->default ? 'true' : 'false';
                $line .= "\n{$indent}    ->default({$defaultVal})";
            }
            $line .= ',';

            return [$line];
        }

        $line = "{$indent}TextInput::make('{$columnName}')";
        $line .= "\n{$indent}    ->label('{$label}')";

        if (in_array($param->type, ['int', 'float'])) {
            $line .= "\n{$indent}    ->numeric()";
        }

        if (! $param->nullable && ! $param->hasDefault) {
            $line .= "\n{$indent}    ->required()";
        }

        if ($param->hasDefault && $param->default !== null) {
            $line .= "\n{$indent}    ->default(".var_export($param->default, true).')';
        }

        $line .= ',';

        return [$line];
    }

    /**
     * Build lines for a BelongsTo model parameter.
     *
     * @return string[]
     */
    private function buildBelongsToLines(ActionParameter $param, string $indent): array
    {
        $fkColumn = $param->foreignKeyColumn ?? $param->name.'_id';
        $label = Str::headline($param->name);
        $modelBasename = class_basename($param->modelClass);

        $line = "{$indent}Select::make('{$fkColumn}')";
        $line .= "\n{$indent}    ->label('{$label}')";
        $line .= "\n{$indent}    ->options(fn () => {$modelBasename}::query()->pluck('name', 'id'))";
        $line .= "\n{$indent}    ->searchable()";

        if (! $param->nullable) {
            $line .= "\n{$indent}    ->required()";
        }

        $line .= ',';

        return [$line];
    }

    /**
     * Build lines for a nested relationship parameter.
     *
     * @return string[]
     */
    private function buildNestedComponentLines(NestedRelationshipParameter $nested, string $indent): array
    {
        $label = Str::headline($nested->name);
        $lines = [];

        // BelongsToMany/MorphToMany without pivot fields → multi-select
        if (in_array($nested->relationshipType, ['belongsToMany', 'morphToMany']) && empty($nested->pivotFields)) {
            $hasIdField = false;
            foreach ($nested->fields as $field) {
                if ($field->name === 'id') {
                    $hasIdField = true;

                    break;
                }
            }

            if ($hasIdField) {
                $modelBasename = class_basename($nested->relatedModel);
                $line = "{$indent}Select::make('{$nested->name}')";
                $line .= "\n{$indent}    ->label('{$label}')";
                $line .= "\n{$indent}    ->multiple()";
                $line .= "\n{$indent}    ->options(fn () => {$modelBasename}::query()->pluck('name', 'id'))";
                $line .= "\n{$indent}    ->searchable()";
                $line .= ',';
                $lines[] = $line;

                return $lines;
            }
        }

        $nestedIndent = $indent.'    ';
        $fieldLines = $this->buildNestedFieldLines($nested, $nestedIndent);

        // Collection relationships → Repeater
        if ($nested->isCollection) {
            $lines[] = "{$indent}Repeater::make('{$nested->name}')";
            $lines[] = "{$indent}    ->label('{$label}')";
            $lines[] = "{$indent}    ->schema([";
            foreach ($fieldLines as $fl) {
                $lines[] = $fl;
            }
            $lines[] = "{$indent}    ])";
            $lines[] = "{$indent}    ->columns(2)";
            $lines[] = "{$indent}    ->defaultItems(0),";

            return $lines;
        }

        // Singular → Fieldset
        $lines[] = "{$indent}Fieldset::make('{$label}')";
        $lines[] = "{$indent}    ->statePath('{$nested->name}')";
        $lines[] = "{$indent}    ->schema([";
        foreach ($fieldLines as $fl) {
            $lines[] = $fl;
        }
        $lines[] = "{$indent}    ]),";

        return $lines;
    }

    /**
     * Build field lines for a nested relationship's fields, pivot fields, and sub-nested relationships.
     *
     * @return string[]
     */
    private function buildNestedFieldLines(NestedRelationshipParameter $nested, string $indent): array
    {
        $lines = [];

        foreach ($nested->fields as $field) {
            $lines = array_merge($lines, $this->buildNestedFieldComponentLines($field, $indent));
        }

        foreach ($nested->pivotFields as $pivotField) {
            $label = Str::headline($pivotField);
            $lines[] = "{$indent}TextInput::make('{$pivotField}')";
            $lines[] = "{$indent}    ->label('{$label}'),";
        }

        foreach ($nested->nestedRelationships as $subNested) {
            $lines = array_merge($lines, $this->buildNestedComponentLines($subNested, $indent));
        }

        return $lines;
    }

    /**
     * Build a form component line for a single nested field.
     *
     * @return string[]
     */
    private function buildNestedFieldComponentLines(NestedFieldDefinition $field, string $indent): array
    {
        $label = Str::headline($field->name);

        if ($field->type === 'bool') {
            $line = "{$indent}Checkbox::make('{$field->name}')";
            $line .= "\n{$indent}    ->label('{$label}'),";

            return [$line];
        }

        $line = "{$indent}TextInput::make('{$field->name}')";
        $line .= "\n{$indent}    ->label('{$label}')";

        if (in_array($field->type, ['int', 'float'])) {
            $line .= "\n{$indent}    ->numeric()";
        }

        if (! $field->nullable) {
            $line .= "\n{$indent}    ->required()";
        }

        $line .= ',';

        return [$line];
    }

    /**
     * Detect which Filament imports are needed for nested relationships.
     */
    private function detectNestedImports(
        NestedRelationshipParameter $nested,
        bool &$hasTextInput,
        bool &$hasCheckbox,
        bool &$hasSelect,
        bool &$hasRepeater,
        bool &$hasFieldset,
    ): void {
        // BelongsToMany/MorphToMany ID-only → Select
        if (in_array($nested->relationshipType, ['belongsToMany', 'morphToMany']) && empty($nested->pivotFields)) {
            foreach ($nested->fields as $field) {
                if ($field->name === 'id') {
                    $hasSelect = true;

                    return;
                }
            }
        }

        if ($nested->isCollection) {
            $hasRepeater = true;
        } else {
            $hasFieldset = true;
        }

        foreach ($nested->fields as $field) {
            if ($field->type === 'bool') {
                $hasCheckbox = true;
            } else {
                $hasTextInput = true;
            }
        }

        if (! empty($nested->pivotFields)) {
            $hasTextInput = true;
        }

        foreach ($nested->nestedRelationships as $subNested) {
            $this->detectNestedImports($subNested, $hasTextInput, $hasCheckbox, $hasSelect, $hasRepeater, $hasFieldset);
        }
    }

    /**
     * Resolve ActionMeta from a definition.
     *
     * @return array{label: ?string, description: ?string}
     */
    private function resolveMeta(ActionDefinition $definition): array
    {
        return [
            'label' => $definition->label,
            'description' => $definition->description,
        ];
    }
}
