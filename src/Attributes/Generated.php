<?php

namespace SchemaCraft\Attributes;

use Attribute;

/**
 * Marks a column as generated at the database level (MySQL `GENERATED ALWAYS AS (...)`,
 * a trigger-populated column, a materialized view column, etc.).
 *
 * Two effects, one marker:
 *   1. The DB owns the DDL — schema-craft will not emit CREATE/ALTER for this column
 *      in migration generation. Whatever mechanism originally created the column
 *      retains ownership.
 *   2. The column is read-only from the application's perspective — should be excluded
 *      from fillable / INSERT / UPDATE flows and rendered read-only in any generated
 *      SDK / Filament resource / API spec.
 *
 * The "how" of generation (the expression, the trigger, the source columns) is
 * intentionally NOT captured in this attribute. That keeps the marker portable
 * across MySQL generated-column syntax, trigger-based population, materialized
 * views, application-service population, and any other mechanism. Document the
 * "how" in a regular comment above the property:
 *
 *     // Concat of first_name + last_name. Generated column at the DB level;
 *     // see migration 2022_03_18_add_full_name_to_contacts.
 *     #[Generated]
 *     public ?string $full_name;
 *
 * Downstream-of-MVP consumers (migration writer, factory generator, SDK generator,
 * Filament generator) should honor the marker when wired. Today (MVP) only the
 * scanner recognizes it — the marker is visible in ColumnDefinition::$generated
 * for any consumer to read.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Generated {}
