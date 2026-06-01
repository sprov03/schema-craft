<?php

namespace SchemaCraft\Exceptions;

use RuntimeException;

class SdkGenerationException extends RuntimeException
{
    /**
     * @param  string[]  $actions
     */
    public static function missingRoutes(string $schemaClass, string $controllerPath, array $actions): self
    {
        $actionList = empty($actions) ? '(no custom actions discovered)' : implode(', ', $actions);

        return new self(
            "Cannot generate SDK for [{$schemaClass}]: controller exists at [{$controllerPath}] "
            ."but no routes are registered for this schema. Discovered actions: {$actionList}. "
            ."Register the controller's actions in apiRoutes() (or remove the controller if it's "
            ."not part of the public API) before generating the SDK. Generating without real route "
            ."metadata would produce an SDK that calls the wrong HTTP methods."
        );
    }

    public static function unsupportedResourceType(string $type): self
    {
        return new self(
            "Cannot generate SDK type for [{$type}]. Supported: native scalars (int, string, "
            ."bool, float, array), DateTimeInterface subclasses (Carbon, DateTime, ...), "
            ."BackedEnum subclasses, or classes implementing "
            ."SchemaCraft\\Contracts\\GeneratesSdkType (typically via SchemaCraftColumn — extend "
            ."Bitmask (SchemaCraft\\Primitives), AbstractJsonDtoType, or AbstractCollectionType). Add the "
            ."contract to [{$type}] or change the resource property type. Silent fallbacks "
            ."here would ship the wrong type in the SDK without anyone noticing."
        );
    }

    /**
     * Thrown when an SDK field resolves to a bare `array` or `mixed` — a shape the generator
     * can't document down to its properties.
     *
     * Why hard-fail with no opt-out: the SDK contract is that every field is typed to the
     * bottom so consumers always know the exact shape. An untyped `array`/`mixed` silently
     * hides that, so we block generation rather than ship an opaque field. Fix by modelling the
     * value as a typed shape (AbstractJsonDtoType / AbstractCollectionType).
     */
    public static function untypedField(string $context, string $field, string $type): self
    {
        return new self(
            "Field [{$context}::\${$field}] resolves to `{$type}`, which can't be documented down "
            ."to its properties. The SDK requires every field to be typed to the bottom, so an "
            ."untyped `array`/`mixed` cannot ship. Model it as a typed shape — extend "
            ."AbstractJsonDtoType (a typed object) or AbstractCollectionType (a typed list), or "
            ."give the column a cast type that does. There is no opt-out: an untyped field hides "
            ."its real shape from SDK consumers."
        );
    }
}
