<?php

namespace SchemaCraft\Generator\Sdk;

use Illuminate\Support\Str;
use SchemaCraft\Scanner\ActionScanner;
use SchemaCraft\Scanner\ResourceScanner;

/**
 * Turns a raw endpoint from RuntimeRouteScanner into the full representation that both
 * the visualizer's "API Docs" panel and the SDK generator consume.
 *
 * Why this is its own class: the enrichment used to live as private methods on the
 * visualizer's GenerateController, so the CLI SDK command (GenerateSdkCommand) could not
 * reach it and silently fell back to building DTOs from schema columns instead of from the
 * actual Resource class. Both endpoints (GUI and CLI) now share this single source so the
 * SDK can never drift from what the API Docs panel shows. It is intentionally stateless.
 */
class EndpointEnricher
{
    /**
     * Enrich an endpoint from RuntimeRouteScanner with parameter metadata and response field docs.
     *
     * Both action and controller endpoints flow through the same response-resolution path so that
     * the API docs and SDK generator consume an identical data structure regardless of source.
     *
     * Response fields are only populated when a resource class is explicitly declared and scannable.
     * Endpoints with no declared resource, or with a manual JsonResource, show no response shape —
     * we do not infer or fabricate a shape from the schema.
     *
     * @param  array<string, mixed>  $endpoint
     * @return array<string, mixed>
     */
    public function enrich(array $endpoint): array
    {
        $parameters = [];

        // Action endpoints: extract typed parameters and nested relationship info from the action class
        if ($endpoint['source'] === 'action' && $endpoint['actionClass'] !== null && class_exists($endpoint['actionClass'])) {
            try {
                $definition = (new ActionScanner($endpoint['actionClass']))->scan();
                $meta = $endpoint['actionClass']::meta();

                $endpoint['label'] = $meta?->label ?? Str::headline($definition->serviceMethod);
                $endpoint['serviceMethod'] = $definition->serviceMethod;

                $parameters = array_map(fn ($p) => [
                    'name' => $p->name,
                    'type' => $p->type,
                    'nullable' => $p->nullable,
                    'isModel' => $p->isModel,
                    'hasDefault' => $p->hasDefault,
                    'default' => $p->default,
                    'columnName' => $p->columnName,
                    'columnType' => $p->columnType,
                    'foreignKeyColumn' => $p->foreignKeyColumn,
                    'isNestedRelationship' => $p->isNestedRelationship,
                    'morphPairName' => $p->morphPairName,
                    'isMorphType' => $p->isMorphType,
                ], $definition->parameters);

                // Typed request DTO for body-bearing actions: project the action's params into a
                // DTO field set — the SHAPE view of the contract. belongsTo → its FK id (int);
                // hasMany → {Item}Data[]; nested DataSchema/Collection → {X}Data / {Item}Data[].
                // No "relationship" concept survives into the contract; it's all data shapes.
                // Skipped for GET (no body). An Action can't go through the Request reflection path
                // (SdkShape::object) because its model/relationship props would trip guardTyped —
                // the scanned ActionParameter carries the shape interpretation raw reflection lacks.
                $method = strtolower((string) ($endpoint['method'] ?? ''));
                if (in_array($method, ['post', 'put', 'patch'], true)) {
                    [$requestDtoFields, $requestItemClasses] = $this->projectActionRequestDto($definition);
                    if ($requestDtoFields !== []) {
                        $endpoint['requestDtoName'] = class_basename($endpoint['actionClass']).'Data';
                        $endpoint['requestDtoFields'] = $requestDtoFields;
                        $endpoint['requestItemSchemaClasses'] = $requestItemClasses;
                    }
                }
            } catch (\Throwable) {
                // Skip enrichment if scan fails
            }
        }

        // Controller endpoints: build canonical parameters from FormRequest rules so the SDK
        // and API docs consume the same parameter shape regardless of endpoint source
        if ($endpoint['source'] === 'controller' && ! empty($endpoint['rules'])) {
            $parameters = $this->buildParametersFromRules($endpoint['rules']);
        }

        // Response resolution — three possible outcomes:
        //   responseFields        — resource declared and introspectable (SchemaCraftResource)
        //   responseManualResource — resource declared but manual JsonResource (opaque toArray())
        //   responseUndocumented  — controller with generic/missing return type (already set by scanner)
        //   (nothing)             — no resource declared at all
        if (! empty($endpoint['responseResource'])) {
            $rr = $endpoint['responseResource'];
            $resourceClass = $rr['resourceClass'] ?? null;

            if ($resourceClass !== null && class_exists($resourceClass)) {
                $endpoint['resolvedResourceClass'] = $resourceClass;
                $endpoint['responseCollection'] = $rr['collection'] ?? false;

                $fields = $this->buildResponseFieldsFromResource($resourceClass);

                if ($fields !== null) {
                    // responseModelName only belongs to introspectable resources — a manual
                    // resource has no generated DTO, so naming one here would make the SDK
                    // emit XManualData::fromArray() against a class that never exists.
                    $endpoint['responseModelName'] = str_replace('Resource', '', class_basename($resourceClass));
                    $endpoint['responseFields'] = $fields;
                } else {
                    // Resource is declared but uses a manual toArray() — known but not introspectable
                    $endpoint['responseManualResource'] = $resourceClass;
                }
            }

            unset($endpoint['responseResource']);
        }

        // Request side, symmetric to the response block: a type-hinted SchemaCraft\Request
        // documents its own typed input (structure + rules at every level) via its own walk.
        if (! empty($endpoint['requestResource'])) {
            $requestClass = $endpoint['requestResource']['requestClass'] ?? null;

            if ($requestClass !== null && class_exists($requestClass)) {
                $fields = $this->buildRequestFieldsFromRequest($requestClass);

                if ($fields !== null) {
                    $endpoint['resolvedRequestClass'] = $requestClass;
                    $endpoint['requestModelName'] = str_replace('Request', '', class_basename($requestClass));
                    $endpoint['requestFields'] = $fields['fields'];
                    // DTO name off the class basename, NOT requestModelName: the latter strips
                    // "Request", so a request "CreateCatalog" would collide with a schema model's
                    // CreateCatalogData. Basename keeps it CreateCatalogRequestData — collision-proof.
                    $endpoint['requestDtoName'] = class_basename($requestClass).'Data';
                }
            }

            unset($endpoint['requestResource']);
        }

        $endpoint['parameters'] = $parameters;
        // Request-side "relationships" intentionally NOT emitted: it duplicated what `parameters`
        // (filtered by isNestedRelationship) already documents, fed only a cosmetic badge + a
        // mislabeled table, and the Eloquent relationship type is meaningless to an API consumer.
        // The request contract is data shapes only. (Response-side relationships live on the
        // Resource/schema — a separate, load-bearing concept — and are untouched.)
        $endpoint['sourceFiles'] = $this->resolveSourceFiles($endpoint);

        unset($endpoint['controllerClass']);

        return $endpoint;
    }

