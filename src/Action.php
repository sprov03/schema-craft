<?php

namespace SchemaCraft;

use Filament\Actions\Action as FilamentAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use SchemaCraft\Attributes\Actions\ActionMeta;
use SchemaCraft\Config\ConfigResolver;
use SchemaCraft\Scanner\ActionDefinition;
use SchemaCraft\Scanner\ActionParameter;
use SchemaCraft\Scanner\ActionScanner;
use SchemaCraft\Scanner\NestedFieldDefinition;
use SchemaCraft\Scanner\NestedRelationshipParameter;
use SchemaCraft\Scanner\SchemaScanner;

/**
 * Abstract base class for action definitions.
 *
 * Extend this class to define an operation as typed properties (like Schema defines columns).
 * The base class provides default behaviors for data mapping, validation, endpoint
 * registration, and Filament action building — all derived from typed properties.
 *
 * This class is analogous to Schema: it is both a scannable definition and provides
 * runtime functionality (execute, endpoint, filamentAction).
 */
abstract class Action
{
    /** @var class-string<Schema> The schema this action operates on. */
    protected static string $schema;

    /** @var string The service method this action maps to. Defaults to class basename without 'Action' suffix. */
    protected static string $serviceMethod = '';

    /** @var array<class-string<Action>, ActionDefinition> */
    private static array $scanCache = [];

    /** @var \Closure|null Call-site closure to customize auto-generated Filament field components. */
    private ?\Closure $fieldConfigFn = null;

    /**
     * Get the schema class this action operates on.
     *
     * @return class-string<Schema>
     */
    public static function schema(): string
    {
        return static::$schema;
    }

    /**
     * Get the service method name this action maps to.
     */
    public static function serviceMethod(): string
    {
        if (static::$serviceMethod !== '') {
            return static::$serviceMethod;
        }

        $className = class_basename(static::class);
        $baseName = preg_replace('/Action$/', '', $className);

        return lcfirst($baseName);
    }

    /**
     * Customize auto-generated Filament form fields at the call site.
     *
     * The closure receives a FieldProxy. Inside the closure,
     * property access returns the auto-generated Filament component for
     * that field, allowing modification or full replacement:
     *
     *   ->configureFields(function (FieldProxy $fields, ?Model $record = null): void {
     *       // Modify: call Filament methods on the auto-generated component
     *       $fields->account
     *           ->options(fn () => Account::active()->pluck('name', 'id'))
     *           ->default(fn (?Model $record) => $record?->account_id)
     *           ->disabled();
     *
     *       // Conditional configuration based on record
     *       if ($record) {
     *           $fields->status->disabled();
     *       }
     *
     *       // Replace: assign a new Filament component entirely
     *       $fields->name = Textarea::make('name')->rows(3);
     *   })
     *
     * configureFields() runs after all component defaults are set, so any
     * ->default() call here overwrites the auto-generated default.
     */
    public function configureFields(\Closure $fn): static
    {
        $this->fieldConfigFn = $fn;

        return $this;
    }

    /**
     * Get the ActionMeta attribute for this action class.
     */
    public static function meta(): ?ActionMeta
    {
        $ref = new \ReflectionClass(static::class);
        $attrs = $ref->getAttributes(ActionMeta::class);

        return ! empty($attrs) ? $attrs[0]->newInstance() : null;
    }

    /**
     * Get the scanned definition for this action.
     */
    public static function definition(): ActionDefinition
    {
        $class = static::class;

        if (! isset(self::$scanCache[$class])) {
            self::$scanCache[$class] = (new ActionScanner($class))->scan();
        }

        return self::$scanCache[$class];
    }

