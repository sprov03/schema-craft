<?php

namespace SchemaCraft;

/**
 * Proxy object for configuring auto-generated Filament form fields.
 *
 * Used inside the configureFields() closure. Property access returns
 * the Filament component for that action parameter, allowing modification
 * or full replacement:
 *
 *   $fields->account->searchable()->preload();   // modify
 *   $fields->name = Textarea::make('name');       // replace
 *
 * @property mixed $* Dynamic properties mapped to Filament form components
 */
class FieldProxy
{
    /**
     * @param  array<string, mixed>  $componentMap
     */
    public function __construct(
        private array &$componentMap,
    ) {}

    public function __get(string $name): mixed
    {
        if (array_key_exists($name, $this->componentMap)) {
            return $this->componentMap[$name];
        }

        trigger_error('Unknown action field: '.$name, E_USER_NOTICE);

        return null;
    }

    public function __set(string $name, mixed $value): void
    {
        if (array_key_exists($name, $this->componentMap)) {
            $this->componentMap[$name] = $value;

            return;
        }

        trigger_error('Unknown action field: '.$name, E_USER_NOTICE);
    }

    public function __isset(string $name): bool
    {
        return array_key_exists($name, $this->componentMap);
    }
}
