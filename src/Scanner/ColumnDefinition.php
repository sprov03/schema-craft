<?php

namespace SchemaCraft\Scanner;

/**
 * Value object representing a single database column derived from a schema property.
 */
class ColumnDefinition
{
    public function __construct(
        public string $name,
        public string $columnType,
        public bool $nullable = false,
        public mixed $default = null,
        public bool $hasDefault = false,
        public bool $unsigned = false,
        public ?int $length = null,
        public ?int $precision = null,
        public ?int $scale = null,
        public bool $unique = false,
        public bool $index = false,
        public bool $primary = false,
        public bool $autoIncrement = false,
        public ?string $castType = null,
        public array $attributes = [],
        public ?string $renamedFrom = null,
        public ?string $expressionDefault = null,
        public ?string $phpType = null,
        /**
         * True when the column is marked #[Generated] — DB owns the DDL,
         * the column is read-only from the app's perspective. Downstream
         * consumers (migration writer, factory generator, SDK / Filament
         * generators) should skip or read-only this column when true.
         */
        public bool $generated = false,
        /**
         * When the column wraps a single object shape: the DataSchema FQCN. Carried alongside
         * castType (which points at the DataSchema-as-column-type pattern) so the SDK pipeline can reflect
         * the shape without re-parsing the cast directive string. Null for non-DTO columns.
         */
        public ?string $dataSchemaClass = null,
        /**
         * When the column wraps a collection: the item DataSchema FQCN (sourced from the
         * property's #[CollectionOf] attribute). Carried alongside castType (which points at
         * the Collection primitive (SchemaCraft\Primitives\CollectionColumn)) so the SDK pipeline can reflect the item shape.
         * Null for non-collection columns.
         */
        public ?string $collectionItemClass = null,
    ) {}
}