    /**
     * Get validation rules for this action.
     *
     * Default: derives rules from Schema for the fields matching this action's typed properties.
     * Override to customize.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $definition = static::definition();
        $schemaClass = static::$schema;

        $columnNames = [];
        foreach ($definition->parameters as $param) {
            if ($param->isNestedRelationship) {
                continue; // Nested params get their own rules below
            }

            if ($param->isModel && $param->foreignKeyColumn !== null) {
                $columnNames[] = $param->foreignKeyColumn;
            } else {
                $columnNames[] = $param->columnName ?? $param->name;
            }
        }

        $rules = $schemaClass::updateRules($columnNames)->toArray();

        // Add rules for nested relationship parameters
        foreach ($definition->parameters as $param) {
            if (! $param->isNestedRelationship || $param->nestedRelationship === null) {
                continue;
            }

            $nested = $param->nestedRelationship;
            $prefix = $nested->name;
            $arrayRule = $nested->nullable ? 'sometimes|nullable|array' : 'sometimes|array';

            $rules[$prefix] = $arrayRule;

            $fieldPrefix = $nested->isCollection ? "{$prefix}.*" : $prefix;

            foreach ($nested->fields as $field) {
                $fieldRule = $this->nestedFieldRule($field);
                $rules["{$fieldPrefix}.{$field->name}"] = $fieldRule;
            }
        }

        return $rules;
    }

    /**
     * Build a validation rule string for a nested field.
     */
    private function nestedFieldRule(Scanner\NestedFieldDefinition $field): string
    {
        $parts = [];

        if ($field->nullable) {
            $parts[] = 'nullable';
        } else {
            $parts[] = 'required';
        }

        $parts[] = match ($field->type) {
            'int' => 'integer',
            'float' => 'numeric',
            'bool' => 'boolean',
            'array' => 'array',
            default => 'string',
        };

        return implode('|', $parts);
    }

    /**
     * Map raw request/form data to resolved service parameters.
     *
     * Default: passes scalars through by name, resolves FK model references
     * from IDs using findOrFail/find. Scoping should be handled by global
     * scopes activated via middleware (see ScopeContext pattern in docs).
     * Override to customize.
     *
     * @return array<string, mixed>
     */
    public function mapData(array $data): array
    {
        $definition = static::definition();
        $mapped = [];

        // Pre-scan for morph pairs so we can resolve them after the main loop
        $morphPairs = [];
        foreach ($definition->parameters as $param) {
            if ($param->morphPairName !== null && ! isset($morphPairs[$param->morphPairName])) {
                $morphPairs[$param->morphPairName] = [
                    'propertyName' => $param->name,
                    'nullable' => $param->nullable,
                ];
            }
        }

        foreach ($definition->parameters as $param) {
            // Skip morph params — resolved after the loop
            if ($param->morphPairName !== null) {
                continue;
            }

            if ($param->isNestedRelationship) {
                $nested = $param->nestedRelationship;
                $default = ($nested !== null && $nested->isCollection) ? [] : null;
                $mapped[$param->name] = $data[$param->name] ?? ($param->nullable ? null : $default);
            } elseif ($param->isModel) {
                $fkColumn = $param->foreignKeyColumn ?? $param->name.'_id';
                $value = $data[$fkColumn] ?? null;

                if ($value !== null && $param->modelClass !== null) {
                    $modelClass = $param->modelClass;
                    $mapped[$param->name] = $param->nullable
                        ? $modelClass::query()->find($value)
                        : $modelClass::query()->findOrFail($value);
                } else {
                    $mapped[$param->name] = null;
                }
            } else {
                $columnName = $param->columnName ?? $param->name;
                $mapped[$param->name] = $data[$columnName] ?? $param->default;
            }
        }

        // Resolve morph pairs: need both _type and _id to find the model
        foreach ($morphPairs as $morphName => $info) {
            $typeValue = $data[$morphName.'_type'] ?? null;
            $idValue = $data[$morphName.'_id'] ?? null;

            if ($typeValue !== null && $idValue !== null) {
                $modelClass = Relation::getMorphedModel($typeValue) ?? $typeValue;
                $mapped[$info['propertyName']] = $info['nullable']
                    ? $modelClass::query()->find($idValue)
                    : $modelClass::query()->findOrFail($idValue);
            } else {
                $mapped[$info['propertyName']] = null;
            }
        }

        return $mapped;
    }

    /**
     * Run the action logic.
     *
     * Each Action must implement this method to explicitly call its service method,
     * providing IDE-discoverable linkage between the action and the service.
     *
     * @param  mixed  $record  The model instance (new for create, existing for update/delete)
     * @param  array<string, mixed>  $mapped  Data mapped by mapData()
     */
    abstract public function run(mixed $record, array $mapped): mixed;

    /**
     * Execute the action: map data → call run().
     */
    public function execute(?Model $record, array $data): mixed
    {
        $mapped = $this->mapData($data);

        return $this->run($record, $mapped);
    }

