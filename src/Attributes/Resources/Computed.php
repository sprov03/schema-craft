<?php

namespace SchemaCraft\Attributes\Resources;

use Attribute;

/**
 * Marks a public method as a computed field included in the resource response.
 * The method name is converted to snake_case for the JSON key.
 *
 * Example:
 *   #[Computed]
 *   public function displayLabel(): string
 *   {
 *       return $this->name . ' (' . $this->status . ')';
 *   }
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Computed {}
