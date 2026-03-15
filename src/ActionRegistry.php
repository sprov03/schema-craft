<?php

namespace SchemaCraft;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Abstract base class for per-model action registries.
 *
 * Extend this class and declare static methods for each action.
 * Each method returns a new action instance with full IDE autocomplete.
 *
 * Example:
 *   class RecordActions extends ActionRegistry
 *   {
 *       protected static string $schema = RecordSchema::class;
 *
 *       public static function update(): UpdateRecordAction { return new UpdateRecordAction; }
 *       public static function delete(): DeleteRecordAction { return new DeleteRecordAction; }
 *   }
 *
 * Usage:
 *   RecordActions::update()->endpoint(RecordResource::class);
 *   RecordActions::delete()->execute($record, []);
 */
abstract class ActionRegistry
{
    /** @var class-string<Schema> */
    protected static string $schema;

    /**
     * Get the schema class this registry is for.
     *
     * @return class-string<Schema>
     */
    public static function schema(): string
    {
        return static::$schema;
    }

    /**
     * Get all registered action classes by discovering static methods
     * that return Action subclass instances.
     *
     * @return array<string, class-string<Action>>
     */
    public static function actionClasses(): array
    {
        $actions = [];
        $ref = new ReflectionClass(static::class);

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC) as $method) {
            if ($method->getDeclaringClass()->getName() === self::class) {
                continue;
            }

            $returnType = $method->getReturnType();

            if (! $returnType instanceof ReflectionNamedType || $returnType->isBuiltin()) {
                continue;
            }

            $typeName = $returnType->getName();

            if (is_a($typeName, Action::class, true)) {
                $actions[$method->getName()] = $typeName;
            }
        }

        return $actions;
    }
}