    /**
     * Build a Filament Action with auto-generated modal form from this action's definition.
     *
     * All component defaults are set via Filament's ->default() with closure DI.
     * configureFields() runs last and can override any default — last call wins.
     * No fillForm() is used; Filament evaluates ->default() closures at mount time,
     * resolving $record via DI (works for both page and table row actions).
     *
     * Fill-data precedence (lowest → highest):
     *   1. PHP property defaults on the action class → component ->default()
     *   2. Record data via ->default(fn($record) => ...) closures
     *   3. configureFields() ->default() overrides — always wins
     *
     * Filament handles record injection automatically in all contexts:
     * - Page actions: resolved via getDefaultActionRecord() on the Livewire component
     * - Table row actions: set per-row by resolveTableAction() before mounting
     *
     * @param  \Closure|null  $configureFields  Optional closure to customize fields (receives FieldProxy)
     */
    public function filamentAction(?\Closure $configureFields = null): FilamentAction
    {
        // configureFields can be set via the method parameter or via the chainable method
        if ($configureFields !== null) {
            $this->fieldConfigFn = $configureFields;
        }

        $definition = static::definition();
        $meta = static::meta();
        $actionName = Str::camel($definition->serviceMethod);

        $action = FilamentAction::make($actionName)
            ->label($meta?->label ?? Str::headline($definition->serviceMethod));

        if ($meta?->description) {
            $action->modalDescription($meta->description);
        }

        // Deferred: Filament evaluates this closure at mount time via evaluate().
        // $record is resolved by Filament's DI, making it available to configureFields.
        $schemaCraftAction = $this;
        $action->schema(fn (?Model $record = null) => $schemaCraftAction->buildFilamentSchema($definition, $record));

        // Deferred: $record resolved by Filament's DI at submit time.
        $schemaCraftAction = $this;
        $action->action(fn (array $data, ?Model $record = null) => $schemaCraftAction->execute($record, $data));

        return $action;
    }

    /**
     * Build the Filament form schema from the action definition.
     *
     * Each component gets a ->default() closure that resolves values from the
     * record via Filament's DI at mount time. configureFields() runs last
     * and can override any default.
     *
     * @return array<\Filament\Forms\Components\Component|\Filament\Schemas\Components\Component>
     */
    protected function buildFilamentSchema(ActionDefinition $definition, ?Model $record = null): array
    {
        $componentMap = [];

        foreach ($definition->parameters as $param) {
            $key = ($param->morphPairName !== null) ? $param->columnName : $param->name;

            if ($param->isNestedRelationship && $param->nestedRelationship !== null) {
                $componentMap[$key] = $this->buildNestedFilamentComponent($param->nestedRelationship);
            } elseif ($param->isModel && $param->modelClass !== null) {
                $componentMap[$key] = $this->buildBelongsToFilamentComponent($param);
            } elseif ($param->morphPairName !== null && $param->isMorphType) {
                $componentMap[$key] = $this->buildMorphTypeFilamentComponent($param);
            } else {
                $componentMap[$key] = $this->buildScalarFilamentComponent($param);
            }
        }

        // Apply call-site field customizations — runs last, so ->default() here wins
        if ($this->fieldConfigFn !== null) {
            $this->applyFieldConfiguration($componentMap, $record);
        }

        // Collect in parameter order
        $components = [];
        foreach ($definition->parameters as $param) {
            $key = ($param->morphPairName !== null) ? $param->columnName : $param->name;
            $components[] = $componentMap[$key];
        }

        return $components;
    }

    /**
     * Apply the configureFields closure using a FieldProxy.
     *
     * The proxy maps action parameter names to their auto-generated
     * Filament components, allowing modification or full replacement.
     *
     * @param  array<string, mixed>  $componentMap
     */
    private function applyFieldConfiguration(array &$componentMap, ?Model $record = null): void
    {
        $proxy = new FieldProxy($componentMap);

        $ref = new \ReflectionFunction($this->fieldConfigFn);
        if ($ref->getNumberOfParameters() >= 2) {
            ($this->fieldConfigFn)($proxy, $record);
        } else {
            ($this->fieldConfigFn)($proxy);
        }
    }

