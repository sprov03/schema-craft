<?php

namespace SchemaCraft;

use Illuminate\Contracts\Validation\ValidatesWhenResolved;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Str;
use SchemaCraft\Attributes\Rules;
use SchemaCraft\Scanner\ActionScanner;
use SchemaCraft\Scanner\ColumnDefinition;
use SchemaCraft\Scanner\SchemaScanner;
use SchemaCraft\Validation\ValidationRuleMapper;

/**
 * Standalone, strictly-typed request input contract.
 *
 * A Request is a typed data shape (it extends DataSchema, so it inherits the
 * fromArray/toArray hydration, casting, nullability and autocomplete machinery
 * for free) plus request-specific validation. It stands on its own off the
 * primitives it declares — no schema is required.
 *
 * Rule-building per property (see rules()):
 *   1. base rules inferred from the property's OWN primitive (nullable/required,
 *      type, length, …) via ValidationRuleMapper;
 *   2. if the optional $schema (inherited from DataSchema) is linked, the schema
 *      column's custom #[Rules] are appended — but ONLY for columns present on
 *      BOTH sides, and ONLY the custom rules (never type/length/nullable, which
 *      the request's identical primitive already infers — copying them would
 *      recreate the same rules and invite drift);
 *   3. the property's OWN #[Rules] are applied last and win on conflict.
 * Nullability is ALWAYS the request's own decision; the schema never touches it.
 *
 * Action extends Request: an Action is a Request that additionally maps to a
 * service and runs a mutation. The mutation half (mapData/FK-resolution, run(),
 * Filament, endpoint registration) lives on Action, never here. (Action keeps its
 * own schema-first rules() override for now — see the extraction plan; the two
 * differ by design until they are converged.)
 */
abstract class Request extends DataSchema implements ValidatesWhenResolved
{
    /**
     * Build a validated, hydrated instance from an incoming HTTP request.
     *
     * Validates against rules(), then hydrates the typed properties via the
     * inherited DataSchema::fromArray — so callers read $request->propertyName
     * with full type/autocomplete, not a loose array.
     */
    public static function fromRequest(HttpRequest $request): static
    {
        return static::fromArray((new static)->validateAgainstRules($request));
    }

    /**
     * Container hook (ValidatesWhenResolved). Laravel registers a global
     * afterResolving(ValidatesWhenResolved::class) callback that invokes this the
     * moment ANY implementor is resolved — so a controller can type-hint a Request
     * subclass and receive it already validated + hydrated, exactly the lifecycle a
     * FormRequest gets, WITHOUT this class having to be an HTTP request.
     *
     * The container hands us a freshly-newed (empty) instance, so we validate the
     * current request and populate THIS instance in place.
     */
    public function validateResolved(): void
    {
        $this->hydrateFrom($this->validateAgainstRules(request()));
    }

    /**
     * Validate an incoming request against this Request's rules().
     * Throws Illuminate\Validation\ValidationException (→ 422) on failure.
     *
     * @return array<string, mixed>
     */
    protected function validateAgainstRules(HttpRequest $request): array
    {
        return validator($request->all(), $this->rules())->validate();
    }

