<?php

namespace SchemaCraft\Generators;

use RuntimeException;
use SchemaCraft\Generators\InputTypes\InputType;

/**
 * Registry for generator input type handlers.
 *
 * SchemaCraft's 8 built-in types are registered automatically in the service provider.
 * Projects register their own custom types in their own service provider:
 *
 *     $registry = app(InputTypeRegistry::class);
 *     $registry->register('filamentPlacements', FilamentPlacementsInputType::class);
 *
 * Registered handlers are resolved through the Laravel container, so they support
 * constructor injection.
 */
class InputTypeRegistry
{
    /** @var array<string, class-string<InputType>> */
    private array $types = [];

    public function register(string $name, string $handlerClass): void
    {
        $this->types[$name] = $handlerClass;
    }

    public function has(string $name): bool
    {
        return isset($this->types[$name]);
    }

    public function get(string $name): InputType
    {
        if (! $this->has($name)) {
            throw new RuntimeException(
                "Generator input type '{$name}' is not registered. ".
                'Register it via InputTypeRegistry::register() in your service provider.'
            );
        }

        return app($this->types[$name]);
    }

    /** @return array<string, class-string<InputType>> */
    public function all(): array
    {
        return $this->types;
    }
}
