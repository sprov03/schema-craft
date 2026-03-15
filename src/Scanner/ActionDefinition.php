<?php

namespace SchemaCraft\Scanner;

/**
 * The scanned definition of a single Action class.
 */
class ActionDefinition
{
    /**
     * @param  ActionParameter[]  $parameters
     */
    public function __construct(
        /** The fully qualified Action class name. */
        public string $actionClass,
        /** The action name (derived from class basename, e.g. 'updateHomeAddress'). */
        public string $name,
        /** The HTTP method (from ActionMeta, e.g. 'put', 'post', 'delete'). */
        public string $httpMethod = 'put',
        /** Human-readable label (from ActionMeta). */
        public ?string $label = null,
        /** Description (from ActionMeta). */
        public ?string $description = null,
        /** The service method name this action maps to. */
        public string $serviceMethod = '',
        /** The schema class this action operates on. */
        public string $schemaClass = '',
        /** The typed property parameters. */
        public array $parameters = [],
        /** Whether the action's service method is static (e.g. create). */
        public bool $isStatic = false,
    ) {}

    /**
     * Get only the nested relationship parameters.
     *
     * @return ActionParameter[]
     */
    public function nestedParameters(): array
    {
        return array_values(array_filter($this->parameters, fn (ActionParameter $p) => $p->isNestedRelationship));
    }

    /**
     * Get only the flat (non-nested) parameters.
     *
     * @return ActionParameter[]
     */
    public function flatParameters(): array
    {
        return array_values(array_filter($this->parameters, fn (ActionParameter $p) => ! $p->isNestedRelationship));
    }
}
