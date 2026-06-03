<?php

namespace SchemaCraft\Validation;

use BackedEnum;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use SchemaCraft\Attributes\Rules;
use SchemaCraft\Contracts\SchemaCraftType;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Scanner\RelationshipDefinition;

/**
 * Maps ColumnDefinition properties to Laravel validation rule arrays.
 */
class ValidationRuleMapper
{
    /** @var array<string, array<int, mixed>> */
    private array $pendingNestedRules = [];

    /**
     * @param  RelationshipDefinition[]  $relationships
     */
    public function __construct(
        private string $tableName,
        private array $relationships = [],
    ) {}

    /**
     * Generate validation rules for a column in create context.
     *
     * @return array<int, mixed>
     */
    public function createRules(ColumnDefinition $column): array
    {
        return $this->buildRules($column, 'create');
    }

    /**
     * Generate validation rules for a column in update context.
     *
     * @return array<int, mixed>
     */
    public function updateRules(ColumnDefinition $column, string $modelVariable): array
    {
        return $this->buildRules($column, 'update', $modelVariable);
    }

    /**
     * Get nested validation rules from the last column processed.
     *
     * Returns prefixed dot-notation rules for nested types (DTOs).
     *
     * @return array<string, array<int, mixed>>
     */
    public function nestedRules(string $fieldName): array
    {
        $nested = $this->pendingNestedRules;
        $this->pendingNestedRules = [];

        if ($nested === []) {
            return [];
        }

        $prefixed = [];
        foreach ($nested as $key => $keyRules) {
            $prefixed["{$fieldName}.{$key}"] = $keyRules;
        }

        return $prefixed;
    }

    /**
     * @return array<int, mixed>
     */
    private function buildRules(ColumnDefinition $column, string $context, ?string $modelVariable = null): array
    {
        $rules = [];

        // Prefix: required/nullable
        if ($column->nullable) {
            $rules[] = 'nullable';
        } else {
            $rules[] = 'required';
        }

        // DataSchema-typed columns: the DataSchema documents the shape, so the column gets
        // an `array` validation rule plus dot-notation nested rules derived by walking the
        // DataSchema's typed properties. Same shape declaration the SDK pipeline reflects.
        if ($column->dataSchemaClass !== null) {
            $rules[] = 'array';
            $this->pendingNestedRules = $column->dataSchemaClass::validationRules();

            $rulesAttr = $this->getRulesAttribute($column);
            if ($rulesAttr !== null) {
                $rules = array_merge($rules, $rulesAttr->rules);
            }

            return $rules;
        }

        // Collection-of-DataSchema columns: column is an array; each item validates via the
        // item DataSchema's nested rules under the wildcard prefix. (E.g. price_history.*.amount.)
        if ($column->collectionItemClass !== null) {
            $rules[] = 'array';
            $this->pendingNestedRules = $column->collectionItemClass::validationRules('*');

            $rulesAttr = $this->getRulesAttribute($column);
            if ($rulesAttr !== null) {
                $rules = array_merge($rules, $rulesAttr->rules);
            }

            return $rules;
        }

        // SchemaCraftType provides its own validation rules
        if ($column->castType !== null && is_a($column->castType, SchemaCraftType::class, true)) {
            $typeRules = $column->castType::schemaValidationRules();

            if (array_is_list($typeRules)) {
                // Flat rules (bitmask, etc.) — merge into column rules
                $rules = array_merge($rules, $typeRules);
            } else {
                // Nested rules (DTO, etc.) — column is 'array', nested stored separately
                $rules[] = 'array';
                $this->pendingNestedRules = $typeRules;
            }

            // Schema-level #[Rules(...)] additions
            $rulesAttr = $this->getRulesAttribute($column);
            if ($rulesAttr !== null) {
                $rules = array_merge($rules, $rulesAttr->rules);
            }

            return $rules;
        }

        // Type-specific rules
        $typeRules = $this->inferTypeRules($column);
        $rules = array_merge($rules, $typeRules);

        // Unique constraint
        if ($column->unique) {
            $rules[] = $this->buildUniqueRule($column->name, $context, $modelVariable);
        }

        // Enum cast
        if ($column->castType !== null && $this->isEnumClass($column->castType)) {
            $rules[] = Rule::enum($column->castType);
        }

        // Foreign key exists rule
        $existsRule = $this->buildExistsRule($column->name);
        if ($existsRule !== null) {
            $rules[] = $existsRule;
        }

        // Schema-level #[Rules(...)] additions
        $rulesAttr = $this->getRulesAttribute($column);
        if ($rulesAttr !== null) {
            $rules = array_merge($rules, $rulesAttr->rules);
        }

        return $rules;
    }

    /**
     * @return string[]
     */
    private function inferTypeRules(ColumnDefinition $column): array
    {
        $type = $column->columnType;

        // Handle unsigned compound types
        if (str_starts_with($type, 'unsigned')) {
            $rules = ['integer', 'min:0'];

            return $rules;
        }

        return match ($type) {
            'string' => $this->stringRules($column),
            'text', 'mediumText', 'longText' => ['string'],
            'integer', 'bigInteger', 'smallInteger', 'tinyInteger' => ['integer'],
            'boolean' => ['boolean'],
            'decimal', 'float', 'double' => ['numeric'],
            'timestamp', 'dateTime', 'dateTimeTz' => ['date'],
            'date' => ['date'],
            'time', 'timeTz' => ['date_format:H:i:s'],
            'json' => ['array'],
            'uuid' => ['string', 'uuid'],
            'ulid' => ['string', 'ulid'],
            'year' => ['integer', 'digits:4'],
            default => ['string'],
        };
    }

    /**
     * @return string[]
     */
    private function stringRules(ColumnDefinition $column): array
    {
        $maxLength = $column->length ?? 255;

        return ['string', "max:{$maxLength}"];
    }

    private function buildUniqueRule(string $columnName, string $context, ?string $modelVariable): string
    {
        if ($context === 'update' && $modelVariable !== null) {
            return "unique:{$this->tableName},{$columnName},ignore:\$this->route('{$modelVariable}')";
        }

        return "unique:{$this->tableName},{$columnName}";
    }

    private function isEnumClass(string $castType): bool
    {
        return class_exists($castType) && is_subclass_of($castType, BackedEnum::class);
    }

    /**
     * Build an exists rule if this column is a foreign key from a BelongsTo relationship.
     */
    private function buildExistsRule(string $columnName): ?string
    {
        foreach ($this->relationships as $rel) {
            if ($rel->type !== 'belongsTo') {
                continue;
            }

            // Derive the FK column name the same way SchemaScanner does
            $fkName = $rel->foreignColumn ?? Str::snake($rel->name).'_id';

            if ($fkName !== $columnName) {
                continue;
            }

            // Resolve the related table name from the model class
            $relatedTable = $this->resolveTableName($rel->relatedModel);

            return "exists:{$relatedTable},id";
        }

        return null;
    }

    private function resolveTableName(string $modelClass): string
    {
        if (class_exists($modelClass) && method_exists($modelClass, 'getTable')) {
            return (new $modelClass)->getTable();
        }

        // Fallback: derive from class name
        $className = class_basename($modelClass);

        return Str::snake(Str::pluralStudly($className));
    }

    private function getRulesAttribute(ColumnDefinition $column): ?Rules
    {
        foreach ($column->attributes as $attr) {
            if ($attr instanceof Rules) {
                return $attr;
            }
        }

        return null;
    }
}
