<?php

namespace SchemaCraft\Generators\InputTypes;

use Illuminate\Support\Str;
use SchemaCraft\Config\ConfigResolver;
use SchemaCraft\Generators\GeneratorSchemaContext;
use SchemaCraft\Generators\InputDefinition;
use SchemaCraft\Migration\SchemaDiscovery;
use SchemaCraft\Scanner\SchemaScanner;

class SchemaSelectorInputType implements InputType
{
    public function resolve(mixed $rawValue, InputDefinition $definition, array $resolved): mixed
    {
        // Accept a bare schema class string, e.g. from ->value(MyModelSchema::class)
        if (is_string($rawValue) && class_exists($rawValue)) {
            $rawValue = ['class' => $rawValue];
        }

        if (! $rawValue || empty($rawValue['class']) || ! class_exists($rawValue['class'])) {
            return null;
        }

        $table = (new SchemaScanner($rawValue['class']))->scan();

        // Use null (all) when the key is absent — the frontend sends the key only when
        // the user has made an explicit selection via the column/relationship checkboxes.
        $selectedColumns = $definition->selectColumns
            ? (is_array($rawValue) && array_key_exists('selectedColumns', $rawValue) ? $rawValue['selectedColumns'] : null)
            : null;

        $selectedRelationships = $definition->selectRelationships
            ? (is_array($rawValue) && array_key_exists('selectedRelationships', $rawValue) ? $rawValue['selectedRelationships'] : null)
            : null;

        return new GeneratorSchemaContext($table, $selectedColumns, $selectedRelationships);
    }

    public function toFrontend(InputDefinition $definition, array $resolved = []): array
    {
        $directories = ConfigResolver::schemaDirectories();
        $schemaClasses = (new SchemaDiscovery)->discover($directories);

        // Action-style generators opt into modelBackedOnly so only entities (schemas tied to a
        // model) are offered for selection — response-only / transient shapes (e.g. ActionResult)
        // are data, not selectable entities. isEntity() is the canonical, single-source test.
        if ($definition->modelBackedOnly) {
            $schemaClasses = array_values(array_filter(
                $schemaClasses,
                fn ($schemaClass) => GeneratorSchemaContext::isEntity($schemaClass),
            ));
        }

        $schemas = array_map(fn ($schemaClass) => [
            'class' => $schemaClass,
            'modelName' => $this->resolveModelName($schemaClass),
        ], $schemaClasses);

        return [
            'selectColumns' => $definition->selectColumns,
            'selectRelationships' => $definition->selectRelationships,
            'schemas' => $schemas,
        ];
    }

    private function resolveModelName(string $schemaClass): string
    {
        $className = class_basename($schemaClass);

        return Str::beforeLast($className, 'Schema') ?: $className;
    }
}