    /**
     * Build a Filament form component for a scalar parameter.
     *
     * Sets ->default() from PHP property defaults and a record-aware closure.
     */
    protected function buildScalarFilamentComponent(ActionParameter $param): TextInput|Textarea|Checkbox
    {
        $columnName = $param->columnName ?? $param->name;

        if ($param->type === 'bool') {
            $field = Checkbox::make($columnName)
                ->label(Str::headline($param->name));

            $field->default($this->buildScalarDefaultClosure($param));

            return $field;
        }

        // Use Textarea for text, mediumText, and longText column types
        if (in_array($param->columnType, ['text', 'mediumText', 'longText'])) {
            $field = Textarea::make($columnName)
                ->label(Str::headline($param->name));

            if (! $param->nullable && ! $param->hasDefault) {
                $field->required();
            }

            $field->default($this->buildScalarDefaultClosure($param));

            return $field;
        }

        $field = TextInput::make($columnName)
            ->label(Str::headline($param->name));

        if (in_array($param->type, ['int', 'float'])) {
            $field->numeric();
        }

        if (! $param->nullable && ! $param->hasDefault) {
            $field->required();
        }

        $field->default($this->buildScalarDefaultClosure($param));

        return $field;
    }

    /**
     * Build a default closure for a scalar parameter.
     *
     * Returns the record's column value if available, otherwise the PHP property default.
     */
    private function buildScalarDefaultClosure(ActionParameter $param): \Closure
    {
        $columnName = $param->columnName ?? $param->name;
        $phpDefault = $param->hasDefault ? $param->default : null;

        return function (?Model $record = null) use ($columnName, $phpDefault) {
            if ($record !== null && $record->exists) {
                $attributes = $record->getAttributes();
                if (array_key_exists($columnName, $attributes)) {
                    return $record->{$columnName};
                }
            }

            return $phpDefault;
        };
    }

    /**
     * Build a Filament Select component for a BelongsTo model parameter.
     *
     * Sets ->default() from the record's FK column value.
     */
    protected function buildBelongsToFilamentComponent(ActionParameter $param): Select
    {
        $fkColumn = $param->foreignKeyColumn ?? $param->name.'_id';
        $modelClass = $param->modelClass;
        $titleColumns = $this->getTitleColumns($modelClass);

        $field = Select::make($fkColumn)
            ->label(Str::headline($param->name))
            ->options(function () use ($modelClass, $titleColumns) {
                if (count($titleColumns) <= 1) {
                    return $modelClass::query()->pluck($titleColumns[0] ?? 'id', 'id');
                }

                // Composite title: concatenate columns with a space
                $concat = implode(", ' ', ", array_map(fn ($c) => "`{$c}`", $titleColumns));

                return $modelClass::query()
                    ->selectRaw("id, CONCAT({$concat}) as _title")
                    ->pluck('_title', 'id');
            })
            ->searchable();

        if (! $param->nullable) {
            $field->required();
        }

        $field->default(function (?Model $record = null) use ($fkColumn) {
            if ($record !== null && $record->exists) {
                return $record->{$fkColumn};
            }

            return null;
        });

        return $field;
    }

    /**
     * Build a Filament Select component for the _type column of a MorphTo pair.
     *
     * Uses Laravel's morph map to provide options. Falls back to a plain TextInput
     * if no morph map is configured.
     */
    protected function buildMorphTypeFilamentComponent(ActionParameter $param): Select|TextInput
    {
        $columnName = $param->columnName;
        $morphMap = Relation::morphMap();

        if (empty($morphMap)) {
            $field = TextInput::make($columnName)
                ->label(Str::headline(str_replace('_', ' ', $columnName)));

            if (! $param->nullable) {
                $field->required();
            }

            return $field;
        }

        $options = array_combine(
            array_keys($morphMap),
            array_map(fn ($c) => class_basename($c), $morphMap),
        );

        $field = Select::make($columnName)
            ->label(Str::headline(str_replace('_', ' ', $columnName)))
            ->options($options)
            ->searchable();

        if (! $param->nullable) {
            $field->required();
        }

        return $field;
    }

