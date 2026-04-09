<?php

namespace SchemaCraft\Generator\Sdk;

/**
 * Value object representing a custom action for SDK generation.
 */
class SdkCustomAction
{
    public function __construct(
        public string $name,
        public string $httpMethod,
    ) {}
}