    /**
     * Populate THIS instance's typed properties from already-validated data, reusing
     * the inherited DataSchema::fromArray hydration (nested shapes, enums, casts) and
     * copying the result onto $this — used by the in-place container resolver path.
     *
     * @param  array<string, mixed>  $validated
     */
    protected function hydrateFrom(array $validated): void
    {
        $hydrated = static::fromArray($validated);

        foreach ((new \ReflectionObject($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic() || ! $prop->isInitialized($hydrated)) {
                continue;
            }

            $prop->setValue($this, $prop->getValue($hydrated));
        }
    }

    /**
     * Validation rules derived from this request's own typed properties, with
     * optional intersection-only enrichment from a linked schema.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        // Reuse the Action scanner (shared, not a parallel scanner) to read each
        // property's primitive + attributes. Guarded in ActionScanner::scan() so a
        // schema-less Request — which has no serviceMethod() — scans cleanly.
        $definition = (new ActionScanner(static::class))->scan();

        $schemaClass = static::schema();

        if ($schemaClass !== null) {
            $schemaBaseName = Str::beforeLast(class_basename($schemaClass), 'Schema');
            $tableName = $schemaClass::tableName() ?? Str::snake(Str::pluralStudly($schemaBaseName));
            $modelVariable = Str::camel($schemaBaseName);
            $schemaRules = $this->schemaColumnRules($schemaClass);
        } else {
            $tableName = '';
            $modelVariable = Str::camel(class_basename(static::class));
            $schemaRules = [];
        }

        $mapper = new ValidationRuleMapper($tableName, []);
        $rules = [];

        foreach ($definition->parameters as $param) {
            // Nested relationship payloads are an Action concern for now.
            if ($param->isNestedRelationship) {
                continue;
            }

            $fieldKey = $param->isModel
                ? ($param->foreignKeyColumn ?? Str::snake($param->name).'_id')
                : ($param->columnName ?? $param->name);

            // The property's own #[Rules] are applied LAST (request authority), so
            // exclude them from the base mapper call and append them explicitly.
            $ownRules = $this->findRulesAttribute($param->attributes)?->rules ?? [];

            // Nested shape property (typed as a DataSchema / JsonColumn): validate as an
            // array and emit dot-notation rules for its inner fields via DataSchema's own
            // walker. Without this it falls through to scalar handling below and (wrongly)
            // asserts 'string' on the object, rejecting otherwise-valid nested payloads.
            if (! $param->isModel && is_subclass_of($param->type, DataSchema::class)) {
                $nestedRules = [$param->nullable ? 'nullable' : 'required', 'array'];
                foreach ($ownRules as $rule) {
                    if (! in_array($rule, $nestedRules, true)) {
                        $nestedRules[] = $rule;
                    }
                }
                $rules[$fieldKey] = $nestedRules;

                foreach ($param->type::validationRules($fieldKey) as $innerKey => $innerRules) {
                    $rules[$innerKey] = $innerRules;
                }

                continue;
            }

            // Collection-of-shapes property (typed as a CollectionColumn): same treatment as
            // the nested shape above, but the item rules cascade under the `.*` wildcard. A
            // CollectionColumn is not a DataSchema subclass, so it would otherwise fall through
            // to scalar handling and wrongly assert 'string' on the array. Item rules come from
            // the item DataSchema's own walker (DataSchema::validationRules) — one source.
            if (! $param->isModel && is_subclass_of($param->type, \SchemaCraft\Primitives\CollectionColumn::class)) {
                $nestedRules = [$param->nullable ? 'nullable' : 'required', 'array'];
                foreach ($ownRules as $rule) {
                    if (! in_array($rule, $nestedRules, true)) {
                        $nestedRules[] = $rule;
                    }
                }
                $rules[$fieldKey] = $nestedRules;

                foreach ($param->type::itemClass()::validationRules("{$fieldKey}.*") as $innerKey => $innerRules) {
                    $rules[$innerKey] = $innerRules;
                }

                continue;
            }

            $baseAttributes = array_values(array_filter(
                $param->attributes,
                static fn ($attr): bool => ! $attr instanceof Rules,
            ));

            $columnType = $param->columnType ?? $this->phpTypeToColumnType($param->type);

            $synthetic = new ColumnDefinition(
                name: $fieldKey,
                columnType: $columnType,
                nullable: $param->nullable, // nullability is always the request's own
                length: $param->length,
                unsigned: $param->unsigned,
                unique: $param->unique,
                precision: $param->precision,
                scale: $param->scale,
                castType: $param->castType,
                attributes: $baseAttributes,
            );

            $fieldRules = $mapper->updateRules($synthetic, $modelVariable);

            // Intersection-only enrichment: the linked schema column's custom rules.
            foreach ($schemaRules[$fieldKey] ?? [] as $rule) {
                if (! in_array($rule, $fieldRules, true)) {
                    $fieldRules[] = $rule;
                }
            }

            // Own #[Rules] last — request wins on conflict.
            foreach ($ownRules as $rule) {
                if (! in_array($rule, $fieldRules, true)) {
                    $fieldRules[] = $rule;
                }
            }

            $rules[$fieldKey] = $fieldRules;
        }

        return $rules;
    }

    /**
     * Map a linked schema's column name → its custom #[Rules] strings.
     *
     * Only columns that actually declare #[Rules] are returned; everything else
     * about a schema column (type/length/nullable) is intentionally ignored —
     * the request's own primitive already carries it.
     *
     * @return array<string, string[]>
     */
    protected function schemaColumnRules(string $schemaClass): array
    {
        $table = (new SchemaScanner($schemaClass))->scan();

        $out = [];
        foreach ($table->columns as $column) {
            $rulesAttr = $this->findRulesAttribute($column->attributes);
            if ($rulesAttr !== null && $rulesAttr->rules !== []) {
                $out[$column->name] = $rulesAttr->rules;
            }
        }

        return $out;
    }

    /**
     * Find the #[Rules] attribute among a set of raw attribute instances.
     *
     * @param  array<int, object>  $attributes
     */
    protected function findRulesAttribute(array $attributes): ?Rules
    {
        foreach ($attributes as $attr) {
            if ($attr instanceof Rules) {
                return $attr;
            }
        }

        return null;
    }

    /**
     * Map a PHP scalar type name to a column type for rule inference.
     */
    protected function phpTypeToColumnType(string $type): string
    {
        return match ($type) {
            'int' => 'integer',
            'float' => 'float',
            'bool' => 'boolean',
            default => 'string',
        };
    }
}