    /**
     * Build a Filament component for a nested relationship parameter.
     *
     * Sets ->default() from the record's relationship data.
     */
    protected function buildNestedFilamentComponent(NestedRelationshipParameter $nested): Repeater|Fieldset|Select
    {
        $label = Str::headline($nested->name);

        // BelongsToMany/MorphToMany without pivot fields → multi-select on IDs
        if (in_array($nested->relationshipType, ['belongsToMany', 'morphToMany']) && empty($nested->pivotFields)) {
            $hasIdField = false;
            foreach ($nested->fields as $field) {
                if ($field->name === 'id') {
                    $hasIdField = true;
                    break;
                }
            }

            if ($hasIdField) {
                $titleColumn = $this->guessTitleColumn($nested->relatedModel);
                $relationName = $nested->name;

                $field = Select::make($nested->name)
                    ->label($label)
                    ->multiple()
                    ->options(fn () => ($nested->relatedModel)::query()->pluck($titleColumn, 'id'))
                    ->searchable();

                $field->default(function (?Model $record = null) use ($relationName) {
                    if ($record !== null && $record->exists) {
                        $relation = $record->{$relationName};

                        return $relation?->pluck('id')->toArray() ?? [];
                    }

                    return [];
                });

                return $field;
            }
        }

        // Collection relationships → Repeater
        if ($nested->isCollection) {
            $fields = $nested->fields;
            $pivotFields = $nested->pivotFields;
            $relationName = $nested->name;

            $field = Repeater::make($nested->name)
                ->label($label)
                ->schema($this->buildNestedFieldsSchema($nested))
                ->columns(2)
                ->defaultItems(0);

            $field->default(function (?Model $record = null) use ($relationName, $fields, $pivotFields) {
                if ($record === null || ! $record->exists) {
                    return [];
                }

                $relation = $record->{$relationName};
                if ($relation === null) {
                    return [];
                }

                $items = [];
                foreach ($relation as $related) {
                    $item = [];
                    foreach ($fields as $f) {
                        $item[$f->name] = $related->{$f->name};
                    }
                    foreach ($pivotFields as $pivotField) {
                        $item[$pivotField] = $related->pivot?->{$pivotField};
                    }
                    $items[] = $item;
                }

                return $items;
            });

            return $field;
        }

        // Singular relationships (hasOne, morphOne) → Fieldset
        $fields = $nested->fields;
        $relationName = $nested->name;

        $fieldset = Fieldset::make($label)
            ->statePath($nested->name)
            ->schema($this->buildNestedFieldsSchema($nested));

        // Singular nested relationships set defaults on each child field
        // via the nested field builder, not on the fieldset itself.

        return $fieldset;
    }

    /**
     * Build the field schema for a nested relationship's fields, pivot fields, and sub-nested relationships.
     *
     * @return array<\Filament\Forms\Components\Component|\Filament\Schemas\Components\Component>
     */
    protected function buildNestedFieldsSchema(NestedRelationshipParameter $nested): array
    {
        $components = [];

        foreach ($nested->fields as $field) {
            $components[] = $this->buildNestedFieldComponent($field);
        }

        foreach ($nested->pivotFields as $pivotField) {
            $components[] = TextInput::make($pivotField)
                ->label(Str::headline($pivotField))
                ->numeric();
        }

        foreach ($nested->subNested as $subNested) {
            $components[] = $this->buildNestedFilamentComponent($subNested);
        }

        return $components;
    }

    /**
     * Build a Filament form component for a single nested field.
     */
    protected function buildNestedFieldComponent(NestedFieldDefinition $field): TextInput|Textarea|Checkbox
    {
        if ($field->type === 'bool') {
            return Checkbox::make($field->name)
                ->label(Str::headline($field->name));
        }

        $component = TextInput::make($field->name)
            ->label(Str::headline($field->name));

        if (in_array($field->type, ['int', 'float'])) {
            $component->numeric();
        }

        if (! $field->nullable) {
            $component->required();
        }

        return $component;
    }

    /**
     * Guess the best "title" column for a model's Select options.
     *
     * @param  class-string<Model>  $modelClass
     */
    /**
     * Resolve the title column(s) for a model's Select options.
     *
     * Returns a single column name. For composite #[Title], returns the first column
     * — use getTitleColumns() for the full list when building custom pluck queries.
     *
     * Priority: #[Title] attribute → preferred names → first string column → 'id'.
     *
     * @param  class-string<Model>  $modelClass
     */
    private function guessTitleColumn(string $modelClass): string
    {
        $columns = $this->getTitleColumns($modelClass);

        return $columns[0] ?? 'id';
    }

