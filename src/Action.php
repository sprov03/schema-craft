<?php

namespace SchemaCraft;

use Filament\Actions\Action as FilamentAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Illuminate\Database\Eloquent\Model;
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
     * from IDs using the model-resolver pattern (forAuthUser()->findOrFail/find).
     * Override to customize.
     *
     * @return array<string, mixed>
     */
    public function mapData(array $data): array
    {
        $definition = static::definition();
        $mapped = [];

        foreach ($definition->parameters as $param) {
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
                        ? $modelClass::forAuthUser()->find($value)
                        : $modelClass::forAuthUser()->findOrFail($value);
                } else {
                    $mapped[$param->name] = null;
                }
            } else {
                $columnName = $param->columnName ?? $param->name;
                $mapped[$param->name] = $data[$columnName] ?? $param->default;
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
     *
     * @param  \Illuminate\Database\Eloquent\Model  $record
     */
    public function execute($record, array $data): mixed
    {
        $mapped = $this->mapData($data);

        return $this->run($record, $mapped);
    }

    /**
     * Build a Filament Action with auto-generated modal form from this action's definition.
     *
     * Override this method in a specific Action to customize the Filament form.
     *
     * @param  Model  $record  The model instance to operate on
     */
    public function filamentAction(Model $record): FilamentAction
    {
        $definition = static::definition();
        $meta = static::meta();
        $actionName = Str::camel($definition->serviceMethod);

        $action = FilamentAction::make($actionName)
            ->label($meta?->label ?? Str::headline($definition->serviceMethod));

        if ($meta?->description) {
            $action->modalDescription($meta->description);
        }

        $schema = $this->buildFilamentSchema($definition);
        if (! empty($schema)) {
            $action->schema($schema);
            $action->fillForm(fn () => $this->buildFilamentFillData($definition, $record));
        }

        $actionInstance = $this;
        $action->action(function (array $data) use ($actionInstance, $record): void {
            $actionInstance->execute($record, $data);
        });

        return $action;
    }

    /**
     * Build the Filament form schema from the action definition.
     *
     * @return array<\Filament\Forms\Components\Component|\Filament\Schemas\Components\Component>
     */
    protected function buildFilamentSchema(ActionDefinition $definition): array
    {
        $components = [];

        foreach ($definition->parameters as $param) {
            if ($param->isNestedRelationship && $param->nestedRelationship !== null) {
                $components[] = $this->buildNestedFilamentComponent($param->nestedRelationship);
            } elseif ($param->isModel && $param->modelClass !== null) {
                $components[] = $this->buildBelongsToFilamentComponent($param);
            } else {
                $components[] = $this->buildScalarFilamentComponent($param);
            }
        }

        return $components;
    }

    /**
     * Build fill data from the record for the Filament form.
     *
     * @return array<string, mixed>
     */
    protected function buildFilamentFillData(ActionDefinition $definition, Model $record): array
    {
        $data = [];

        foreach ($definition->parameters as $param) {
            if ($param->isNestedRelationship && $param->nestedRelationship !== null) {
                $nested = $param->nestedRelationship;
                $relation = $record->{$nested->name};

                if ($nested->isCollection && $relation !== null) {
                    $items = [];
                    foreach ($relation as $related) {
                        $item = [];
                        foreach ($nested->fields as $field) {
                            $item[$field->name] = $related->{$field->name};
                        }
                        foreach ($nested->pivotFields as $pivotField) {
                            $item[$pivotField] = $related->pivot?->{$pivotField};
                        }
                        $items[] = $item;
                    }
                    $data[$nested->name] = $items;
                } elseif (! $nested->isCollection && $relation !== null) {
                    $item = [];
                    foreach ($nested->fields as $field) {
                        $item[$field->name] = $relation->{$field->name};
                    }
                    $data[$nested->name] = $item;
                }
            } elseif ($param->isModel) {
                $fkColumn = $param->foreignKeyColumn ?? $param->name.'_id';
                $data[$fkColumn] = $record->{$fkColumn};
            } else {
                $columnName = $param->columnName ?? $param->name;
                $data[$columnName] = $record->{$columnName};
            }
        }

        return $data;
    }

    /**
     * Build a Filament form component for a scalar parameter.
     */
    protected function buildScalarFilamentComponent(ActionParameter $param): TextInput|Checkbox
    {
        $columnName = $param->columnName ?? $param->name;

        if ($param->type === 'bool') {
            $field = Checkbox::make($columnName)
                ->label(Str::headline($param->name));

            if ($param->hasDefault) {
                $field->default($param->default);
            }

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

        if ($param->hasDefault) {
            $field->default($param->default);
        }

        return $field;
    }

    /**
     * Build a Filament Select component for a BelongsTo model parameter.
     */
    protected function buildBelongsToFilamentComponent(ActionParameter $param): Select
    {
        $fkColumn = $param->foreignKeyColumn ?? $param->name.'_id';
        $modelClass = $param->modelClass;
        $titleColumn = $this->guessTitleColumn($modelClass);

        $field = Select::make($fkColumn)
            ->label(Str::headline($param->name))
            ->options(fn () => $modelClass::query()->pluck($titleColumn, 'id'))
            ->searchable();

        if (! $param->nullable) {
            $field->required();
        }

        return $field;
    }

    /**
     * Build a Filament component for a nested relationship parameter.
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

                return Select::make($nested->name)
                    ->label($label)
                    ->multiple()
                    ->options(fn () => ($nested->relatedModel)::query()->pluck($titleColumn, 'id'))
                    ->searchable();
            }
        }

        // Collection relationships → Repeater
        if ($nested->isCollection) {
            return Repeater::make($nested->name)
                ->label($label)
                ->schema($this->buildNestedFieldsSchema($nested))
                ->columns(2)
                ->defaultItems(0);
        }

        // Singular relationships (hasOne, morphOne) → Fieldset
        return Fieldset::make($label)
            ->statePath($nested->name)
            ->schema($this->buildNestedFieldsSchema($nested));
    }

    /**
     * Build the field schema for a nested relationship's fields, pivot fields, and sub-nested relationships.
     *
     * @return array<\Filament\Forms\Components\Component|\Filament\Schemas\Components\Component>
     */
    protected function buildNestedFieldsSchema(NestedRelationshipParameter $nested): array
    {
        $schema = [];

        foreach ($nested->fields as $field) {
            $schema[] = $this->buildNestedFieldComponent($field);
        }

        foreach ($nested->pivotFields as $pivotField) {
            $schema[] = TextInput::make($pivotField)
                ->label(Str::headline($pivotField));
        }

        foreach ($nested->nestedRelationships as $subNested) {
            $schema[] = $this->buildNestedFilamentComponent($subNested);
        }

        return $schema;
    }

    /**
     * Build a form component for a single nested field definition.
     */
    protected function buildNestedFieldComponent(NestedFieldDefinition $field): TextInput|Checkbox
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
     * Guess the title/display column for a model class.
     *
     * @param  class-string  $modelClass
     */
    protected function guessTitleColumn(string $modelClass): string
    {
        $candidates = ['name', 'title', 'label', 'email', 'display_name'];

        try {
            $instance = new $modelClass;
            $table = $instance->getTable();
            $columns = \Illuminate\Support\Facades\Schema::getColumnListing($table);

            foreach ($candidates as $candidate) {
                if (in_array($candidate, $columns)) {
                    return $candidate;
                }
            }
        } catch (\Throwable) {
            // Fall through to default
        }

        return 'id';
    }

    /**
     * Register an API route for this action.
     *
     * Registers a route that validates input, maps data, executes the action,
     * and returns the result wrapped in the given Eloquent API Resource.
     * The calling code handles prefix, middleware, and auth wrapping.
     *
     * @param  class-string  $resourceClass  Eloquent API Resource class
     */
    public function endpoint(string $resourceClass): Route
    {
        $definition = static::definition();
        $httpMethod = strtolower($definition->httpMethod);
        $routeSegment = Str::kebab($definition->serviceMethod);

        // Resolve model class from schema
        $schemaClass = static::$schema;
        $modelName = Str::beforeLast(class_basename($schemaClass), 'Schema');
        $schemaScanner = new SchemaScanner($schemaClass);
        $table = $schemaScanner->scan();
        $connectionConfig = ConfigResolver::resolveByDatabaseConnection($table->connection);
        $modelClass = $connectionConfig->modelClass($modelName);

        $actionClass = static::class;
        $routeParam = Str::snake($modelName).'_id';

        if ($httpMethod === 'post') {
            return RouteFacade::post($routeSegment, function (Request $request) use ($actionClass, $resourceClass, $modelClass) {
                $action = new $actionClass;
                $validated = $request->validate($action->rules());
                $result = $action->execute(new $modelClass, $validated);

                return new $resourceClass($result);
            })->defaults('_schema_craft_action', $actionClass)->defaults('_schema_craft_schema', $schemaClass);
        }

        if ($httpMethod === 'delete') {
            return RouteFacade::delete('{'.$routeParam.'}/'.$routeSegment, function (Request $request, int $id) use ($actionClass, $modelClass) {
                $model = $modelClass::forAuthUser()->findOrFail($id);
                $action = new $actionClass;
                $action->execute($model, []);

                return new JsonResponse(null, 204);
            })->defaults('_schema_craft_action', $actionClass)->defaults('_schema_craft_schema', $schemaClass);
        }

        return RouteFacade::match([$httpMethod], '{'.$routeParam.'}/'.$routeSegment, function (Request $request, int $id) use ($actionClass, $resourceClass, $modelClass) {
            $model = $modelClass::forAuthUser()->findOrFail($id);
            $action = new $actionClass;
            $validated = $request->validate($action->rules());
            $result = $action->execute($model, $validated);

            return new $resourceClass($result);
        })->defaults('_schema_craft_action', $actionClass)->defaults('_schema_craft_schema', $schemaClass);
    }

    /**
     * Clear the scan cache. Useful in tests.
     */
    public static function clearScanCache(): void
    {
        self::$scanCache = [];
    }
}