    /**
     * Resolve the relevant source files for an endpoint.
     *
     * Action routes: Action class + declared Resource class.
     * Controller routes: Controller class + FormRequest + declared Resource class.
     *
     * @return array<int, array{label: string, path: string, content: string}>
     */
    private function resolveSourceFiles(array $endpoint): array
    {
        $files = [];

        if ($endpoint['source'] === 'action') {
            if (! empty($endpoint['actionClass'])) {
                $file = $this->readClassFile($endpoint['actionClass']);
                if ($file !== null) {
                    $files[] = $file;
                }
            }
        } elseif ($endpoint['source'] === 'controller') {
            if (! empty($endpoint['controllerClass'])) {
                $file = $this->readClassFile($endpoint['controllerClass']);
                if ($file !== null) {
                    $files[] = $file;
                }
            }

            if (! empty($endpoint['formRequest'])) {
                $file = $this->readClassFile($endpoint['formRequest']);
                if ($file !== null) {
                    $files[] = $file;
                }
            }
        }

        if (! empty($endpoint['resolvedResourceClass'])) {
            $file = $this->readClassFile($endpoint['resolvedResourceClass']);
            if ($file !== null) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * Read a class file via reflection and return a labelled source entry.
     *
     * `path` is base_path-relative: the frontend reconstructs the absolute path for IDE-open links
     * via window.SCHEMA_CRAFT_BASE_PATH (see ideIconForPath). The backend never emits absolute
     * paths — keeps links machine-correct without per-machine backend work. [[schema-craft-ide-open-links]]
     *
     * @return array{label: string, path: string, content: string}|null
     */
    private function readClassFile(string $class): ?array
    {
        try {
            $filePath = (new \ReflectionClass($class))->getFileName();

            if (! $filePath || ! file_exists($filePath)) {
                return null;
            }

            return [
                'label' => class_basename($class),
                'path' => str_replace(base_path().'/', '', $filePath),
                'content' => file_get_contents($filePath),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Convert FormRequest rules into the canonical parameter shape used by action endpoints.
     *
     * Produces the same array structure as the action parameter map so the SDK generator
     * and API docs can consume controller and action endpoint params identically.
     *
     * @param  array<string, mixed>  $rules
     * @return array<int, array<string, mixed>>
     */
    private function buildParametersFromRules(array $rules): array
    {
        $parameters = [];
        $fieldNames = array_keys($rules);

        foreach ($rules as $field => $fieldRules) {
            // Dot-notation fields (e.g. items.*.id) are sub-fields of a nested param — skip the leaf
            if (str_contains($field, '.')) {
                continue;
            }

            // Normalize the rule list: arrays pass through; pipe-strings get split; a single
            // Rule object (e.g. `'amount' => new CurrencyValidationRule(...)`) becomes a
            // one-element list. The downstream filter drops anything that isn't a string,
            // so Rule objects safely contribute zero rule-name signals to type inference.
            if (is_array($fieldRules)) {
                $ruleList = $fieldRules;
            } elseif (is_string($fieldRules)) {
                $ruleList = explode('|', $fieldRules);
            } else {
                $ruleList = [$fieldRules];
            }
            $ruleStrings = array_filter(array_map(fn ($r) => is_string($r) ? $r : null, $ruleList));

            $nullable = in_array('nullable', $ruleStrings, true);
            $type = $this->inferTypeFromRules(array_values($ruleStrings));

            // A field is a nested relationship when it has sub-rules (e.g. 'items' with 'items.*.id')
            $hasSubRules = collect($fieldNames)->contains(fn ($k) => str_starts_with($k, $field.'.'));

            $parameters[] = [
                'name' => $field,
                'type' => $type,
                'nullable' => $nullable,
                'isModel' => false,
                'hasDefault' => false,
                'default' => null,
                'columnName' => $field,
                'columnType' => $type,
                'foreignKeyColumn' => null,
                'isNestedRelationship' => $hasSubRules,
                'morphPairName' => null,
                'isMorphType' => false,
            ];
        }

        return $parameters;
    }

    /**
     * Infer a PHP type string from a list of Laravel validation rule strings.
     *
     * Returns PHP scalar names ('int'/'bool'/'float'/'array'/'string') to match the
     * vocabulary of SdkResourceGenerator::phpTypeFromString and the reflection-derived
     * action param types — anything else would document as `mixed` in the SDK.
     */
    private function inferTypeFromRules(array $rules): string
    {
        foreach ($rules as $rule) {
            if ($rule === 'integer' || $rule === 'int') {
                return 'int';
            }
            if ($rule === 'boolean' || $rule === 'bool') {
                return 'bool';
            }
            if ($rule === 'numeric' || $rule === 'decimal') {
                return 'float';
            }
            if ($rule === 'array') {
                return 'array';
            }
        }

        return 'string';
    }

    /**
     * Build response field documentation from a SchemaCraftResource class.
     *
     * Reflects on the resource's typed public properties, relationship attributes,
     * and computed methods. Relationships point at related Resources by canonical FQCN
     * (relatedResource); consumers walk a flat map keyed by model name to resolve nested
     * shapes — both SDK gen (via SdkContextBuilder's per-Resource registration) and the
     * visualizer's renderer use this same map identically.
     *
     * Returns null for manual JsonResource subclasses (toArray() is opaque).
     *
     * @param  string[]  $visited  Resource classes already scanned (prevents infinite recursion at scan time)
     * @return array{columns: array, relationships: array}|null
     */
    /**
     * Document a Request's typed input structure + validation rules at every level.
     *
     * The mirror of buildResponseFieldsFromResource(): where a Resource is walked via
     * ResourceScanner, a Request walks ITSELF — it is a DataSchema, so its structure
     * comes from DataSchema::fieldDescriptors() (recursing into nested shapes) and its
     * rules from Request::rules() (which already emits nested, dot-notation rules). No
     * schema is consulted; the request stands on its own primitives.
     *
     * @return array{fields: array<int, array<string, mixed>>}|null
     */
    public function buildRequestFieldsFromRequest(string $requestClass): ?array
    {
        if (! is_subclass_of($requestClass, \SchemaCraft\Request::class)) {
            return null;
        }

        try {
            $rules = (new $requestClass)->rules();

            return ['fields' => $this->walkRequestFields($requestClass, '', $rules)];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Recursively turn a DataSchema's typed properties into documented fields, attaching
     * the matching rule set (by dot-path) at each level.
     *
     * @param  class-string<\SchemaCraft\DataSchema>  $dataSchemaClass
     * @param  array<string, mixed>  $rules
     * @return array<int, array<string, mixed>>
     */
    private function walkRequestFields(string $dataSchemaClass, string $prefix, array $rules): array
    {
        $fields = [];

        foreach ($dataSchemaClass::fieldDescriptors() as $descriptor) {
            $path = $prefix === '' ? $descriptor['name'] : $prefix.'.'.$descriptor['name'];

            $field = [
                'name' => $descriptor['name'],
                'type' => $descriptor['isBuiltin']
                    ? ($descriptor['typeName'] ?? 'mixed')
                    : class_basename($descriptor['typeName'] ?? 'mixed'),
                'nullable' => $descriptor['nullable'],
                'rules' => $rules[$path] ?? [],
            ];

            // Nested shape (DataSchema/JsonColumn property): recurse so the inner
            // structure + its per-field rules are documented as this field's children.
            if ($descriptor['isDataSchema']) {
                $field['children'] = $this->walkRequestFields($descriptor['typeName'], $path, $rules);
            }

            // Collection of shapes (CollectionColumn property): recurse into the item type
            // under the `.*` path so each item field + its rules document as children — the
            // `.*` prefix matches the rule keys the validation cascade emits.
            if ($descriptor['isCollectionColumn'] && $descriptor['collectionItemClass'] !== null) {
                $field['isCollection'] = true;
                $field['children'] = $this->walkRequestFields($descriptor['collectionItemClass'], $path.'.*', $rules);
            }

            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * Project an action's typed params into a request-DTO field set (the SHAPE view of its
     * contract) plus the nested DataSchema classes whose own DTOs must also be emitted.
     * Types are final PHPDoc strings ('int', '{X}Data', '{Item}Data[]') consumed verbatim by
     * SdkDataGenerator::generateFromInnerDto.
     *
     * @return array{0: array<int, array{name: string, type: string, nullable: bool}>, 1: array<int, string>}
     */
    private function projectActionRequestDto(\SchemaCraft\Scanner\ActionDefinition $definition): array
    {
        $fields = [];
        $itemClasses = [];

        foreach ($definition->parameters as $p) {
            // hasMany / nested relationship → the related item shape, as a collection or single.
            if ($p->isNestedRelationship && $p->nestedRelationship !== null) {
                $nr = $p->nestedRelationship;
                if ($nr->dataSchemaClass === null) {
                    continue;
                }
                $itemDto = class_basename($nr->dataSchemaClass).'Data';
                $fields[] = [
                    'name' => $nr->name,
                    'type' => $nr->isCollection ? $itemDto.'[]' : $itemDto,
                    'nullable' => $nr->nullable,
                ];
                $itemClasses[] = $nr->dataSchemaClass;

                continue;
            }

            // belongsTo → its FK id: the contract sends an int, never a model object.
            if ($p->isModel) {
                $fields[] = [
                    'name' => $p->foreignKeyColumn ?? (Str::snake($p->name).'_id'),
                    'type' => 'int',
                    'nullable' => $p->nullable,
                ];

                continue;
            }

            $name = $p->columnName ?? $p->name;
            $type = $p->type;

            if (class_exists($type) && is_subclass_of($type, \SchemaCraft\Primitives\CollectionColumn::class)) {
                $item = $type::itemClass();
                $fields[] = ['name' => $name, 'type' => class_basename($item).'Data[]', 'nullable' => $p->nullable];
                $itemClasses[] = $item;
            } elseif (class_exists($type) && is_subclass_of($type, \SchemaCraft\DataSchema::class)) {
                $fields[] = ['name' => $name, 'type' => class_basename($type).'Data', 'nullable' => $p->nullable];
                $itemClasses[] = $type;
            } elseif (class_exists($type) && is_subclass_of($type, \BackedEnum::class)) {
                $fields[] = [
                    'name' => $name,
                    'type' => (new \ReflectionEnum($type))->getBackingType()?->getName() ?? 'string',
                    'nullable' => $p->nullable,
                ];
            } else {
                $fields[] = [
                    'name' => $name,
                    'type' => match ($type) {
                        'int' => 'int',
                        'float' => 'float',
                        'bool' => 'bool',
                        'string' => 'string',
                        default => 'mixed',
                    },
                    'nullable' => $p->nullable,
                ];
            }
        }

        return [$fields, array_values(array_unique($itemClasses))];
    }

    public function buildResponseFieldsFromResource(string $resourceClass, array $visited = []): ?array
    {
        if (in_array($resourceClass, $visited, true)) {
            return null;
        }

        try {
            $definition = (new ResourceScanner)->scanClass($resourceClass);

            if ($definition->isManual) {
                return null;
            }

            $visited[] = $resourceClass;

            $columns = array_map(function ($p) {
                $col = [
                    'name' => $p['name'],
                    'type' => $p['type'],
                    'nullable' => $p['nullable'] ?? false,
                ];

                // Resolve the column's nested shape. Two introspection paths:
                //   - Property carries collectionItemClass (rare, set by ResourceScanner for
                //     bare-Collection + property-attribute cases). Direct collection shape.
                //   - Framework introspects the property type via SdkShape::forType — recognizes
                //     Bitmask + DataSchema + Collection primitives. Collection primitives
                //     return a collection-kind shape, so isCollection is set from the shape.
                if (! empty($p['collectionItemClass'])) {
                    $shape = \SchemaCraft\Generator\Sdk\SdkShape::collectionOf($p['collectionItemClass']);
                    $col['innerDtoName'] = $shape->dtoName();
                    $col['isCollection'] = true;
                } elseif (is_string($p['type'])) {
                    $shape = \SchemaCraft\Generator\Sdk\SdkShape::forType($p['type']);
                    if ($shape !== null && ! $shape->isScalar() && $shape->dtoName() !== null) {
                        $col['innerDtoName'] = $shape->dtoName();
                        if ($shape->isCollection()) {
                            $col['isCollection'] = true;
                        }
                    }
                }

                // Closed-enumeration primitives — bitmask and native PHP enum — surface their
                // option set so both API docs and the SDK can render/emit it. Two well-known
                // introspection surfaces (Bitmask::definitions() and BackedEnum::cases()), no
                // opt-in interface required. Anything else (structured rich types like
                // AbstractJsonDtoType) is documented via its inner DTO above, not options.
                $options = $this->optionsForType($p['type']);
                if ($options !== null) {
                    $col['options'] = $options;
                }

                return $col;
            }, $definition->properties);

            foreach ($definition->computed as $c) {
                $columns[] = [
                    'name' => Str::snake($c['name']),
                    'type' => $c['returnType'] ?? 'mixed',
                    'nullable' => false,
                    'computed' => true,
                ];
            }

            $relationships = array_map(function ($r) use ($visited) {
                // Shared cardinality rules (SdkRelationshipCardinality) collapse DB-level
                // mechanics (belongsToMany / hasManyThrough / morphMany / etc.) into the
                // Resource-layer's binary "collection vs singular" — single source of truth
                // for what the SDK should emit.
                // The unified shape — both SDK gen (via SdkContextBuilder + SdkDataGenerator) and
                // the visualizer's API tab (via apiRoutes + renderResponseFields) consume identical
                // data. relatedResource carries the canonical Resource FQCN; SdkResourceNaming
                // owns the FQCN → bare-model and FQCN → DTO-name transforms when each consumer
                // needs them. isCollection comes straight from the ResourceScanner — it determined
                // collection vs singular at scan time from the property type (singular) or
                // #[CollectionOf] attribute (collection).
                return [
                    'name' => $r['name'],
                    'type' => $r['type'],
                    'relatedResource' => $r['resource'],
                    'isCollection' => $r['isCollection'],
                ];
            }, $definition->relationships);

            return ['columns' => $columns, 'relationships' => $relationships];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Recursively collect every Resource FQCN reachable from $resourceClass via its
     * #[Resources\BelongsTo / HasMany / HasOne] attributes. Deduped + cycle-safe.
     *
     * Why this is the Resource-walked counterpart to DependencyResolver::resolveDependencies:
     * SDK shape comes from what Resources document, not from the schema-relationship graph.
     * Resources are response documentation; schemas are runtime DB binding. Two walkers,
     * cleanly separated by concern — the schema-walker stays for API/code/query gen, where
     * the relationship graph is the right primitive.
     *
     * @param  array<string, true>  $visited  Accumulator keyed by FQCN (cycle + dedup)
     * @return array<string, true>
     */
    public function collectDepResources(string $resourceClass, array $visited = []): array
    {
        if (isset($visited[$resourceClass]) || ! class_exists($resourceClass)) {
            return $visited;
        }
        $visited[$resourceClass] = true;

        try {
            $definition = (new ResourceScanner)->scanClass($resourceClass);
            foreach ($definition->relationships as $r) {
                $related = $r['resource'] ?? null;
                if ($related !== null) {
                    $visited = $this->collectDepResources($related, $visited);
                }
            }
        } catch (\Throwable) {
            // Resource not scannable — silently skip; primary endpoint resolution already
            // surfaced any blockers at the route-attribution step.
        }

        return $visited;
    }

    /**
     * Extract the closed-enumeration option set for a column type, if any.
     *
     * Two well-known introspection surfaces, no opt-in interface:
     *   - Bitmask subclasses expose their flag set via the primitive's definitions() method.
     *   - Native PHP enums (BackedEnum / UnitEnum) expose their cases via ::cases().
     *
     * For native enums, label() and description() are convention-based — invoked when the
     * enum defines them, fall back to a humanized case name otherwise. Project enums get
     * coverage for free; no contract change required.
     *
     * @return array{kind: string, values: array<int, array{value: mixed, name?: string, label: string, description?: string}>}|null
     */
    private function optionsForType(mixed $type): ?array
    {
        if (! is_string($type) || ! class_exists($type)) {
            return null;
        }

        if (is_subclass_of($type, \SchemaCraft\Primitives\BitmaskColumn::class)) {
            return [
                'kind' => 'bitmask',
                'values' => array_map(
                    fn (array $d) => array_filter([
                        'name' => $d['name'],
                        'value' => $d['value'],
                        'label' => $d['label'],
                        'description' => $d['description'],
                    ], fn ($v) => $v !== null),
                    $type::definitions()
                ),
            ];
        }

        if (is_subclass_of($type, \UnitEnum::class)) {
            return [
                'kind' => 'enum',
                'values' => array_map(function (\UnitEnum $case) {
                    $label = method_exists($case, 'label') ? $case->label() : Str::headline(strtolower($case->name));
                    $description = method_exists($case, 'description') ? $case->description() : null;

                    return array_filter([
                        'name' => $case->name,
                        'value' => $case instanceof \BackedEnum ? $case->value : $case->name,
                        'label' => $label,
                        'description' => $description,
                    ], fn ($v) => $v !== null);
                }, $type::cases()),
            ];
        }

        return null;
    }
}