    /**
     * Get the title column(s) for a model, resolving from schema #[Title] or by guessing.
     *
     * @param  class-string<Model>  $modelClass
     * @return string[]
     */
    private function getTitleColumns(string $modelClass): array
    {
        $guesses = ['name', 'title', 'label', 'email', 'display_name'];
        $stringTypes = ['string', 'char', 'varchar', 'text', 'uuid', 'ulid'];

        try {
            $schema = ActionScanner::resolveRelatedSchemaClass($modelClass);
            if ($schema !== null) {
                $table = (new SchemaScanner($schema))->scan();

                // First: #[Title] attribute on the schema
                if (! empty($table->titleColumns)) {
                    return $table->titleColumns;
                }

                // Second: check preferred column names
                foreach ($guesses as $guess) {
                    foreach ($table->columns as $col) {
                        if ($col->name === $guess) {
                            return [$guess];
                        }
                    }
                }

                // Third: fall back to first string column that isn't a key/type column
                foreach ($table->columns as $col) {
                    if ($col->name === 'id' || str_ends_with($col->name, '_id') || str_ends_with($col->name, '_type')) {
                        continue;
                    }

                    if (in_array($col->castType ?? 'string', ['string']) || in_array($col->columnType, $stringTypes)) {
                        return [$col->name];
                    }
                }
            }
        } catch (\Throwable) {
            // Fall through to default
        }

        return [];
    }

    // ─── Endpoint Registration ────────────────────────────────────────────

    /**
     * Register this action as an API endpoint.
     *
     * Must be called inside a Route group context. Derives HTTP method, path,
     * and response type from the action definition and meta attributes.
     *
     * @param  class-string|null  $resourceClass  The API resource class to wrap the response
     */
    public function endpoint(?string $resourceClass = null): Route
    {
        $definition = static::definition();
        $meta = static::meta();
        $httpMethod = $meta?->method ?? 'post';
        $segment = Str::kebab($definition->serviceMethod);

        $actionClass = static::class;

        return RouteFacade::{$httpMethod}($segment, function (Request $request) use ($actionClass, $definition, $httpMethod, $resourceClass) {
            $action = new $actionClass;

            $request->validate($action->rules());
            $data = $request->only($this->extractFieldNames($definition));

            $record = $this->resolveEndpointRecord($request, $httpMethod);

            $result = $action->execute($record, $data);

            if ($httpMethod === 'delete') {
                return new JsonResponse(null, 204);
            }

            if ($resourceClass !== null && $result instanceof Model) {
                return new $resourceClass($result);
            }

            return new JsonResponse($result);
        })->defaults('_schema_craft_action', $actionClass)
            ->defaults('_schema_craft_schema', static::$schema);
    }

    /**
     * Extract field names from the action definition for request filtering.
     *
     * @return array<string>
     */
    private function extractFieldNames(ActionDefinition $definition): array
    {
        $names = [];

        foreach ($definition->parameters as $param) {
            if ($param->isNestedRelationship && $param->nestedRelationship !== null) {
                $names[] = $param->nestedRelationship->name;
            } elseif ($param->isModel) {
                $names[] = $param->foreignKeyColumn ?? $param->name.'_id';
            } else {
                $names[] = $param->columnName ?? $param->name;
            }
        }

        return $names;
    }

    /**
     * Resolve the model record for an endpoint request.
     */
    private function resolveEndpointRecord(Request $request, string $httpMethod): Model
    {
        $schemaClass = static::$schema;

        $connectionConfig = ConfigResolver::resolveForSchema($schemaClass);

        $modelClass = $connectionConfig->modelNamespace.
            '\\'.class_basename(str_replace('Schema', '', class_basename($schemaClass)));

        if ($httpMethod === 'post') {
            return new $modelClass;
        }

        // For PUT/PATCH/DELETE, resolve from route parameter
        $paramName = Str::snake(class_basename($modelClass));

        return $modelClass::query()->findOrFail($request->route($paramName));
    }

    /**
     * Clear the scan cache. Useful for testing.
     */
    public static function clearScanCache(): void
    {
        self::$scanCache = [];
    }
}
