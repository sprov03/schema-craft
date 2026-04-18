<?php

namespace SchemaCraft;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use SchemaCraft\Attributes\Resources\BelongsTo;
use SchemaCraft\Attributes\Resources\Computed;
use SchemaCraft\Attributes\Resources\HasMany;
use SchemaCraft\Attributes\Resources\HasOne;

/**
 * Base class for typed, schema-aware API resources.
 *
 * Define public properties for scalar fields and use relationship attributes
 * for nested resources. Never write toArray() manually — the base class
 * handles serialization via reflection.
 *
 * Example:
 *   #[ResourceSchema(CampaignSchema::class)]
 *   class CampaignFullResource extends SchemaCraftResource
 *   {
 *       public int $id;
 *       public string $name;
 *       public ?string $status;
 *
 *       #[HasMany(CampaignCostResource::class)]
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

            if ($property->getAttributes(HasMany::class)) {
                $resourceClass = $property->getAttributes(HasMany::class)[0]->newInstance()->resource;
                $result[$name] = $resourceClass::collection($this->whenLoaded($name));
            } elseif ($property->getAttributes(HasOne::class)) {
                $resourceClass = $property->getAttributes(HasOne::class)[0]->newInstance()->resource;
                $result[$name] = new $resourceClass($this->whenLoaded($name));
            } elseif ($property->getAttributes(BelongsTo::class)) {
                $resourceClass = $property->getAttributes(BelongsTo::class)[0]->newInstance()->resource;
                $result[$name] = new $resourceClass($this->whenLoaded($name));
            } else {
                $result[$name] = $this->resource->{$name} ?? null;
            }
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
