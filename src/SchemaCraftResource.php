<?php

namespace SchemaCraft;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use SchemaCraft\Attributes\CollectionOf;
use SchemaCraft\Attributes\Resources\Computed;

/**
 * Base class for typed, schema-aware API resources.
 *
 * Define public properties for scalar fields and relationship targets. Never write
 * toArray() manually — the base class handles serialization via reflection.
 *
 * Relationship detection:
 *   - Singular: property typed as another SchemaCraftResource subclass. No attribute needed.
 *       public ?CatalogBrandResource $brand;
 *   - Collection: property typed as Collection (or a subclass) + #[CollectionOf(X::class)]
 *     attribute supplying the item type (PHP can't express collection item types at the
 *     property-type level — the attribute fills that one language gap).
 *       #[CollectionOf(CatalogVariantResource::class)]
 *       public Collection $variants;
 *   - Computed: methods marked #[Computed] are added to the response under snake_cased keys.
 *
 * Example:
 *   #[ResourceSchema(CampaignSchema::class)]
 *   class CampaignFullResource extends SchemaCraftResource
 *   {
 *       public int $id;
 *       public string $name;
 *       public ?string $status;
 *
 *       public ?CampaignOwnerResource $owner;
 *
 *       #[CollectionOf(CampaignCostResource::class)]
 *       public Collection $costs;
 *
 *       #[Computed]
 *       public function displayLabel(): string
 *       {
 *           return $this->name . ' (' . $this->status . ')';
 *       }
 *   }
 */
abstract class SchemaCraftResource extends JsonResource
{
    /**
     * Reflection cache keyed by class name.
     *
     * @var array<string, array{properties: ReflectionProperty[], methods: ReflectionMethod[]}>
     */
    private static array $reflectionCache = [];

    public function toArray(Request $request): array
    {
        $reflection = $this->getReflectionData();
        $result = [];

        foreach ($reflection['properties'] as $property) {
            $name = $property->getName();
            $type = $property->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;

            // Singular relationship: property type is itself a SchemaCraftResource subclass.
            // Access the relation directly (not whenLoaded) — the Resource is a contract for
            // what the response will contain, so if the caller forgot to eager-load it, Laravel
            // lazy-loads on access rather than the field silently disappearing from the payload.
            // N+1 risk is the tradeoff; the future fix is letting actions inject a configured
            // query-builder, not gating the contract here.
            if ($typeName !== null
                && class_exists($typeName)
                && is_subclass_of($typeName, self::class)
            ) {
                $result[$name] = new $typeName($this->resource->{$name});

                continue;
            }

            // Collection relationship: property type is a collection container + #[CollectionOf]
            // supplies the item type (the single piece of info PHP's type system can't carry).
            // Same lazy-load-by-default rationale as the singular case above.
            if ($attrs = $property->getAttributes(CollectionOf::class)) {
                $resourceClass = $attrs[0]->newInstance()->resource;
                $result[$name] = $resourceClass::collection($this->resource->{$name});

                continue;
            }

            // Scalar / column property — read off the underlying model.
            $result[$name] = $this->resource->{$name} ?? null;
        }

        foreach ($reflection['methods'] as $method) {
            $result[Str::snake($method->getName())] = $this->{$method->getName()}();
        }

        return $result;
    }

    /**
     * @return array{properties: ReflectionProperty[], methods: ReflectionMethod[]}
     */
    private function getReflectionData(): array
    {
        $class = static::class;

        if (isset(self::$reflectionCache[$class])) {
            return self::$reflectionCache[$class];
        }

        $reflection = new ReflectionClass($this);

        $properties = array_filter(
            $reflection->getProperties(ReflectionProperty::IS_PUBLIC),
            fn (ReflectionProperty $p) => $p->getDeclaringClass()->getName() === $class,
        );

        $methods = array_filter(
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
            fn (ReflectionMethod $m) => ! empty($m->getAttributes(Computed::class))
                && $m->getDeclaringClass()->getName() === $class,
        );

        return self::$reflectionCache[$class] = [
            'properties' => array_values($properties),
            'methods' => array_values($methods),
        ];
    }
}
